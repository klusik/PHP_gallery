# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/gallery_information.py
# Module Type: Gallery metadata presentation
# Purpose: Project validated gallery media metadata onto the desktop field labels.
# Responsibilities: Preserve the shared order and Admin-only location fields.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)

"""Prepare authorized media metadata for the native desktop views."""

from typing import TypeAlias

from .linking import LinkedMediaDetails

InformationRow: TypeAlias = tuple[str, str | int | float | bool | None]
InformationGroup: TypeAlias = tuple[str, list[InformationRow]]

# Purpose: Format exact source byte counts in binary display units.
# Type: int. Units: lower-unit increments. Scope: desktop size labels.
# Consumers: format_gallery_bytes. Rationale: One KiB equals 1,024 bytes.
BYTES_PER_BINARY_UNIT = 1024
# Purpose: Retain recent authorized media-info responses for quick revisit.
# Type: int. Units: photo metadata rows. Scope: one live LinkingSession.
# Consumers: Tk and Qt photo inspectors. Rationale: 32 covers nearby browsing
# without retaining a catalogue.
PHOTOS_INFO_CACHE_ENTRY_LIMIT = 32
# Purpose: Revalidate photo metadata after a short same-session reuse interval.
# Type: float. Units: monotonic seconds. Scope: one photo metadata cache entry.
# Consumers: Tk and Qt photo inspectors. Rationale: One minute matches media
# freshness without retaining changed descriptions, access or location indefinitely.
PHOTOS_INFO_CACHE_FRESH_SECONDS = 60.0


def format_gallery_bytes(value: int) -> str:
    """Show a compact byte count in the reference desktop format.

    Args:
        value (int): Observed byte count, clamped to zero when negative.
    Returns:
        str: Whole bytes or a binary unit with one decimal place.
    """
    size = float(max(0, value))
    for unit in ('B', 'KiB', 'MiB', 'GiB'):
        if size < BYTES_PER_BINARY_UNIT:
            return f'{int(size)} B' if unit == 'B' else f'{size:.1f} {unit}'
        size /= BYTES_PER_BINARY_UNIT
    return f'{size:.1f} TiB'


def thumbnail_quality_caption(fields: LinkedMediaDetails) -> str | None:
    """Describe the configured quality of future generated thumbnails.

    Args:
        fields (LinkedMediaDetails): Validated optional JPEG and WebP settings.
    Returns:
        str | None: Caption for the provided settings, or None when absent.
    """
    qualities = [
        f'{format_name} {fields[key]}'
        for key, format_name in (('jpeg_quality', 'JPEG'), ('webp_quality', 'WebP'))
        if fields.get(key) is not None
    ]
    return ' · '.join(qualities) if qualities else None


def photo_information_groups(
    fields: LinkedMediaDetails, *, include_location: bool,
) -> list[InformationGroup]:
    """Prepare the same photo metadata groups for both desktop renderers.

    Args:
        fields (LinkedMediaDetails): Bounded scalar media details.
        include_location (bool): Current grant authorizes Admin location rows.
    Returns:
        list[InformationGroup]: Ordered localized heading and field keys
            with their optional validated values.
    """
    layout = [
        ('file', (
            'title', 'filename', 'mime_type', 'size', 'width', 'height',
            'display_width', 'display_height', 'exif_orientation',
        )),
        ('description', ('description',)),
        ('dates', ('created_at', 'modified_at', 'exif_taken_at')),
        ('camera', (
            'exif_camera_make', 'exif_camera_model', 'exif_lens_model',
            'exif_focal_length', 'exif_aperture', 'exif_exposure_time', 'exif_iso',
        )),
    ]
    if include_location:
        layout.append(('location', ('gps_lat', 'gps_lng', 'gps_altitude')))
    layout.append(('access', ('visibility', 'nsfw')))
    groups: list[InformationGroup] = [
        (f'photos_workspace_group_{name}', [
            (f'photos_workspace_field_{key}', fields[key])
            for key in keys if fields.get(key) is not None
        ])
        for name, keys in layout
    ]
    derivative = thumbnail_quality_caption(fields)
    if derivative is not None:
        groups[0][1].append(('photos_workspace_field_thumbnail_settings', derivative))
    return groups
