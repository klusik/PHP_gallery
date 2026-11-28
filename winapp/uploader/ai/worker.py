# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/ai/worker.py
# Module Type: AI Job Worker
# Purpose: Claim and process Gallery-owned AI metadata jobs in a background thread.
# Responsibilities:
#   - Retain GUI mode admission while polling, downloading and reporting jobs.
#   - Keep worker identity and optional image-analysis helpers in canonical modules.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Own the background worker for Gallery-assigned AI jobs."""

import ctypes
import logging
import mimetypes
import os
import queue
import shutil
import tempfile
import threading
import uuid
from pathlib import Path
from typing import Any, Dict, Optional, Tuple

from .. import http_transport
from ..http_transport import DEFAULT_TIMEOUT_SECONDS, GalleryKeyRedirectError
from ..localization import gettext_noop
from ..mode_coordinator import GuiModeOwner, ModeConfigPort, ModeCoordinatorError
from ..watcher import state as watcher_state
from ..watcher.state import ConfigStore, capture_worker_config
from .. import watcher
from .image_analysis import AIImageAnalyzer
from .heartbeat import PeriodicAIHeartbeat

def extension_for_content_type(content_type: str, fallback_name: str) -> str:
    """
    Return a safe filename suffix for a downloaded AI job asset.

    Args:
        content_type (str): Response Content-Type header.
        fallback_name (str): Original filename from the claimed job payload.

    Returns:
        str: File extension including the leading dot.
    """
    fallback_suffix = Path(fallback_name).suffix.lower()
    if fallback_suffix in watcher_state.SUPPORTED_SUFFIXES:
        return fallback_suffix
    mime = content_type.split(";", 1)[0].strip().lower()
    guessed = mimetypes.guess_extension(mime) or ""
    if guessed.lower() in {".jpe"}:
        return ".jpg"
    return guessed if guessed else ".img"


def ai_worker_id() -> str:
    """
    Return a stable local worker id for server-side lease diagnostics.

    The value is not a secret and is not used for authorization. It lets the
    server show which companion app instance currently owns a claim.

    Returns:
        str: Stable worker identifier for this Windows profile and machine.
    """
    watcher_state.CONFIG_DIR.mkdir(parents=True, exist_ok=True)
    worker_path = watcher_state.CONFIG_DIR / "worker_id.txt"
    try:
        if worker_path.is_file():
            existing = worker_path.read_text(encoding="utf-8").strip()
            if existing:
                return existing
        generated = f"{os.environ.get('COMPUTERNAME', 'windows').strip() or 'windows'}:{uuid.uuid4().hex}"
        worker_path.write_text(generated, encoding="utf-8")
        return generated
    except OSError:
        return f"temporary:{uuid.uuid4().hex}"


def enter_background_thread_mode() -> None:
    """
    Ask Windows to schedule the current thread as background work.

    This keeps optional image analysis from competing aggressively with the tray
    UI and ordinary uploads. Unsupported platforms silently continue because the
    worker can still run correctly without this scheduling hint.
    """
    if os.name != "nt":
        return
    try:
        kernel32 = ctypes.windll.kernel32  # type: ignore[attr-defined]
        thread_handle = kernel32.GetCurrentThread()
        thread_mode_background_begin = 0x00010000
        if kernel32.SetThreadPriority(thread_handle, thread_mode_background_begin):
            return
        thread_priority_below_normal = -1
        kernel32.SetThreadPriority(thread_handle, thread_priority_below_normal)
    except Exception:  # noqa: BLE001
        logging.debug("Could not set background thread priority.", exc_info=True)


