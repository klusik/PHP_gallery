# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/ai/image_analysis.py
# Module Type: AI Image Analysis Support
# Purpose: Adapt local image and vision backends for claimed Gallery AI jobs.
# Responsibilities:
#   - Analyze verified image inputs with configured local vision providers.
#   - Cache optional Transformers pipelines inside the owning process.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Own optional local image analysis used by the AI worker."""

import base64
import importlib
import json
import logging
import math
import subprocess
from pathlib import Path
from typing import Any, Dict, List, Protocol, Tuple, TYPE_CHECKING
from urllib import error, request
import threading

from ..watcher import runtime
from ..models import JsonValue
from ..watcher import state as watcher_state

if TYPE_CHECKING:
    from PIL import Image


class TransformersPipeline(Protocol):
    """Describe the image and task-specific options used by local pipelines."""

    def __call__(
        self,
        image: "Image.Image",
        *,
        max_new_tokens: int | None = None,
        candidate_labels: List[str] | None = None,
        threshold: float | None = None,
    ) -> JsonValue:
        """Run one supported local image task.

        Args:
            image (Image.Image): RGB Pillow image supplied to the task.
            max_new_tokens (int | None): Optional caption generation limit.
            candidate_labels (List[str] | None): Optional labels for detection.
            threshold (float | None): Optional minimum detection confidence.
        Returns:
            JsonValue: JSON-compatible caption or detection pipeline output.
        """
        ...


_AI_TRANSFORMERS_LOCK = threading.Lock()
_AI_TRANSFORMERS_PIPELINES: Dict[Tuple[str, str], TransformersPipeline] = {}

