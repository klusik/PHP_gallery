# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/watcher/workers/thumbnails.py
# Module Type: Windows Watcher Worker Support
# Purpose: Generate local thumbnail variants from verified source images.
# Responsibilities:
#   - Transform supported source rasters into upload-ready JPEG/WebP variants.
#   - Snapshot and verify linked sources before thumbnail generation.
#   - Provide importable top-level worker targets for Windows process spawning.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Generate local upload thumbnails from stable source images."""

import hashlib
from pathlib import Path
import shutil
import sys
from typing import List, Optional, TYPE_CHECKING

if TYPE_CHECKING:
    from PIL import Image

from ...http_transport import LocalThumbnail
from ...linked_upload import LINKED_UPLOAD_CHUNK_BYTES, LINKED_UPLOAD_FILE_MAX_BYTES
from .. import runtime
from ..state import THUMBNAIL_SIZES

def image_has_alpha(image: "Image.Image") -> bool:
    """
    Return whether a Pillow image contains transparency that matters for output.

    Args:
        image (Image.Image): Pillow image with a mode string and info mapping;
            palette transparency is read from info["transparency"].

    Returns:
        bool: True when the image has an alpha channel or palette transparency.
    """
    return image.mode in {"RGBA", "LA"} or (image.mode == "P" and "transparency" in image.info)


def prepare_jpeg_image(image: "Image.Image") -> "Image.Image":
    """
    Convert a Pillow image to the RGB canvas expected by JPEG output.

    Transparent pixels are composited over white to match the gallery server's
    JPEG thumbnail behavior.

    Args:
        image (PIL.Image.Image): Pillow image instance.

    Returns:
        PIL.Image.Image: RGB Pillow image suitable for JPEG encoding.
    """
    if image_has_alpha(image):
        rgba = image.convert("RGBA")
        background = runtime.Image.new("RGB", rgba.size, (255, 255, 255))
        background.paste(rgba, mask=rgba.getchannel("A"))
        return background
    return image.convert("RGB")


def prepare_webp_image(image: "Image.Image") -> "Image.Image":
    """
    Convert a Pillow image to a WebP-friendly mode while preserving alpha.

    Args:
        image (PIL.Image.Image): Pillow image instance.

    Returns:
        PIL.Image.Image: Pillow image suitable for WebP encoding.
    """
    if image_has_alpha(image):
        return image.convert("RGBA")
    return image.convert("RGB")


def generate_local_thumbnails(source_path: Path, output_root: Path, client_upload_id: str) -> List[LocalThumbnail]:
    """
    Generate PHP Gallery responsive thumbnail variants on the client computer.

    The size list and naming intent mirror PHP Gallery's thumbnail service:
    300, 600, 800, 960, 1280, and 1600 pixels on the long side, emitted as JPG
    and WebP. The server decides where those files belong after it stores the
    original and resolves the final image record.

    Args:
        source_path (Path): Original image selected for manual upload.
        output_root (Path): Temporary parent directory for generated files.
        client_upload_id (str): Request-local ID shared with the server
            metadata.

    Returns:
        List[LocalThumbnail]: Generated thumbnail descriptors.

    Raises:
        RuntimeError: Raised when Pillow is unavailable.
        OSError: Propagated when files cannot be read or written.
        Exception: Propagated when Pillow cannot decode or encode the image.
    """
    if not runtime.local_thumbnail_supported():
        guidance = (
            "Repair the WinApp installation with the standard setup."
            if getattr(sys, "frozen", False)
            else "Use the source interpreter to install winapp/requirements-core.txt with pip."
        )
        raise RuntimeError(f"Client-side thumbnails require Pillow. {guidance}")

    target_dir = output_root / client_upload_id
    target_dir.mkdir(parents=True, exist_ok=True)
    thumbnails: List[LocalThumbnail] = []

    with runtime.Image.open(source_path) as opened:
        try:
            opened.seek(0)
        except EOFError:
            pass
        source = runtime.ImageOps.exif_transpose(opened)
        stem = source_path.stem.replace('"', "_")

        for size in THUMBNAIL_SIZES:
            resized = source.copy()
            resized.thumbnail((size, size), runtime.Image.Resampling.LANCZOS)

            jpeg_path = target_dir / f"{stem}_thumb{size}.jpg"
            jpeg_image = prepare_jpeg_image(resized)
            jpeg_image.save(jpeg_path, "JPEG", quality=82, optimize=True, progressive=True)
            thumbnails.append(LocalThumbnail(path=jpeg_path, size=size, format="jpg"))

            webp_path = target_dir / f"{stem}_thumb{size}.webp"
            webp_image = prepare_webp_image(resized)
            webp_image.save(webp_path, "WEBP", quality=82, method=6)
            thumbnails.append(LocalThumbnail(path=webp_path, size=size, format="webp"))

    return thumbnails


def generate_linked_thumbnails(
    source_path: Path, output_root: Path, client_upload_id: str, expected_sha256: str,
) -> List[LocalThumbnail]:
    """Generate variants from a size-bounded, hash-verified private snapshot.

    Args:
        source_path (Path): Original source retained by its owner.
        output_root (Path): Owned temporary root for this generation pipeline.
        client_upload_id (str): Unique request-local thumbnail directory.
        expected_sha256 (str): SHA-256 captured before generation begins.

    Returns:
        List[LocalThumbnail]: Variants generated only from verified bytes.

    Raises:
        OSError: The source or private snapshot cannot be safely processed.
        ValueError: The source is too large or changed since hashing.
    """
    snapshot_dir = output_root / client_upload_id / 'source-snapshot'
    snapshot_dir.mkdir(parents=True, exist_ok=False)
    snapshot = snapshot_dir / source_path.name
    digest = hashlib.sha256()
    copied = 0
    try:
        with source_path.open('rb') as source, snapshot.open('xb') as destination:
            while True:
                chunk = source.read(LINKED_UPLOAD_CHUNK_BYTES)
                if not chunk:
                    break
                copied += len(chunk)
                if copied > LINKED_UPLOAD_FILE_MAX_BYTES:
                    raise ValueError('Linked thumbnail source exceeds the upload size limit.')
                digest.update(chunk)
                destination.write(chunk)
        if digest.hexdigest() != expected_sha256:
            raise ValueError('Source changed before linked thumbnail generation.')
        return generate_local_thumbnails(snapshot, output_root, client_upload_id)
    finally:
        shutil.rmtree(snapshot_dir, ignore_errors=True)