class AIAnalysisWorkerThread(threading.Thread):
    """
    Background worker that asks PHP Gallery for server-assigned AI jobs.

    It never scans the gallery by itself. The server is authoritative: each poll
    either returns one claimed job with a lease token or no job. This makes it
    safe to run several companion app instances against the same gallery key.
    """

    def __init__(self, config: watcher_state.WatcherConfig, events: "queue.Queue[Tuple[str, str]]",
                 gui_admission: Optional[Tuple[GuiModeOwner, ModeConfigPort]] = None) -> None:
        """
        Create a background AI-analysis worker.

        Args:
            config (WatcherConfig): Runtime configuration captured when the
                worker starts.
            events (queue.Queue[Tuple[str, str]]): Queue used to send status
                lines back to the UI.
            gui_admission (Optional[Tuple[GuiModeOwner, ModeConfigPort]]): GUI
                owner and port for the exact captured AI configuration.

        Returns:
            None: Create a background AI-analysis worker.
        """
        super().__init__(name="PHPGalleryAIAnalysisWorker", daemon=True)
        self.config = config
        self.events = events
        self._gui_admission = gui_admission
        self._gui_work: Optional[CoordinatedWork] = None
        self._terminal_refused = threading.Event()
        self.stop_event = threading.Event()
        self.worker_id = ai_worker_id()
        self.progress_lock = threading.Lock()
        self.progress_percent = 0
        self.progress_message = "Starting."

    def stop(self) -> None:
        """
        Request the worker loop to stop.
        """
        self.stop_event.set()

    def _request_checkpoint(self) -> None:
        """Stop every new AI request after a shared terminal refusal.

        Returns:
            None: The AI job and any GUI work remain admitted.

        Raises:
            GalleryKeyRedirectError: A heartbeat already refused a redirect.
            ModeCoordinatorError: Current mode evidence cannot admit a request.
        """
        if self._terminal_refused.is_set():
            raise GalleryKeyRedirectError('Gallery authorization is unavailable.')
        if self._gui_work is not None:
            self._gui_work.checkpoint()

    def emit(self, level: str, message: str) -> None:
        """
        Send one worker event to the UI and file log.

        Args:
            level (str): Severity label.
            message (str): Human-readable status text.

        Returns:
            None: Send one worker event to the UI and file log.
        """
        logging.log(logging.ERROR if level == "error" else logging.INFO, "%s", message)
        self.events.put((level, message))

    def update_progress(self, percent: int, message: str) -> None:
        """
        Store the current progress text used by heartbeat requests.

        Args:
            percent (int): Integer progress from 0 to 99 while running.
            message (str): Short status text.

        Returns:
            None: Store the current progress text used by heartbeat requests.
        """
        with self.progress_lock:
            self.progress_percent = max(0, min(99, int(percent)))
            self.progress_message = message

    def current_progress(self) -> Tuple[int, str]:
        """
        Return the latest heartbeat progress tuple.

        Returns:
            Tuple[int, str]: Progress percent and message.
        """
        with self.progress_lock:
            return self.progress_percent, self.progress_message

    def run(self) -> None:
        """Hold the GUI work lease across AI jobs and their heartbeats.

        Returns:
            None: Stops on unavailable mode and releases its own work lease.
        """
        try:
            if self._gui_admission is not None:
                owner, port = self._gui_admission
                self._gui_work = owner.acquire('legacy', port)
            self._run_owned()
        except (ModeCoordinatorError, GalleryKeyRedirectError):
            self._terminal_refused.set()
            self.emit('error', gettext_noop('Gallery authorization could not be verified; AI worker stopped.'))
        finally:
            if self._gui_work is not None:
                self._gui_work.close()

    def _run_owned(self) -> None:
        """Poll the server until stopped while retaining admitted work.

        Returns:
            None: Processes claimed jobs without admitting another mode.
        """
        enter_background_thread_mode()
        upload_url = http_transport.normalize_upload_url(self.config.gallery_url)
        api_key = self.config.api_key.strip()
        if not self.config.ai_worker_enabled:
            self.emit("info", gettext_noop("AI metadata worker is disabled in configuration."))
            return
        if not upload_url or not api_key:
            self.emit("error", gettext_noop("AI metadata worker needs Gallery URL and API key."))
            return

        poll_seconds = max(watcher_state.AI_WORKER_MIN_POLL_SECONDS, float(self.config.ai_worker_poll_seconds or watcher_state.DEFAULT_AI_WORKER_POLL_SECONDS))
        lease_seconds = watcher_state.clamp_int(
            int(self.config.ai_worker_lease_seconds or watcher_state.DEFAULT_AI_WORKER_LEASE_SECONDS),
            watcher_state.AI_WORKER_MIN_LEASE_SECONDS, watcher_state.AI_WORKER_MAX_LEASE_SECONDS,
        )
        model_name = self.config.ai_model_name.strip() or watcher_state.AI_MODEL_NAME_DEFAULT
        model_version = self.config.ai_model_version.strip() or watcher_state.AI_MODEL_VERSION_DEFAULT
        self.emit("info", f"AI metadata worker started as {self.worker_id} using {model_name} {model_version}.")

        while not self.stop_event.is_set():
            try:
                claimed = http_transport.post_json(
                    upload_url,
                    api_key,
                    {
                        "action": "ai_next_job",
                        "worker_id": self.worker_id,
                        "model_name": model_name,
                        "model_version": model_version,
                        "lease_seconds": lease_seconds,
                    },
                    timeout_seconds=60,
                    before_request=self._request_checkpoint,
                )
                job = claimed.get("job")
                if not isinstance(job, dict):
                    wait_seconds = float(claimed.get("poll_after_seconds") or poll_seconds)
                    self.update_progress(0, "No job available.")
                    self.stop_event.wait(max(watcher_state.AI_WORKER_MIN_POLL_SECONDS, wait_seconds))
                    continue
                self.process_job(upload_url, api_key, job, lease_seconds)
            except (ModeCoordinatorError, GalleryKeyRedirectError):
                self._terminal_refused.set()
                raise
            except Exception as exc:  # noqa: BLE001
                self.emit("warning", f"AI metadata worker poll failed: {exc}")
                self.stop_event.wait(poll_seconds)

        self.emit("info", gettext_noop("AI metadata worker stopped."))

    def process_job(self, upload_url: str, api_key: str, job: Dict[str, Any], lease_seconds: int) -> None:
        """
        Download, analyze, and report one claimed AI job.

        Args:
            upload_url (str): Normalized upload automation endpoint.
            api_key (str): Gallery-scoped API key.
            job (Dict[str, Any]): Claimed job payload from the server.
            lease_seconds (int): Requested lease extension length.

        Returns:
            None: Download, analyze, and report one claimed AI job.
        """
        job_id = int(job.get("job_id") or 0)
        claim_token = str(job.get("claim_token") or "")
        image = job.get("image", {}) if isinstance(job.get("image"), dict) else {}
        filename = str(image.get("filename") or f"job-{job_id}.img")
        if job_id <= 0 or not claim_token:
            self.emit("warning", gettext_noop("AI metadata worker received an invalid job payload."))
            return

        heartbeat = PeriodicAIHeartbeat(
            upload_url, api_key, job_id, claim_token, lease_seconds,
            self.current_progress, self.emit,
            before_request=self._request_checkpoint,
            terminal_event=self._terminal_refused,
        )
        temp_dir = Path(tempfile.mkdtemp(prefix="php_gallery_ai_"))
        heartbeat_started = False
        try:
            downloaded_path = temp_dir / ("source" + extension_for_content_type(str(image.get("mime_type") or ""), filename))
            heartbeat.start()
            heartbeat_started = True
            self.update_progress(5, "Downloading assigned image.")
            self.emit("info", f"AI metadata worker claimed job {job_id} for {filename}.")
            content_type, downloaded = http_transport.post_binary_to_file(
                upload_url,
                api_key,
                {
                    "action": "ai_asset",
                    "job_id": str(job_id),
                    "claim_token": claim_token,
                },
                downloaded_path,
                timeout_seconds=DEFAULT_TIMEOUT_SECONDS,
                before_request=self._request_checkpoint,
            )
            if downloaded <= 0:
                raise RuntimeError("Server returned an empty image asset.")
            corrected_suffix = extension_for_content_type(content_type, filename)
            if downloaded_path.suffix.lower() != corrected_suffix:
                corrected_path = temp_dir / ("source" + corrected_suffix)
                downloaded_path.rename(corrected_path)
                downloaded_path = corrected_path

            self.update_progress(35, "Analyzing image locally.")
            analyzer = AIImageAnalyzer(self.config)
            metadata, searchable_text = analyzer.analyze(downloaded_path, job)
            self.update_progress(90, "Reporting AI metadata.")
            http_transport.post_json(
                upload_url,
                api_key,
                {
                    "action": "ai_complete",
                    "job_id": job_id,
                    "claim_token": claim_token,
                    "status": "succeeded",
                    "metadata": metadata,
                    "searchable_text": searchable_text,
                },
                timeout_seconds=60,
                before_request=self._request_checkpoint,
            )
            self.update_progress(100, "Completed.")
            self.emit("info", f"AI metadata stored for {filename}.")
        except (ModeCoordinatorError, GalleryKeyRedirectError):
            self._terminal_refused.set()
            raise
        except Exception as exc:  # noqa: BLE001
            self.update_progress(0, "Reporting failure.")
            self.report_failure(upload_url, api_key, job_id, claim_token, filename, exc)
        finally:
            heartbeat.stop()
            if heartbeat_started:
                # The parent stays active until the last heartbeat request exits.
                # Network and DNS delays can extend this wait beyond socket timeouts.
                heartbeat.join()
            shutil.rmtree(temp_dir, ignore_errors=True)

    def report_failure(self, upload_url: str, api_key: str, job_id: int, claim_token: str, filename: str, exc: Exception) -> None:
        """
        Report one failed job to the server without hiding local diagnostics.

        Args:
            upload_url (str): Normalized upload automation endpoint.
            api_key (str): Gallery-scoped API key.
            job_id (int): Failed server job id.
            claim_token (str): Claim token returned with the job.
            filename (str): Original filename for readable logging.
            exc (Exception): Exception raised during processing.

        Returns:
            None: Report one failed job to the server without hiding local
                diagnostics.
        """
        message = str(exc)
        try:
            http_transport.post_json(
                upload_url,
                api_key,
                {
                    "action": "ai_complete",
                    "job_id": job_id,
                    "claim_token": claim_token,
                    "status": "failed",
                    "error": message,
                },
                timeout_seconds=60,
                before_request=self._request_checkpoint,
            )
        except (ModeCoordinatorError, GalleryKeyRedirectError):
            self._terminal_refused.set()
            raise
        except Exception as report_exc:  # noqa: BLE001
            self.emit("warning", f"AI metadata worker could not report failure for {filename}: {report_exc}")
        self.emit("warning", f"AI metadata worker failed for {filename}: {message}")


def create_ai_worker(
    store: ConfigStore, config: watcher_state.WatcherConfig, owner: GuiModeOwner,
    events: "queue.Queue[tuple[str, str]]",
) -> AIAnalysisWorkerThread:
    """Prepare the existing AI request owner from one saved configuration.

    Args:
        store (ConfigStore): Owner of the exact configuration generation.
        config (WatcherConfig): Currently verified saved AI configuration.
        owner (GuiModeOwner): GUI mode admission used for all current requests.
        events (queue.Queue[tuple[str, str]]): Ordered severity and status events.
    Returns:
        AIAnalysisWorkerThread: Unstarted worker with its captured mode port.
    """
    captured = capture_worker_config(config)
    return AIAnalysisWorkerThread(captured, events, (owner, store.mode_port(captured)))