class AIImageAnalyzer:
    """
    Local image-analysis adapter used by the optional AI metadata worker.

    The default implementation is deliberately dependency-light and relies on
    Pillow because the companion app already supports it. Operators who run a
    real local vision model can provide an external command that receives the
    downloaded image path and writes JSON to stdout.
    """

    def __init__(self, config: watcher_state.WatcherConfig) -> None:
        """
        Create an analyzer adapter from the current worker configuration.

        Args:
            config (WatcherConfig): Worker configuration captured at start
                time.

        Returns:
            None: Create an analyzer adapter from the current worker
                configuration.
        """
        self.config = config
        self.external_command = config.ai_external_command.strip()
        self.vision_backend = watcher_state.normalize_ai_vision_backend(config.ai_vision_backend)
        self.transformers_caption_model = config.ai_transformers_caption_model.strip() or watcher_state.AI_TRANSFORMERS_CAPTION_MODEL_DEFAULT
        self.transformers_detector_model = config.ai_transformers_detector_model.strip() or watcher_state.AI_TRANSFORMERS_DETECTOR_MODEL_DEFAULT
        self.transformers_object_labels = config.ai_transformers_object_labels.strip() or watcher_state.AI_TRANSFORMERS_OBJECT_LABELS_DEFAULT
        self.transformers_detection_threshold = max(0.01, min(0.95, float(config.ai_transformers_detection_threshold or watcher_state.AI_TRANSFORMERS_DETECTION_THRESHOLD_DEFAULT)))
        self.ollama_url = config.ai_ollama_url.strip().rstrip("/") or watcher_state.AI_OLLAMA_URL_DEFAULT
        self.ollama_model = config.ai_ollama_model.strip() or watcher_state.AI_OLLAMA_MODEL_DEFAULT
        self.semantic_prompt = config.ai_semantic_prompt.strip() or watcher_state.AI_SEMANTIC_PROMPT_DEFAULT

    def analyze(self, image_path: Path, job: Dict[str, Any]) -> Tuple[Dict[str, Any], str]:
        """
        Analyze one downloaded image asset and return internal metadata.

        Args:
            image_path (Path): Local path to the claimed image asset.
            job (Dict[str, Any]): Job payload returned by the PHP Gallery
                server.

        Returns:
            Tuple[Dict[str, Any], str]: Tuple of metadata dictionary and
                explicit searchable text.

        Raises:
            RuntimeError: Raised when neither the external command nor the
                built-in Pillow analysis can produce metadata.
        """
        if self.vision_backend == "external":
            if not self.external_command:
                raise RuntimeError("The external AI backend is selected, but no external analyzer command is configured.")
            return self.analyze_with_external_command(image_path, job)
        if self.vision_backend == "transformers":
            return self.analyze_with_transformers(image_path, job)
        if self.vision_backend == "ollama":
            return self.analyze_with_ollama(image_path, job)
        if self.vision_backend == "pillow":
            return self.analyze_with_pillow(image_path, job)
        if self.external_command:
            return self.analyze_with_external_command(image_path, job)
        try:
            return self.analyze_with_transformers(image_path, job)
        except Exception as exc:  # noqa: BLE001
            logging.info("Local Transformers backend unavailable, using next AI fallback: %s", exc)
        try:
            return self.analyze_with_ollama(image_path, job)
        except Exception as exc:  # noqa: BLE001
            logging.info("Local Ollama backend unavailable, using Pillow fallback: %s", exc)
        return self.analyze_with_pillow(image_path, job)

    def analyze_with_external_command(self, image_path: Path, job: Dict[str, Any]) -> Tuple[Dict[str, Any], str]:
        """
        Run an operator-provided local analyzer command.

        The command must print a JSON object to stdout. Accepted shapes are:
        {"metadata": {...}, "searchable_text": "..."} or any JSON object, which
        will be stored directly as metadata.

        Args:
            image_path (Path): Local path to the downloaded image.
            job (Dict[str, Any]): Server job payload.

        Returns:
            Tuple[Dict[str, Any], str]: Tuple of metadata dictionary and
                explicit searchable text.

        Raises:
            RuntimeError: Raised when the command fails or returns bad JSON.
        """
        image = job.get("image", {}) if isinstance(job.get("image"), dict) else {}
        command = self.external_command
        # Placeholder replacement is intentionally simple so JSON braces in shell
        # commands are not accidentally parsed as Python format fields.
        command = command.replace("{image_path}", str(image_path))
        command = command.replace("{filename}", str(image.get("filename") or image_path.name))
        command = command.replace("{job_id}", str(job.get("job_id") or ""))
        completed = subprocess.run(
            command,
            shell=True,
            capture_output=True,
            text=True,
            timeout=watcher_state.AI_ANALYZER_TIMEOUT_SECONDS,
            check=False,
        )
        if completed.returncode != 0:
            stderr = (completed.stderr or completed.stdout or "").strip()
            raise RuntimeError(f"External AI analyzer failed with exit code {completed.returncode}: {stderr[:800]}")

        try:
            payload = json.loads(completed.stdout)
        except json.JSONDecodeError as exc:
            raise RuntimeError(f"External AI analyzer returned non-JSON output: {completed.stdout[:800]}") from exc
        if not isinstance(payload, dict):
            raise RuntimeError("External AI analyzer must return a JSON object.")

        metadata = payload.get("metadata") if isinstance(payload.get("metadata"), dict) else payload
        searchable_text = str(payload.get("searchable_text") or "")
        metadata.setdefault("analyzer", "external-command")
        metadata.setdefault("external_command_used", True)
        return metadata, searchable_text

    def analyze_with_transformers(self, image_path: Path, job: Dict[str, Any]) -> Tuple[Dict[str, Any], str]:
        """
        Produce semantic metadata inside this Python process with Transformers.

        This backend does not require a separate local server. It imports the
        optional Hugging Face Transformers and PyTorch packages directly, loads
        the configured models in-process, and analyzes the assigned image on the
        worker PC. First use may download model files into the normal local
        Hugging Face cache for the current Windows user.

        Args:
            image_path (Path): Local path to the downloaded image.
            job (Dict[str, Any]): Server job payload.

        Returns:
            Tuple[Dict[str, Any], str]: Tuple of metadata dictionary and
                searchable text.

        Raises:
            RuntimeError: Raised when optional packages or models are absent.
        """
        if runtime.Image is None or runtime.ImageOps is None:
            raise RuntimeError("Pillow is required before the local Transformers backend can open images.")

        image_info = job.get("image", {}) if isinstance(job.get("image"), dict) else {}
        with runtime.Image.open(image_path) as opened:
            image = runtime.ImageOps.exif_transpose(opened).convert("RGB")
            caption = self.transformers_caption(image)
            detections = self.transformers_object_detections(image)

        objects = self.normalize_label_list([item["label"] for item in detections])
        caption_labels = self.labels_from_caption(caption, self.transformers_candidate_labels())
        labels = sorted(set(objects + caption_labels))
        internal_description = caption.strip().rstrip(".")
        if objects:
            internal_description += "; objects: " + ", ".join(objects[:16])
        if not internal_description:
            internal_description = "semantic image metadata generated locally"

        metadata = {
            "analyzer": "local-transformers-vision-metadata",
            "analyzer_version": "1",
            "backend": "transformers",
            "caption_model": self.transformers_caption_model,
            "detector_model": self.transformers_detector_model,
            "detection_threshold": self.transformers_detection_threshold,
            "internal_description": internal_description,
            "caption": caption,
            "labels": labels,
            "objects": objects,
            "detections": detections[:40],
            "source": {
                "filename": str(image_info.get("filename") or image_path.name),
                "image_id": int(image_info.get("id") or 0),
                "checksum_sha256": str(image_info.get("checksum_sha256") or ""),
            },
        }
        searchable_parts = [
            internal_description,
            caption,
            " ".join(labels),
            " ".join(objects),
            str(image_info.get("filename") or ""),
        ]
        searchable_text = " ".join(part for part in searchable_parts if part).strip()
        return metadata, searchable_text

    def transformers_caption(self, image: "Image.Image") -> str:
        """
        Return one concise caption from the configured in-process caption model.

        Args:
            image (Image.Image): RGB Pillow image supplied to the caption
                pipeline.

        Returns:
            str: Caption text, lower-case and whitespace-normalized.

        Raises:
            RuntimeError: Raised when the model cannot run.
        """
        last_error: Optional[Exception] = None
        for task in ("image-to-text", "image-text-to-text"):
            try:
                pipeline = self.transformers_pipeline(task, self.transformers_caption_model)
                try:
                    result = pipeline(image, max_new_tokens=60)
                except TypeError:
                    result = pipeline(image)
                return self.transformers_caption_text_from_result(result)
            except Exception as exc:  # noqa: BLE001
                last_error = exc
                logging.info("Local Transformers caption task %s unavailable: %s", task, exc)
        raise RuntimeError(f"Local Transformers caption model failed: {last_error}")

    def transformers_caption_text_from_result(self, result: JsonValue) -> str:
        """
        Extract normalized caption text from multiple Transformers result shapes.

        Transformers versions differ in their preferred image captioning task and
        output field names. This helper keeps the analyzer tolerant while keeping
        diagnostic details out of metadata sent to the gallery server.

        Args:
            result (JsonValue): JSON-compatible pipeline output: a dictionary
                with optional generated_text, caption or text fields, a list
                whose first item is inspected, or a scalar. Selected values
                are converted to text; empty/None results produce no caption.

        Returns:
            str: Lower-case, whitespace-normalized caption text.
        """
        if isinstance(result, list) and result:
            first = result[0]
            if isinstance(first, dict):
                text = str(first.get("generated_text") or first.get("caption") or first.get("text") or "")
            else:
                text = str(first)
        elif isinstance(result, dict):
            text = str(result.get("generated_text") or result.get("caption") or result.get("text") or "")
        else:
            text = str(result or "")
        return " ".join(text.lower().split())

    def transformers_object_detections(
        self, image: "Image.Image"
    ) -> List[Dict[str, Any]]:
        """
        Return zero-shot object detections from the configured local model.

        The detector is optional even when the Transformers backend is selected.
        If the detector model fails after a caption has been produced, the
        analyzer keeps the caption result and stores a diagnostic object label
        only in local logs through the raised message when no caption exists.

        Args:
            image (Image.Image): RGB Pillow image supplied to the detector.

        Returns:
            List[Dict[str, Any]]: Detection rows with label (str), score (float)
                and box (dict with integer xmin/ymin/xmax/ymax, or an empty
                dictionary when no bounding box was supplied).
        """
        labels = self.transformers_candidate_labels()
        if not labels:
            return []
        try:
            pipeline = self.transformers_pipeline("zero-shot-object-detection", self.transformers_detector_model)
            result = pipeline(image, candidate_labels=labels, threshold=self.transformers_detection_threshold)
        except TypeError:
            result = pipeline(image, candidate_labels=labels)
        except Exception as exc:  # noqa: BLE001
            logging.info("Local Transformers detector unavailable: %s", exc)
            return []

        detections: List[Dict[str, Any]] = []
        if not isinstance(result, list):
            return detections
        for item in result:
            if not isinstance(item, dict):
                continue
            score = float(item.get("score") or 0.0)
            if score < self.transformers_detection_threshold:
                continue
            label = " ".join(str(item.get("label") or "").lower().split())
            if not label:
                continue
            box = item.get("box") if isinstance(item.get("box"), dict) else {}
            detections.append({
                "label": label,
                "score": round(score, 4),
                "box": {
                    "xmin": int(float(box.get("xmin") or 0)),
                    "ymin": int(float(box.get("ymin") or 0)),
                    "xmax": int(float(box.get("xmax") or 0)),
                    "ymax": int(float(box.get("ymax") or 0)),
                } if box else {},
            })
        detections.sort(key=lambda item: float(item.get("score") or 0.0), reverse=True)
        return detections[:40]

    def transformers_pipeline(self, task: str, model_name: str) -> TransformersPipeline:
        """
        Load and cache one Transformers pipeline inside the current process.

        Args:
            task (str): Transformers pipeline task name.
            model_name (str): Hugging Face model identifier or local model
                path.

        Returns:
            TransformersPipeline: Callable accepting the supported image-task
                inputs and returning JSON-compatible pipeline output.

        Raises:
            RuntimeError: Raised when optional packages are not installed.
        """
        cache_key = (task, model_name)
        with _AI_TRANSFORMERS_LOCK:
            if cache_key in _AI_TRANSFORMERS_PIPELINES:
                return _AI_TRANSFORMERS_PIPELINES[cache_key]
            try:
                transformers = importlib.import_module("transformers")
                importlib.import_module("torch")
            except Exception as exc:  # noqa: BLE001
                raise RuntimeError(
                    "Local Transformers backend needs optional packages. Use the AI tab button "
                    "or run: python -m pip install --user transformers torch torchvision"
                ) from exc
            try:
                pipeline = transformers.pipeline(task, model=model_name)
            except Exception as exc:  # noqa: BLE001
                raise RuntimeError(f"Could not load local Transformers model {model_name!r} for {task}: {exc}") from exc
            _AI_TRANSFORMERS_PIPELINES[cache_key] = pipeline
            return pipeline

    def transformers_candidate_labels(self) -> List[str]:
        """
        Return normalized object labels for local zero-shot detection.

        Returns:
            List[str]: Deduplicated candidate label list.
        """
        return self.normalize_label_list(self.transformers_object_labels)

    def labels_from_caption(self, caption: str, candidates: List[str]) -> List[str]:
        """
        Extract configured search labels explicitly mentioned in a caption.

        Args:
            caption (str): Caption text from the local caption model.
            candidates (List[str]): Candidate labels configured for object
                detection.

        Returns:
            List[str]: Labels found in the caption.
        """
        caption_text = " " + " ".join(caption.lower().replace("-", " ").split()) + " "
        found: List[str] = []
        for label in candidates:
            label_text = " ".join(label.lower().replace("-", " ").split())
            if not label_text:
                continue
            plural = label_text + "s"
            if f" {label_text} " in caption_text or f" {plural} " in caption_text:
                if label_text not in found:
                    found.append(label_text)
        return found[:40]

    def analyze_with_ollama(self, image_path: Path, job: Dict[str, Any]) -> Tuple[Dict[str, Any], str]:
        """
        Produce semantic image metadata with a local Ollama vision model.

        This backend keeps heavy analysis on the Windows machine. It contacts
        only the configured local Ollama endpoint and never sends image bytes to
        PHP Gallery except for the final internal metadata result.

        Args:
            image_path (Path): Local path to the downloaded image.
            job (Dict[str, Any]): Server job payload.

        Returns:
            Tuple[Dict[str, Any], str]: Tuple of metadata dictionary and
                searchable text.

        Raises:
            RuntimeError: Raised when Ollama is unavailable or returns bad
                data.
        """
        image_info = job.get("image", {}) if isinstance(job.get("image"), dict) else {}
        with image_path.open("rb") as handle:
            encoded_image = base64.b64encode(handle.read()).decode("ascii")

        payload = {
            "model": self.ollama_model,
            "prompt": self.semantic_prompt,
            "images": [encoded_image],
            "stream": False,
            "format": "json",
            "options": {
                "temperature": 0.1,
                "num_predict": 512,
            },
        }
        body = json.dumps(payload).encode("utf-8")
        req = request.Request(
            self.ollama_url + "/api/generate",
            data=body,
            headers={"Content-Type": "application/json", "Accept": "application/json"},
            method="POST",
        )
        try:
            with request.urlopen(req, timeout=watcher_state.AI_ANALYZER_TIMEOUT_SECONDS) as response:
                response_payload = json.loads(response.read().decode("utf-8", "replace"))
        except error.URLError as exc:
            raise RuntimeError(f"Ollama vision backend is unavailable at {self.ollama_url}: {exc}") from exc
        except json.JSONDecodeError as exc:
            raise RuntimeError("Ollama vision backend returned invalid JSON.") from exc

        raw_response = str(response_payload.get("response") or "").strip()
        semantic = self.parse_semantic_json(raw_response)
        labels = self.normalize_label_list(semantic.get("labels"))
        objects = self.normalize_label_list(semantic.get("objects"))
        activities = self.normalize_label_list(semantic.get("activities"))
        scene = str(semantic.get("scene") or "").strip().lower()
        recognized_text = str(semantic.get("text") or "").strip()
        internal_description = str(semantic.get("internal_description") or "").strip()
        if not internal_description:
            internal_description = self.semantic_description_from_parts(scene, objects, activities, labels)
        if not internal_description:
            internal_description = "semantic image metadata generated locally"

        metadata = {
            "analyzer": "local-ollama-vision-metadata",
            "analyzer_version": "1",
            "backend": "ollama",
            "ollama_url": self.ollama_url,
            "ollama_model": self.ollama_model,
            "internal_description": internal_description,
            "labels": sorted(set(labels + objects + activities + ([scene] if scene else []))),
            "objects": objects,
            "scene": scene,
            "activities": activities,
            "text": recognized_text,
            "confidence_notes": str(semantic.get("confidence_notes") or "").strip(),
            "source": {
                "filename": str(image_info.get("filename") or image_path.name),
                "image_id": int(image_info.get("id") or 0),
                "checksum_sha256": str(image_info.get("checksum_sha256") or ""),
            },
        }
        searchable_parts = [
            internal_description,
            scene,
            recognized_text,
            " ".join(metadata["labels"]),
            str(image_info.get("filename") or ""),
        ]
        searchable_text = " ".join(part for part in searchable_parts if part).strip()
        return metadata, searchable_text

    def parse_semantic_json(self, raw_response: str) -> Dict[str, Any]:
        """
        Decode the JSON object produced by a local vision model.

        Vision models sometimes wrap JSON in explanatory text despite explicit
        prompting. This parser first tries strict JSON, then extracts the first
        object-shaped range as a practical recovery path.

        Args:
            raw_response (str): Raw response text from the local model.

        Returns:
            Dict[str, Any]: Decoded dictionary.

        Raises:
            RuntimeError: Raised when no JSON object can be decoded.
        """
        try:
            decoded = json.loads(raw_response)
        except json.JSONDecodeError:
            start = raw_response.find("{")
            end = raw_response.rfind("}")
            if start < 0 or end <= start:
                raise RuntimeError(f"Ollama vision backend did not return a JSON object: {raw_response[:500]}")
            try:
                decoded = json.loads(raw_response[start:end + 1])
            except json.JSONDecodeError as exc:
                raise RuntimeError(f"Ollama vision backend returned unusable JSON: {raw_response[:500]}") from exc
        if not isinstance(decoded, dict):
            raise RuntimeError("Ollama vision backend JSON must be an object.")
        return decoded

    def normalize_label_list(self, value: JsonValue) -> List[str]:
        """
        Normalize model-produced labels into short searchable strings.

        Args:
            value (JsonValue): Raw decoded JSON. A string is split at commas
                or semicolons; list members are converted to strings. Other
                JSON kinds yield no labels.

        Returns:
            List[str]: Deduplicated list of lower-case labels.
        """
        if isinstance(value, str):
            candidates = [part.strip() for part in value.replace(";", ",").split(",")]
        elif isinstance(value, list):
            candidates = [str(part).strip() for part in value]
        else:
            candidates = []
        labels: List[str] = []
        for candidate in candidates:
            label = " ".join(candidate.lower().split())
            if label and len(label) <= 80 and label not in labels:
                labels.append(label)
        return labels[:40]

    def semantic_description_from_parts(self, scene: str, objects: List[str], activities: List[str], labels: List[str]) -> str:
        """
        Create a compact fallback sentence from semantic model fields.

        Args:
            scene (str): Scene label returned by the model.
            objects (List[str]): Visible object labels.
            activities (List[str]): Visible activity labels.
            labels (List[str]): General labels.

        Returns:
            str: Compact internal description.
        """
        parts: List[str] = []
        if scene:
            parts.append(scene)
        if objects:
            parts.append("objects: " + ", ".join(objects[:12]))
        if activities:
            parts.append("activities: " + ", ".join(activities[:8]))
        if not parts and labels:
            parts.append(", ".join(labels[:12]))
        return "; ".join(parts)

    def analyze_with_pillow(self, image_path: Path, job: Dict[str, Any]) -> Tuple[Dict[str, Any], str]:
        """
        Produce internal searchable metadata using local Pillow inspection.

        This is a conservative fallback, not an authoritative human caption. It
        records technical and visual descriptors that help search find likely
        images while a stronger local model can be configured later.

        Args:
            image_path (Path): Local path to the downloaded image.
            job (Dict[str, Any]): Server job payload.

        Returns:
            Tuple[Dict[str, Any], str]: Tuple of metadata dictionary and
                searchable text.

        Raises:
            RuntimeError: Raised when Pillow is unavailable or cannot read the
                file.
        """
        if runtime.Image is None or runtime.ImageOps is None or runtime.ImageStat is None:
            raise RuntimeError("Pillow is required for the built-in AI metadata analyzer. Use Install or repair dependencies.")

        image_info = job.get("image", {}) if isinstance(job.get("image"), dict) else {}
        with runtime.Image.open(image_path) as opened:
            image = runtime.ImageOps.exif_transpose(opened)
            width, height = image.size
            mode = image.mode
            fmt = str(opened.format or image_path.suffix.lstrip(".")).upper()
            rgb = image.convert("RGB")
            sample = rgb.copy()
            sample.thumbnail((256, 256))
            grayscale = sample.convert("L")
            luminance_stat = runtime.ImageStat.Stat(grayscale)
            rgb_stat = runtime.ImageStat.Stat(sample)
            mean_luminance = float(luminance_stat.mean[0]) if luminance_stat.mean else 0.0
            luminance_std = float(luminance_stat.stddev[0]) if luminance_stat.stddev else 0.0
            colorfulness = self.colorfulness_score(rgb_stat)
            dominant_colors = self.dominant_color_labels(sample)

        orientation = self.orientation_label(width, height)
        brightness = self.brightness_label(mean_luminance)
        contrast = self.contrast_label(luminance_std)
        color_label = self.colorfulness_label(colorfulness)
        labels = [orientation, brightness, contrast, color_label]
        labels.extend(dominant_colors)
        labels = [label for label in labels if label]
        internal_description = f"{orientation} image, {brightness}, {contrast}, {color_label}"
        if dominant_colors:
            internal_description += ", dominant colors: " + ", ".join(dominant_colors)

        metadata = {
            "analyzer": "local-pillow-visual-metadata",
            "analyzer_version": "1",
            "internal_description": internal_description,
            "labels": labels,
            "dominant_colors": dominant_colors,
            "technical": {
                "width": width,
                "height": height,
                "aspect_ratio": round(width / height, 4) if height else None,
                "orientation": orientation,
                "mode": mode,
                "format": fmt,
                "mean_luminance": round(mean_luminance, 2),
                "luminance_stddev": round(luminance_std, 2),
                "colorfulness": round(colorfulness, 2),
            },
            "source": {
                "filename": str(image_info.get("filename") or image_path.name),
                "image_id": int(image_info.get("id") or 0),
                "checksum_sha256": str(image_info.get("checksum_sha256") or ""),
            },
        }
        searchable_text = " ".join([internal_description, " ".join(labels), str(image_info.get("filename") or "")])
        return metadata, searchable_text

    def colorfulness_score(self, stat: "ImageStat.Stat") -> float:
        """
        Return a simple RGB dispersion score for colorfulness.

        Args:
            stat (ImageStat.Stat): RGB sample statistics whose mean list holds
                the red, green and blue channel means.

        Returns:
            float: Approximate colorfulness score.
        """
        if not stat.mean or len(stat.mean) < 3:
            return 0.0
        red, green, blue = [float(value) for value in stat.mean[:3]]
        rg = abs(red - green)
        yb = abs(0.5 * (red + green) - blue)
        return math.sqrt((rg * rg) + (yb * yb))

    def dominant_color_labels(self, image: "Image.Image") -> List[str]:
        """
        Return compact color labels from a sampled image.

        Args:
            image (Image.Image): RGB Pillow image supporting convert() and
                getcolors(), which returns count/RGB-triple pairs.

        Returns:
            List[str]: List of color labels suitable for internal search.
        """
        adaptive_palette = getattr(runtime.Image, "ADAPTIVE", None)
        if adaptive_palette is None and hasattr(runtime.Image, "Palette"):
            adaptive_palette = getattr(runtime.Image.Palette, "ADAPTIVE", 1)
        if adaptive_palette is None:
            adaptive_palette = 1
        quantized = image.convert("P", palette=adaptive_palette, colors=5).convert("RGB")
        colors = quantized.getcolors(maxcolors=256 * 256) or []
        colors = sorted(colors, key=lambda item: item[0], reverse=True)[:5]
        labels: List[str] = []
        for _count, rgb in colors:
            label = self.rgb_label(rgb)
            if label and label not in labels:
                labels.append(label)
        return labels[:4]

    def rgb_label(self, rgb: Tuple[int, int, int]) -> str:
        """
        Convert one RGB color into a coarse human-readable label.

        Args:
            rgb (Tuple[int, int, int]): Red, green, and blue tuple.

        Returns:
            str: Coarse color label.
        """
        red, green, blue = rgb
        maximum = max(red, green, blue)
        minimum = min(red, green, blue)
        if maximum < 45:
            return "black"
        if minimum > 215:
            return "white"
        if maximum - minimum < 24:
            if maximum < 95:
                return "dark gray"
            if maximum > 175:
                return "light gray"
            return "gray"
        if red >= green and red >= blue:
            if green > 140 and blue < 110:
                return "yellow"
            if blue > 120 and green < 120:
                return "magenta"
            return "red"
        if green >= red and green >= blue:
            if red > 130 and blue < 110:
                return "yellow-green"
            if blue > 120:
                return "cyan"
            return "green"
        if red > 120 and green < 120:
            return "purple"
        return "blue"

    def orientation_label(self, width: int, height: int) -> str:
        """
        Return landscape, portrait, square, or panorama from image dimensions.

        Args:
            width (int): Image width in pixels.
            height (int): Image height in pixels.

        Returns:
            str: Orientation label.
        """
        if width <= 0 or height <= 0:
            return "unknown orientation"
        ratio = width / height
        if ratio > 2.0:
            return "panorama"
        if ratio > 1.15:
            return "landscape"
        if ratio < 0.87:
            return "portrait"
        return "square"

    def brightness_label(self, luminance: float) -> str:
        """
        Return a coarse brightness label.

        Args:
            luminance (float): Mean luminance from 0 to 255.

        Returns:
            str: Brightness label.
        """
        if luminance < 55:
            return "dark"
        if luminance < 115:
            return "dim"
        if luminance < 185:
            return "balanced brightness"
        return "bright"

    def contrast_label(self, stddev: float) -> str:
        """
        Return a coarse contrast label.

        Args:
            stddev (float): Luminance standard deviation.

        Returns:
            str: Contrast label.
        """
        if stddev < 28:
            return "low contrast"
        if stddev > 72:
            return "high contrast"
        return "medium contrast"

    def colorfulness_label(self, score: float) -> str:
        """
        Return a coarse colorfulness label.

        Args:
            score (float): Approximate colorfulness score.

        Returns:
            str: Colorfulness label.
        """
        if score < 18:
            return "muted colors"
        if score > 70:
            return "very colorful"
        return "colorful"
