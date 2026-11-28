# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/ai/heartbeat.py
# Module Type: Windows AI Worker Support
# Purpose: Keep the server lease alive while an AI job is processed.
# Responsibilities:
#   - Send bounded progress heartbeats for one active AI claim.
#   - Stop after terminal authorization or mode-evidence refusal.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Maintain server-side leases for active AI jobs."""

import threading
from typing import Callable, Optional, Tuple

from .. import http_transport
from ..http_transport import GalleryKeyRedirectError
from ..mode_coordinator import ModeCoordinatorError
from ..localization import gettext_noop
from ..watcher.state import AI_HEARTBEAT_MIN_SECONDS

class PeriodicAIHeartbeat(threading.Thread):
    """
    Sends periodic lease heartbeats while one AI job is being processed.

    The worker thread owns the actual image analysis. This helper only extends
    the server lease, which protects long external model runs from being claimed
    by another companion app after the original lease expires.
    """

    def __init__(
        self,
        upload_url: str,
        api_key: str,
        job_id: int,
        claim_token: str,
        lease_seconds: int,
        progress_callback: Callable[[], Tuple[int, str]],
        emit: Callable[[str, str], None],
        before_request: Optional[Callable[[], None]] = None,
        terminal_event: Optional[threading.Event] = None,
    ) -> None:
        """
        Create a heartbeat thread for one claimed job.

        Args:
            upload_url (str): Normalized upload automation endpoint.
            api_key (str): Gallery-scoped API key.
            job_id (int): Server job id from the claim response.
            claim_token (str): Server claim token returned with the job.
            lease_seconds (int): Requested lease extension length.
            progress_callback (Callable[[], Tuple[int, str]]): Snapshot callback
                returning integer progress percent and status text.
            emit (Callable[[str, str], None]): Status callback receiving level
                and message, with no return value.
            before_request (Optional[Callable[[], None]]): GUI work checkpoint,
                or None for non-GUI fixture and compatibility callers.
            terminal_event (Optional[threading.Event]): Shared AI parent signal,
                or None for an independent heartbeat.

        Returns:
            None: Create a heartbeat thread for one claimed job.
        """
        super().__init__(name="PHPGalleryAIHeartbeat", daemon=True)
        self.upload_url = upload_url
        self.api_key = api_key
        self.job_id = job_id
        self.claim_token = claim_token
        self.lease_seconds = lease_seconds
        self.progress_callback = progress_callback
        self.emit = emit
        self.before_request = before_request
        self.terminal_event = terminal_event
        self.stop_event = threading.Event()
        self.interval = max(AI_HEARTBEAT_MIN_SECONDS, min(120.0, lease_seconds / 3.0))

    def stop(self) -> None:
        """Stop scheduling heartbeats without claiming an active request ended.

        Returns:
            None: Signals the worker; an in-flight request may still be active.
        """
        self.stop_event.set()

    def run(self) -> None:
        """
        Send heartbeats until stopped.

        Returns:
            None: Ends after a stop signal or terminal authorization refusal.
        """
        while not self.stop_event.wait(self.interval):
            try:
                progress_percent, message = self.progress_callback()
                http_transport.post_json(
                    self.upload_url,
                    self.api_key,
                    {
                        "action": "ai_heartbeat",
                        "job_id": self.job_id,
                        "claim_token": self.claim_token,
                        "lease_seconds": self.lease_seconds,
                        "progress_percent": int(progress_percent),
                        "message": str(message),
                    },
                    timeout_seconds=30,
                    **({'before_request': self.before_request}
                       if self.before_request is not None else {}),
                )
            except (ModeCoordinatorError, GalleryKeyRedirectError):
                if self.terminal_event is not None:
                    self.terminal_event.set()
                self.stop_event.set()
                self.emit('warning', gettext_noop('Gallery authorization could not be verified; AI heartbeat stopped.'))
            except Exception as exc:  # noqa: BLE001
                self.emit("warning", f"AI worker heartbeat failed for job {self.job_id}: {exc}")
