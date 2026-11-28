# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/watcher/ui/settings/updates.py
# Module Type: Windows Watcher Update UI
# Purpose: Own update admission, continuation and checkpoint lifecycle.
# Responsibilities:
#   - Verify and resume the exact Watcher state across application updates.
#   - Preserve existing updater gates, persistence and Tk callback order.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Own Watcher update continuation and checkpoint interactions."""

from uploader.watcher import runtime
from typing import Optional
from uploader.watcher import state as watcher_state
from uploader.localization import gettext
from uploader import update_helper
from uploader.app_linking import LinkedGallery
from uploader.app_linking import LinkingResponseError
from uploader.flight_jobs import SessionPlan
from uploader.flight_jobs import WATCHER_CONTINUATION_LIFETIME_SECONDS
from uploader.flight_jobs import WatcherContinuation
from uploader.flight_jobs import WatcherContinuationError
from uploader.flight_jobs import connection_binding
from uploader.flight_jobs import read_document
from uploader.flight_recorder import FlightRecorder
from uploader.linked_upload import linked_target_binding
from uploader.linked_upload import verify_linked_upload_target
from uploader.mode_admission import LEGACY_UPLOAD_STATES
from uploader.watcher.workers.watcher import WatcherThread
from uploader.watcher.profile import WatcherProfile
import logging
from uploader.watcher.update_policy import UPDATE_CHECKPOINT_TIMEOUT_SECONDS
from uploader.watcher.update_policy import UPDATE_CONTINUATION_LINK_SECONDS
from uploader.watcher.update_policy import UPDATE_CONTINUATION_POLL_MS
from uploader.watcher.update_policy import UPDATE_RESUME_EXCEPTION_CLASS_MAX_CHARS

class WatcherUpdateMixin:
    """Own this focused Watcher UI lifecycle responsibility."""

    def set_update_available(self, version: Optional[str]) -> None:
        """Show or clear the verified uploader update across app surfaces.

        Args:
            version (Optional[str]): Available uploader release version, or
                None after a successful check finds no current offer.

        Returns:
            None: Updates the global banner, marked check icon, tray image,
                tooltip and menu while preserving verified state on Later.
        """
        normalized_version = version.strip() if version is not None else ""
        self.update_available_version = normalized_version or None
        has_update = self.update_available_version is not None
        if self.check_updates_button is not None:
            self.check_updates_button.configure(
                text="↑" if has_update else "↻",
                command=(
                    self.open_update_notice
                    if has_update
                    else self.updates.check
                ),
            )
        if self.update_banner is not None:
            if has_update:
                self.update_banner_label.configure(
                    text=gettext("Update {update_available_version} available").format(
                        update_available_version=self.update_available_version,
                    )
                )
                self.update_banner.grid()
            else:
                self.update_banner.grid_remove()

        tray_icon = self.tray_icon
        if tray_icon is None:
            return
        try:
            tray_icon.icon = self.make_tray_icon_image()
            tray_icon.title = self.tray_tooltip_text()
            tray_icon.menu = self.build_tray_menu()
            tray_icon.update_menu()
        except Exception:  # noqa: BLE001
            logging.debug("Could not refresh tray update indication.", exc_info=True)

    def open_update_notice(self) -> None:
        """Restore the app and open the controller's current update notice.

        Returns:
            None: Shows the known offer or begins a manual check when idle.
        """
        self.restore_from_tray()
        self.updates.open_updates()

    def tray_update_menu_label(self) -> str:
        """Return the tray menu's current update action caption.

        Returns:
            str: ``Update <version>`` for an available offer, otherwise
                ``Check for updates``.
        """
        if self.update_available_version is None:
            return gettext("Check for updates")
        return gettext("Update {version}").format(version=self.update_available_version)

    def _initialize_gui_mode(self) -> None:
        """Observe startup mode off Tk before any GUI key work can be admitted.

        Returns:
            None: Reports unavailable evidence without opening a fallback mode.
        """
        try:
            self.mode_owner.initialize()
        except watcher_state.ModeCoordinatorError:
            self.events.put({
                "kind": "linking_resume_startup",
                "payload": {"issuer": None},
            })
            self.events.put(('warning', 'Upload mode needs verification before API-key work.'))
            self.events.put({
                'kind': 'gui_mode_initialized', 'payload': {'verified': False},
            })
        else:
            identity = self.saved_linked_upload_identity()
            if identity is not None:
                self.events.put({"kind": "saved_linked_mode_ready"})
            self.events.put({
                "kind": "linking_resume_startup",
                "payload": {"issuer": identity[0] if identity else None},
            })
            self.events.put({
                'kind': 'gui_mode_initialized', 'payload': {'verified': True},
            })

    def _inspect_update_continuation(self) -> None:
        """Inspect one prepared restart off Tk after mode initialization.

        Returns:
            None: Queues a profile-bound continuation or a fixed safe reason;
                an ordinary startup never resumes a stopped capture.
        """
        if (not type(self)._watcher_connection_selected(self)
                or self._update_resume_attempted or not self._gui_mode_ready
                or getattr(self, '_update_resume_cancelled', False)
                or getattr(self, '_update_resume_inspecting', False)):
            return
        if self._update_resume_deadline <= 0.0:
            self._update_resume_deadline = (
                watcher_state.time.monotonic() + UPDATE_CONTINUATION_LINK_SECONDS
            )
        self._update_resume_generation += 1
        generation = self._update_resume_generation
        self._update_resume_attempted = True
        self._update_resume_inspecting = True
        requested = self._after_update_nonce
        inspection_stage = 'workspace_lease'

        def inspect() -> None:
            """Verify saved intent without touching Tk or uploading media.

            Returns:
                None: Queues only validated checkpoint metadata or a safe failure.
            """
            nonlocal inspection_stage

            def reject(reason: str) -> None:
                """Queue a fixed local diagnosis for a rejected checkpoint.

                Args:
                    reason (str): Fixed stage-specific reason identifier.

                Returns:
                    None: Publishes no checkpoint contents or private values.
                """
                self.events.put({
                    'kind': 'watcher_resume_failed',
                    'payload': {
                        'reason': reason, 'stage': inspection_stage,
                        'generation': generation,
                    },
                })

            def no_offer() -> None:
                """Release the busy flag on the GUI thread when there is no offer.

                Returns:
                    None: Completes this generation without admitting saved work.
                """
                self.events.put({
                    'kind': 'watcher_resume_inspection_no_offer',
                    'payload': {'generation': generation},
                })

            try:
                if getattr(self, '_update_resume_cancelled', False):
                    no_offer()
                    return
                automatic = requested is not None
                lease = self.flights.lease
                if lease is None:
                    raise ValueError('The flight workspace is unavailable.')
                inspection_stage = 'primary_checkpoint'
                saved = WatcherContinuation.load(lease)
                ordinary_restart = False
                if requested is not None:
                    inspection_stage = 'update_nonce_and_version'
                    if (saved is None or saved.update_session != requested
                            or saved.phase not in ('prepared', 'claimed')
                            or runtime.APP_VERSION not in (
                                saved.source_version, saved.target_version,
                            )):
                        raise ValueError('No matching update continuation.')
                elif saved is not None:
                    inspection_stage = 'ordinary_restart_mirror'
                    ordinary = WatcherContinuation.load(
                        lease, ordinary_restart=True,
                    )
                    if ordinary is not None and (
                        watcher_state.replace(ordinary, phase=saved.phase) == saved
                        and ordinary.phase in ('prepared', 'claimed')
                        and ordinary.source_version == runtime.APP_VERSION
                        and ordinary.target_version == runtime.APP_VERSION
                        and saved.phase in ('prepared', 'claimed')
                    ):
                        ordinary_restart = True
                    else:
                        inspection_stage = 'ordinary_restart_not_admitted'
                        no_offer()
                        return
                elif requested is not None:
                    raise ValueError('The update continuation is missing.')
                else:
                    # Older helpers cannot pass a nonce. Admit only an actual,
                    # verified installed-version transition plus an active API
                    # session with no potentially interrupted accepted upload.
                    inspection_stage = 'legacy_update_bridge'

                    ticket = update_helper.find_legacy_update_ticket(
                        runtime.APP_VERSION, self.updates.previous_version,
                        WATCHER_CONTINUATION_LIFETIME_SECONDS,
                    )
                    if (ticket is None
                            or self.mode_owner.view().state not in LEGACY_UPLOAD_STATES):
                        no_offer()
                        return
                    binding = connection_binding(
                        watcher_state.normalize_upload_url(self.config.gallery_url),
                        self.config.api_key,
                    )
                    pending = self.flights.pending(binding=binding)
                    if len(pending) != 1:
                        no_offer()
                        return
                    plan = pending[0]
                    queue_row = read_document(plan.directory / 'watch-drain.json')
                    telemetry = read_document(plan.directory / 'session.json')
                    if (plan.local_only or plan.completed or plan.abandoned
                            or queue_row.get('initial_captured', True) is not True
                            or queue_row.get('frozen') is not False
                            or queue_row.get('cutoff') != 0
                            or queue_row.get('error')
                            or telemetry.get('recording_ended') is not False):
                        no_offer()
                        return
                    if queue_row.get('pending') != []:
                        raise ValueError('Old-client accepted uploads need review.')
                    saved = WatcherContinuation(
                        plan.session_id, binding,
                        WatcherContinuation.profile_digest(self.flights.directory),
                        self.updates.previous_version, runtime.APP_VERSION,
                        ticket.session_id, 'legacy', False, watcher_state.time.time(), 'prepared',
                    )
                    saved.save(lease)
                if ordinary_restart:
                    inspection_stage = 'ordinary_session_and_queue'
                    pending = self.flights.pending(binding=saved.binding)
                    if len(pending) != 1 or pending[0].session_id != saved.session_id:
                        reject('restart_session_mismatch')
                        return
                    plan = pending[0]
                    queue_row = read_document(plan.directory / 'watch-drain.json')
                    mode_view = self.mode_owner.view()
                    mode_record = mode_view.record
                    if (plan.completed or plan.abandoned
                            # Delivery preference is not a mode identity:
                            # linked sessions may now require PDF receipts.
                            or (saved.mode == 'legacy' and plan.local_only)
                            or queue_row.get('initial_captured') is not True
                            or queue_row.get('frozen') is not False
                            or queue_row.get('cutoff') != 0 or queue_row.get('error')):
                        reject('restart_queue_needs_review')
                        return
                    if saved.mode == 'linked':
                        inspection_stage = 'ordinary_linked_identity'
                        if (mode_record is None or mode_record.mode != 'linked'
                                or mode_record.scope != saved.approved_scope
                                or linked_target_binding(
                                    mode_record.issuer, mode_record.account_id,
                                    mode_record.gallery_id,
                                ) != saved.binding):
                            reject('restart_linked_identity_changed')
                            return
                    elif (saved.mode != 'legacy'
                          or mode_view.state not in LEGACY_UPLOAD_STATES
                          or saved.binding != connection_binding(
                              watcher_state.normalize_upload_url(self.config.gallery_url),
                              self.config.api_key,
                          )):
                        reject('restart_legacy_target_changed')
                        return
                    automatic = True
                self.events.put({
                    'kind': 'watcher_resume_available',
                    'payload': {
                        'continuation': saved, 'automatic': automatic,
                        'ordinary_restart': ordinary_restart,
                        'generation': generation,
                    },
                })
            except OSError:
                if (not getattr(self, '_update_resume_cancelled', False)
                        and watcher_state.time.monotonic() < self._update_resume_deadline):
                    self.events.put({
                        'kind': 'watcher_resume_inspection_retry',
                        'payload': {
                            'reason': 'temporary_checkpoint_read_failure',
                            'stage': inspection_stage,
                            'generation': generation,
                        },
                    })
                    return
                self.events.put({
                    'kind': 'watcher_resume_failed',
                    'payload': {
                        'reason': 'checkpoint_read_unavailable',
                        'stage': inspection_stage,
                        'generation': generation,
                    },
                })
            except WatcherContinuationError as error:
                self.events.put({
                    'kind': 'watcher_resume_failed',
                    'payload': {
                        'reason': 'checkpoint_' + error.reason,
                        'stage': inspection_stage,
                        'generation': generation,
                    },
                })
            except (ValueError, RuntimeError):
                reason_by_stage = {
                    'workspace_lease': 'workspace_lease_unavailable',
                    'primary_checkpoint': 'primary_checkpoint_unverified',
                    'update_nonce_and_version': 'update_nonce_or_version_mismatch',
                    'ordinary_restart_mirror': 'restart_mirror_unverified',
                    'legacy_update_bridge': 'legacy_bridge_unverified',
                    'ordinary_session_and_queue': 'restart_queue_unverified',
                    'ordinary_linked_identity': 'restart_linked_identity_changed',
                }
                self.events.put({
                    'kind': 'watcher_resume_failed',
                    'payload': {
                        'reason': reason_by_stage.get(
                            inspection_stage, 'checkpoint_admission_unverified',
                        ),
                        'stage': inspection_stage,
                        'generation': generation,
                    },
                })

        try:
            watcher_state.threading.Thread(
                target=inspect, name='PHPGalleryUpdateResumeInspect', daemon=True,
            ).start()
        except RuntimeError:
            self._update_resume_inspecting = False
            self.events.put({
                'kind': 'watcher_resume_failed',
                'payload': {
                    'reason': 'inspection_worker_unavailable',
                    'stage': 'checkpoint_inspection',
                    'generation': generation,
                },
            })

    def _continue_update_resume(self) -> None:
        """Continue an admitted checkpoint without asking again on the Tk thread.

        Returns:
            None: Prepares the same verified API-key or linked capture after
                existing writers settle, or retains it with a safe diagnosis.
        """
        record = self._resume_continuation
        if (record is None or self.exiting or self._update_resume_preparing
                or not type(self)._watcher_connection_selected(self)):
            return
        if getattr(self, '_update_resume_cancelled', False):
            return
        generation = getattr(self, '_update_resume_generation', 0)
        if any(worker is not None and worker.is_alive()
               for worker in self._update_checkpoint_workers):
            if watcher_state.time.monotonic() >= self._update_resume_deadline:
                self._update_resume_failed('prior_workers_still_running')
                return
            self.root.after(UPDATE_CONTINUATION_POLL_MS, self._continue_update_resume)
            return
        try:
            view = self.mode_owner.view()
            if record.mode == 'legacy':
                if (view.state not in LEGACY_UPLOAD_STATES
                        or record.binding != connection_binding(
                            watcher_state.normalize_upload_url(self.config.gallery_url),
                            self.config.api_key,
                        )):
                    raise ValueError('The saved API target changed.')
                self._prepare_update_resume(None)
                return
            target_record = view.record
            if (target_record is None or target_record.mode != 'linked'
                    or target_record.scope != record.approved_scope
                    or linked_target_binding(
                        target_record.issuer, target_record.account_id,
                        target_record.gallery_id,
                    ) != record.binding):
                raise ValueError('The linked gallery target changed.')
            session = self.linking_manager.resume_upload_session(
                target_record.issuer, target_record.account_id,
            )
            if session is None:
                if watcher_state.time.monotonic() >= self._update_resume_deadline:
                    self._update_resume_failed('saved_gallery_access_unavailable')
                    return
                self.status_var.set(gettext('Restoring the approved Watcher connection…'))
                self.root.after(
                    UPDATE_CONTINUATION_POLL_MS, self._continue_update_resume,
                )
                return
            if session.approved_scope() != record.approved_scope:
                raise ValueError('The original linked scope changed.')
            self._update_resume_preparing = True

            def verify_target() -> None:
                """Reverify the original gallery using the approved live account.

                Returns:
                    None: Queues the exact target or a fixed authorization failure.
                """
                try:
                    target = verify_linked_upload_target(
                        session, target_record.issuer, target_record.account_id,
                        record.approved_scope, target_record.gallery_id,
                    )
                    self.events.put({
                        'kind': 'watcher_resume_target',
                        'payload': {
                            'target': target, 'generation': generation,
                        },
                    })
                except Exception as error:
                    if isinstance(error, LinkingResponseError):
                        transient_reasons = {
                            'dns_failure', 'connect_failure', 'tls_failure',
                            'timeout', 'transport_failure', 'throttled',
                        }
                        transient = (
                            error.status == 429
                            or error.status is not None
                            and 500 <= error.status <= 599
                            or error.status is None
                            and error.reason in transient_reasons
                        )
                        if transient:
                            self.events.put({
                                'kind': 'watcher_resume_retry',
                                'payload': {
                                    'reason': 'temporary_target_verification_failure',
                                    'stage': 'target_verification',
                                    'generation': generation,
                                },
                            })
                            return
                        if error.status in (401, 403):
                            self.events.put({
                                'kind': 'watcher_resume_failed',
                                'payload': {
                                    'reason': 'saved_gallery_access_revoked',
                                    'stage': 'target_authorization',
                                    'generation': generation,
                                },
                            })
                            return
                    error_type = type(error).__name__
                    if (not error_type.isidentifier()
                            or len(error_type) > UPDATE_RESUME_EXCEPTION_CLASS_MAX_CHARS):
                        error_type = 'Exception'
                    self.events.put((
                        'warning',
                        'Watcher resume verification failed; '
                        'operation=verify_linked_upload_target '
                        f'error_type={error_type}.',
                    ))
                    self.events.put({
                        'kind': 'watcher_resume_failed',
                        'payload': {
                            'reason': 'saved_gallery_access_unverified',
                            'stage': 'target_verification',
                            'generation': generation,
                        },
                    })

            watcher_state.threading.Thread(
                target=verify_target, name='PHPGalleryUpdateResumeTarget', daemon=True,
            ).start()
        except (OSError, ValueError, RuntimeError):
            self._update_resume_failed('saved_target_changed')

    def _update_resume_failed(self, reason: str, *, stage: str = 'resume') -> None:
        """Leave unfinished work recoverable with a fixed non-secret diagnosis.

        Args:
            reason (str): Locally authored continuation failure identifier.
            stage (str): Locally authored validation or recovery phase.

        Returns:
            None: Retains credentials and queue while showing the next action.
        """
        # The identifier is diagnostic data, never server text or an exception.
        if watcher_state.re.fullmatch(r'[a-z_]+', reason) is None:
            reason = 'continuation_unverified'
        if watcher_state.re.fullmatch(r'[a-z_]+', stage) is None:
            stage = 'resume'
        self._close_update_resume_context_key()
        self._update_resume_status = 'stage=' + stage + '; reason=' + reason
        self._update_resume_preparing = False
        self._resume_continuation = None
        messages = {
            'prior_workers_still_running':
                'Previous work is still finishing. Wait, then resume Watcher.',
            'saved_gallery_access_unavailable':
                'Reconnect the original gallery, then resume Watcher.',
            'saved_gallery_access_unverified':
                'The original gallery access could not be verified. Reconnect it.',
            'saved_watcher_access_unavailable':
                'Temporary Watcher access is unavailable. Reconnect the original gallery.',
            'saved_target_changed':
                'The original upload target changed. Select it before resuming.',
            'session_claim_unverified':
                'The saved session could not be verified. Review pending uploads.',
            'saved_session_needs_review':
                'Saved screenshot or flight data needs review. Copy diagnostics.',
            'saved_update_state_unverified':
                'The saved update state could not be verified. Review pending uploads.',
            'primary_checkpoint_unverified':
                'The saved Watcher checkpoint could not be verified. Review pending uploads.',
            'restart_mirror_unverified':
                'The restart checkpoint could not be verified. Review pending uploads.',
            'restart_queue_unverified':
                'The saved Watcher queue could not be verified. Review pending uploads.',
            'restart_queue_needs_review':
                'The saved Watcher queue needs review before resuming.',
            'restart_session_mismatch':
                'The saved Watcher session no longer matches its checkpoint.',
            'restart_linked_identity_changed':
                'The linked account or gallery changed. Select the original target.',
            'restart_legacy_target_changed':
                'The saved upload target changed. Select it before resuming.',
            'update_nonce_or_version_mismatch':
                'The update restart could not be matched to this checkpoint.',
            'workspace_lease_unavailable':
                'The saved Watcher workspace is unavailable. Review pending uploads.',
            'legacy_bridge_unverified':
                'The older update handoff could not be verified. Review pending uploads.',
            'checkpoint_admission_unverified':
                'The saved Watcher checkpoint could not be verified. Review pending uploads.',
            'inspection_worker_unavailable':
                'The saved Watcher checkpoint could not be inspected. Review pending uploads.',
            'checkpoint_expired':
                'The saved Watcher checkpoint expired. Review pending uploads.',
            'checkpoint_profile_mismatch':
                'The saved Watcher checkpoint belongs to a different profile.',
            'checkpoint_invalid_scope':
                'The saved linked permission scope changed. Reconnect the original gallery.',
            'saved_gallery_access_revoked':
                'The original gallery permission was refused. Reconnect that gallery.',
            'continuation_worker_stopped':
                'Watcher stopped before resume was confirmed. Review pending uploads before trying again.',
            'saved_watcher_key_close_pending':
                'Previous Watcher access is still being closed. Wait for confirmation, then resume Watcher.',
        }
        message = messages.get(
            reason, 'Watcher could not resume. Copy diagnostics; saved work is retained.',
        )
        self._set_watch_start_activity(False, message)
        self.write_log('[watcher-resume] ' + self._update_resume_status, 'warning')

    def _close_update_resume_context_key(self) -> None:
        """Revoke the exact staged contextual key after a failed resume.

        Returns:
            None: Retains ownership until a close result arrives; the key is
                never published as an active Watcher target on this path.
        """
        target = getattr(self, '_update_resume_context_target', None)
        if (not isinstance(target, watcher_state.LinkedUploadTarget)
                or target.watch_key is None
                or getattr(self, '_update_resume_context_key_closing', False)):
            return
        self._update_resume_context_key_closing = True

        def revoke() -> None:
            """Close one staged key off Tk and report only its outcome.

            Returns:
                None: Queues the target identity and a boolean close result.
            """
            try:
                target.session.close_watch_key(target.gallery_id, target.watch_key)
                closed = True
            except Exception:  # noqa: BLE001
                closed = False
            self.events.put({
                'kind': 'update_resume_context_key_closed',
                'payload': {'target': target, 'closed': closed},
            })

        try:
            worker = watcher_state.threading.Thread(
                target=revoke, name='PHPGalleryResumeKeyClose', daemon=True,
            )
            self._update_resume_context_key_worker = worker
            worker.start()
        except RuntimeError:
            self._update_resume_context_key_closing = False
            self._update_resume_context_key_worker = None

    def _finish_update_resume_context_key_close(
        self, target: watcher_state.LinkedUploadTarget, closed: bool,
    ) -> None:
        """Release local ownership only after the exact remote close result.

        Args:
            target (LinkedUploadTarget): Staged key owner from the worker event.
            closed (bool): True only after the server confirmed key closure.

        Returns:
            None: Detaches the matching temporary selection on success, or
                retains it for a later close retry after failure.
        """
        if target is not getattr(self, '_update_resume_context_target', None):
            return
        self._update_resume_context_key_closing = False
        self._update_resume_context_key_worker = None
        if closed:
            self._update_resume_context_target = None
            self.linking_manager.detach_photos_watcher_target(target)
            if (getattr(self, '_resume_retry_available', False)
                    and getattr(self, '_resume_continuation', None) is not None):
                self.refresh_watcher_action()
        else:
            self.status_var.set(
                gettext('Watcher access could not be closed. Review the saved session.'),
            )

    def _retry_update_continuation(self) -> bool:
        """Retry only an explicitly offered exact update continuation.

        Returns:
            bool: True when the visible Resume Watcher action starts its owned
                retry; False leaves the ordinary passive recovery action intact.
        """
        if not getattr(self, '_resume_retry_available', False):
            return False
        try:
            lease = self.flights.lease
            record = WatcherContinuation.load(lease) if lease is not None else None
            if (record is None or record.phase not in ('prepared', 'claimed')
                    or runtime.APP_VERSION not in (record.source_version, record.target_version)):
                return False
        except (OSError, ValueError, RuntimeError):
            return False
        self._resume_continuation = record
        self._update_resume_deadline = watcher_state.time.monotonic() + UPDATE_CONTINUATION_LINK_SECONDS
        self._continue_update_resume()
        return True

    def _prepare_update_resume(
        self, target: Optional[watcher_state.LinkedUploadTarget],
    ) -> bool:
        """Claim and prepare the same queue and telemetry off the Tk thread.

        Args:
            target (Optional[LinkedUploadTarget]): Reverified original linked
                target, including a fresh Watcher key when required, or None
                for the unchanged saved API-key connection.

        Returns:
            bool: True once this owner accepts responsibility for preparation.
        """
        record = self._resume_continuation
        generation = getattr(self, '_update_resume_generation', 0)
        lease = self.flights.lease
        staged_target = getattr(self, '_update_resume_context_target', None)
        if (record is not None and record.contextual
                and isinstance(staged_target, watcher_state.LinkedUploadTarget)):
            # Never replace a key whose remote close has not been confirmed.
            self._close_update_resume_context_key()
            self.status_var.set(
                gettext('Closing previous Watcher access. Try again after it finishes.'),
            )
            return False
        if (record is None or lease is None or self.exiting
                or not type(self)._watcher_connection_selected(self)
                or self.worker is not None and self.worker.is_alive()):
            return False
        try:
            lease.verify()
            current = WatcherContinuation.load(lease)
            if (current is None or current.session_id != record.session_id
                    or current.binding != record.binding
                    or current.profile_hash != record.profile_hash
                    or current.source_version != record.source_version
                    or current.target_version != record.target_version
                    or (current.update_session
                        and current.update_session != record.update_session)
                    or current.mode != record.mode
                    or current.contextual != record.contextual
                    or current.created != record.created
                    or current.phase not in ('preparing', 'prepared', 'claimed')):
                raise ValueError('The update continuation changed.')
            if record.binding != self.flights.binding():
                raise ValueError('The continuation target changed.')
            if record.mode == 'linked':
                mode_record = self.mode_owner.view().record
                if (mode_record is None
                        or mode_record.scope != record.approved_scope
                        or target is None
                        or target.session.approved_scope() != record.approved_scope):
                    raise ValueError('The original linked scope changed.')
            plan = SessionPlan.load(self.flights.directory / record.session_id)
            if (plan.binding != record.binding or plan.completed or plan.abandoned
                    or (record.mode == 'legacy' and plan.local_only)
                    or (target is not None and target.binding() != record.binding)
                    or record.contextual and (
                        target is None or target.watch_key is None
                    )):
                raise ValueError('The accepted session could not be verified.')
            claimed = watcher_state.replace(record, phase='claimed')
            if getattr(self, '_ordinary_restart_resuming', False) is True:
                ordinary = WatcherContinuation.load(
                    lease, ordinary_restart=True,
                )
                if (ordinary is None
                        or ordinary.phase not in ('prepared', 'claimed')
                        or watcher_state.replace(ordinary, phase=current.phase) != current):
                    raise ValueError('The ordinary restart consent changed.')
                claimed.save(lease, ordinary_restart=True)
            claimed.save(lease)
            self._resume_continuation = claimed
            shared = watcher_state.capture_worker_config(self.config)
            config = (watcher_state.capture_worker_config(type(self)._saved_watcher_config(self))
                      if isinstance(getattr(self, '_watcher_profile', None), WatcherProfile)
                      else shared)
            port = self.config_store.mode_port(shared)
        except (OSError, ValueError, RuntimeError):
            self._update_resume_failed('session_claim_unverified')
            return False
        self._update_resume_preparing = True
        self._set_watch_start_activity(True, 'Reading the saved Watcher session…')

        if record.contextual:
            # The LinkingManager callback returns True to transfer this exact
            # key. Record that ownership before any asynchronous preparation.
            self._update_resume_context_target = target
            self._update_resume_context_key_closing = False

        def prepare() -> None:
            """Restore immutable history while leaving the GUI responsive.

            Returns:
                None: Queues idle verified workers or a safe retained-work error.
            """
            worker = None
            operation = 'read_watch_drain'
            try:
                row = read_document(plan.directory / 'watch-drain.json')
                folder, recursive = row.get('folder'), row.get('recursive')
                if not isinstance(folder, str) or not isinstance(recursive, bool):
                    raise ValueError('The original folder could not be verified.')
                operation = 'create_watcher'
                worker = WatcherThread(
                    config, self.events, target,
                    gui_admission=(self.mode_owner, port),
                    watch_folder_override=watcher_state.Path(folder),
                    watch_recursive_override=recursive,
                )
                operation = 'restore_watch_drain'
                worker.configure_drain(
                    plan.directory, (), resume=True, continuation=True,
                )
                identity = (
                    target.issuer + ' #' + str(target.gallery_id)
                    if target is not None else watcher_state.normalize_upload_url(config.gallery_url)
                )
                operation = 'restore_flight_recorder'
                recorder = FlightRecorder.from_session(
                    plan.directory, identity, config.simconnect_dll_path,
                    lambda level, message: self.events.put((level, message)),
                )
                self.events.put({
                    'kind': 'watcher_resume_prepared',
                    'payload': {
                        'continuation': claimed, 'worker': worker,
                        'recorder': recorder, 'target': target,
                        'generation': generation,
                    },
                })
            except Exception as error:
                if worker is not None and worker._linked_lease is not None:
                    worker._linked_lease.release()
                error_type = type(error).__name__
                if (not error_type.isidentifier()
                        or len(error_type) > UPDATE_RESUME_EXCEPTION_CLASS_MAX_CHARS):
                    error_type = 'Exception'
                self.events.put((
                    'warning',
                    'Watcher resume preparation failed; '
                    'stage=resume_prepare operation='
                    f'{operation} error_type={error_type}.',
                ))
                self.events.put({
                    'kind': 'watcher_resume_failed',
                    'payload': {
                        'reason': 'saved_session_needs_review',
                        'stage': operation,
                        'generation': generation,
                    },
                })

        try:
            watcher_state.threading.Thread(
                target=prepare, name='PHPGalleryUpdateResumePrepare', daemon=True,
            ).start()
        except RuntimeError:
            self._update_resume_preparing = False
            if record.contextual:
                self._update_resume_context_target = None
            self._update_resume_failed('saved_session_needs_review')
            return False
        return True

    def _accept_update_resume_target(self, target: watcher_state.LinkedUploadTarget) -> None:
        """Activate only the freshly verified original linked destination.

        Args:
            target (LinkedUploadTarget): Existing authority reverified off Tk.

        Returns:
            None: Requests fresh contextual access or prepares ordinary linked
                capture; an unavailable authority leaves the queue retained.
        """
        record = self._resume_continuation
        self._update_resume_preparing = False
        if record is None or record.binding != target.binding() or self.exiting:
            return
        if target.session.approved_scope() != record.approved_scope:
            self._update_resume_failed('saved_scope_changed')
            return
        if record.contextual:
            if isinstance(
                getattr(self, '_update_resume_context_target', None),
                watcher_state.LinkedUploadTarget,
            ):
                self._close_update_resume_context_key()
                self._resume_retry_available = True
                self._update_resume_status = (
                    'stage=resume; reason=saved_watcher_key_close_pending'
                )
                self.status_var.set(
                    gettext(
                        "Previous Watcher access is still being closed. Wait for "
                        "confirmation, then resume Watcher."
                    ),
                )
                self.refresh_watcher_action()
                return
            # The existing Watcher owner independently rechecks this ID, account
            # and scope. Ancestry is irrelevant to its exact-target verification.
            gallery = LinkedGallery(target.gallery_id, None, target.title)
            generation = self._update_resume_generation
            self.linking_manager.start_photos_watcher(
                target.session, gallery, self._prepare_update_resume,
                lambda _message: self._update_resume_failed(
                    'saved_watcher_access_unavailable',
                ),
                still_requested=lambda: (
                    not type(self).watcher_reset_blocked(self)
                    and self._update_resume_generation == generation
                ),
            )
            return
        if not self.linking_manager.activate_verified_resume_target(
            target, record.binding, record.approved_scope,
        ):
            self._update_resume_failed('saved_gallery_mode_unverified')
            return
        self._prepare_update_resume(target)

    def _start_prepared_update_resume(
        self, record: WatcherContinuation, worker: WatcherThread,
        recorder: FlightRecorder, target: Optional[watcher_state.LinkedUploadTarget],
    ) -> None:
        """Adopt prepared idle workers after rechecking the exact continuation.

        Args:
            record (WatcherContinuation): Exact claimed update checkpoint.
            worker (WatcherThread): Prepared idle queue owner.
            recorder (FlightRecorder): Prepared idle recorder for the same plan.
            target (Optional[LinkedUploadTarget]): Fresh original linked
                authority, or None for the original saved API key.

        Returns:
            None: Starts the same plan or releases prepared ownership on failure.
        """
        valid = record == self._resume_continuation and not self.exiting
        if not valid:
            if worker._linked_lease is not None:
                worker._linked_lease.release()
            if target is getattr(self, '_update_resume_context_target', None):
                self._close_update_resume_context_key()
            return
        try:
            lease = self.flights.lease
            if lease is None or WatcherContinuation.load(lease) != record:
                raise ValueError('The claimed continuation changed.')
            if self.flights.binding() != record.binding:
                raise ValueError('The continuation target changed.')
            plan = SessionPlan.load(self.flights.directory / record.session_id)
            self.worker = worker
            self.flights.resume_session(plan, recorder=recorder)
            self.watched_folder_var.set(str(worker._watch_folder_override))
            self.watch_recursive_var.set(bool(worker._watch_recursive_override))
            worker.start()
            self.refresh_utc_sync()
            self.watcher_activity_var.set('Watch: resuming')
            self._update_resume_status = 'stage=resume; reason=waiting_for_worker'
            self.refresh_connection_controls()
        except Exception:
            self.flights.stop()
            worker.stop()
            if not worker.is_alive() and worker._linked_lease is not None:
                worker._linked_lease.release()
            self._update_resume_failed('prepared_session_changed')

    def _complete_update_resume(self) -> bool:
        """Consume the one-use checkpoint only when its worker reaches REC.

        Returns:
            bool: True for ordinary startup or a durably consumed continuation;
                False pauses an accepted worker whose checkpoint cannot be saved.
        """
        record = self._resume_continuation
        if record is None:
            return True
        lease = self.flights.lease
        if (self.worker is None or self.worker.stop_event.is_set()
                or self.worker.drain_event.is_set()
                or self.worker._update_pause_requested()):
            if self.worker is not None:
                self.worker.request_update_pause()
            self.flights.stop()
            reason = (
                'saved_session_needs_review'
                if self.worker is not None and self.worker.drain_error
                else 'continuation_worker_stopped'
            )
            self._update_resume_failed(reason)
            return False
        if record.contextual and (
            self._update_resume_context_target is None
            or self.worker.linked_target is not self._update_resume_context_target
            or self._update_resume_context_key_closing
        ):
            self.worker.request_update_pause()
            self.flights.stop()
            self._update_resume_failed('saved_watcher_access_unavailable')
            return False
        consumed = watcher_state.replace(record, phase='consumed')
        try:
            if lease is None or WatcherContinuation.load(lease) != record:
                raise ValueError('The continuation checkpoint changed.')
            if getattr(self, '_ordinary_restart_resuming', False) is True:
                ordinary = WatcherContinuation.load(
                    lease, ordinary_restart=True,
                )
                if (ordinary is None
                        or ordinary.phase not in ('prepared', 'claimed')
                        or watcher_state.replace(ordinary, phase=record.phase) != record):
                    raise ValueError('The ordinary restart consent changed.')
                consumed.save(lease, ordinary_restart=True)
            consumed.save(lease)
        except (OSError, ValueError, RuntimeError):
            if self.worker is not None:
                self.worker.request_update_pause()
            self.flights.stop()
            if getattr(self, '_ordinary_restart_resuming', False) is True:
                self._restore_unadmitted_continuation(record, consumed)
            self._update_resume_failed('continuation_consumption_unverified')
            return False
        if self.worker is None or not self.worker.admit_continuation():
            # The worker's gate has not opened, so restore the exact claimed
            # checkpoint. This makes a Stop or timeout during the durable save
            # retryable without risking a duplicate dispatch.
            if not self._restore_unadmitted_continuation(record, consumed):
                self.flights.stop()
                self._update_resume_failed('continuation_consumption_unverified')
                return False
            self.flights.stop()
            reason = (
                'saved_session_needs_review'
                if self.worker is not None and self.worker.drain_error
                else 'continuation_worker_stopped'
            )
            self._update_resume_failed(reason)
            return False
        self._resume_continuation = None
        self._ordinary_restart_resuming = False
        if record.contextual:
            self._contextual_watch_target = self._update_resume_context_target
            plan = getattr(self.flights, 'plan', None)
            self._contextual_watch_is_flight = bool(
                plan is not None and plan.reports_enabled
            )
            self._update_resume_context_target = None
            self._update_resume_context_key_closing = False
        self._update_resume_preparing = False
        self._resume_retry_available = False
        self._update_resume_status = 'stage=resume; reason=watcher_running'
        self.write_log('Watcher resumed using its original session.', 'success')
        return True

    def _restore_unadmitted_continuation(
        self, record: WatcherContinuation, consumed: WatcherContinuation,
    ) -> bool:
        """Reconcile an owned checkpoint pair before any resumed dispatch.

        Args:
            record (WatcherContinuation): Exact claimed state before consumption.
            consumed (WatcherContinuation): Same owned state marked consumed.
        Returns:
            bool: True after the unchanged records are verified as claimed;
                False for changed, cancelled or unreadable state. Callers keep
                the worker gate closed, so this never reverses an admitted run.
        """
        try:
            lease = self.flights.lease
            if lease is None:
                return False
            lease.verify()
            current = WatcherContinuation.load(lease)
            if current not in (record, consumed):
                return False
            ordinary = None
            if getattr(self, '_ordinary_restart_resuming', False) is True:
                ordinary = WatcherContinuation.load(lease, ordinary_restart=True)
                if ordinary not in (record, consumed):
                    return False
            # A failed readback may follow an already-committed local replace.
            # Restore only exact snapshots owned by this still-closed gate.
            if current != record:
                record.save(lease)
            if ordinary is not None and ordinary != record:
                record.save(lease, ordinary_restart=True)
            return True
        except (OSError, ValueError, RuntimeError):
            return False

    def prepare_update_checkpoint(self, target_version: str) -> None:
        """Capture active Watcher intent before requesting a non-destructive pause.

        Args:
            target_version (str): Verified installer version selected for update.

        Returns:
            None: Requests a pause after the current upload acknowledgement.
                An active verified API-key or linked capture keeps its running
                intent. A stopped Watcher acquires no continuation.

        Raises:
            RuntimeError: Another accepted operation must finish first.
            ValueError: The active session or mode cannot be verified.
            OSError: A durable checkpoint cannot be prepared.
        """
        if type(self).watcher_reset_blocked(self):
            raise RuntimeError('The Watcher factory reset is pending.')
        if getattr(self, '_ordinary_restart_closing', False) is True:
            raise RuntimeError('The Watcher is already preparing to close.')
        if (self.flights.busy() or self.api_key_revoke_running
                or any(worker is not None and worker.is_alive() for worker in (
                    self.manual_worker, self.ai_worker,
                ))):
            raise RuntimeError('Finish the current accepted operation before updating.')
        self._update_resume_cancelled = False
        self._update_resume_generation += 1
        self._update_checkpoint_ready = False
        self._update_continuation = None
        self._update_context_target = None
        self._update_context_key_closed = False
        worker = self.worker
        if worker is not None and worker.is_alive():
            plan = self.flights.plan
            lease = self.flights.lease
            if (plan is None or lease is None or worker.drain_event.is_set()
                    or worker.stop_event.is_set() or worker.drain_error
                    or not worker.initial_captured):
                raise ValueError('The current Watcher must finish preparation or recovery first.')
            lease.verify()
            current = self.flights.pending()
            if (len(current) != 1 or current[0].directory != plan.directory
                    or current[0].binding != plan.binding):
                raise ValueError('The current Watcher session could not be verified.')
            mode = self.mode_owner.view()
            kind = 'linked' if worker.linked_target is not None else 'legacy'
            if ((kind == 'legacy' and mode.state not in LEGACY_UPLOAD_STATES)
                    or (kind == 'linked' and mode.state != 'linked_active')):
                raise ValueError('The current Watcher mode could not be verified.')
            contextual = bool(worker.linked_target is not None
                              and worker.linked_target.watch_key is not None)
            record = WatcherContinuation(
                plan.session_id, plan.binding,
                WatcherContinuation.profile_digest(self.flights.directory),
                runtime.APP_VERSION, target_version, '', kind, contextual,
                watcher_state.time.time(), 'preparing',
                approved_scope=mode.record.scope if kind == 'linked' and mode.record else '',
            )
            record.save(lease)
            self._update_continuation = record
            self._update_context_target = worker.linked_target if contextual else None
            worker.request_update_pause()
            self._set_watcher_recording(False)
            self.status_var.set(gettext('Finishing the current upload before updating…'))
        self.flights.stop()
        self.utc_sync.stop()
        if self.preflight_worker is not None and self.preflight_worker.is_alive():
            self.preflight_worker.stop()
        self._update_checkpoint_workers = (
            self.worker, self.flights, self.utc_sync, self.preflight_worker,
        )
        self._update_resume_status = 'stage=checkpoint; reason=waiting_for_acknowledged_work'

    def wait_for_update_checkpoint(self) -> None:
        """Wait off Tk for all captured writers before allowing installation.

        Returns:
            None: Releases workers and confirms durable exact-session state.

        Raises:
            TimeoutError: A current request or native worker has not settled.
            ValueError: A queue, target or credential remains unresolved.
            OSError: Accepted state cannot be verified on disk.
        """
        deadline = watcher_state.time.monotonic() + UPDATE_CHECKPOINT_TIMEOUT_SECONDS
        for worker in self._update_checkpoint_workers:
            if worker is not None and worker.is_alive():
                worker.join(timeout=max(0.0, deadline - watcher_state.time.monotonic()))
        if any(worker is not None and worker.is_alive()
               for worker in self._update_checkpoint_workers):
            self._update_resume_status = 'stage=checkpoint; reason=workers_still_running'
            raise TimeoutError('Current work is still finishing; update postponed.')
        record = self._update_continuation
        if record is not None:
            lease = self.flights.lease
            if lease is None:
                raise ValueError('The Watcher workspace owner is unavailable.')
            lease.verify()
            plan = SessionPlan.load(self.flights.directory / record.session_id)
            row = read_document(plan.directory / 'watch-drain.json')
            if (plan.completed or plan.abandoned or plan.binding != record.binding
                    or row.get('frozen') is not False or row.get('cutoff') != 0
                    or row.get('error') or self.worker is None
                    or self.worker.drain_error):
                self._update_resume_status = 'stage=checkpoint; reason=queue_needs_review'
                raise ValueError('The Watcher queue needs review before updating.')
            if (self.worker.linked_target is not None
                    and self.worker.state.has_unresolved_upload()):
                self._update_resume_status = 'stage=checkpoint; reason=upload_unconfirmed'
                raise ValueError('An upload is unconfirmed; update postponed.')
            target = self._update_context_target
            if target is not None and target.watch_key is not None:
                try:
                    target.session.close_watch_key(target.gallery_id, target.watch_key)
                except Exception:
                    self._update_resume_status = 'stage=checkpoint; reason=watch_access_unconfirmed'
                    raise ValueError('Temporary Watcher access could not be closed.') from None
                self._update_context_key_closed = True
            # Reobserve the final persisted queue only after its writer ended.
            record.save(lease)
        self._update_checkpoint_ready = True
        self._update_resume_status = 'stage=checkpoint; reason=saved'

    def bind_update_checkpoint(self, ticket: watcher_state.Path) -> None:
        """Bind safe paused work to the exact independently staged helper.

        Args:
            ticket (Path): Private ticket created and validated by the helper owner.

        Returns:
            None: Publishes the nonce before the helper's proceed marker.

        Raises:
            ValueError: The checkpoint, helper or selected version changed.
            OSError: The verified checkpoint cannot be persisted.
        """

        if not self._update_checkpoint_ready:
            raise ValueError('The update checkpoint is not ready.')
        selected = update_helper.load_ticket(ticket)
        record = self._update_continuation
        update_helper.bind_capture_resume(ticket, record is not None)
        if record is not None:
            lease = self.flights.lease
            if lease is None or selected.target_version != record.target_version:
                raise ValueError('The update checkpoint target changed.')
            record = watcher_state.replace(
                record, update_session=selected.session_id, phase='prepared',
            )
            record.save(lease)
            self._update_continuation = record
        self._update_resume_status = 'stage=checkpoint; reason=handoff_bound'

    def cancel_update_checkpoint(self) -> None:
        """Retain credentials and resume the same paused work after handoff failure.

        Returns:
            None: Schedules a verified continuation after its writers settle;
                unresolved work keeps an actionable reason instead of abandonment.
        """
        record = getattr(self, '_update_continuation', None)
        if record is None:
            return
        if not record.update_session:
            # This is a local cancellation before helper creation; the nonce
            # identifies only this owned continuation, never authentication.
            record = watcher_state.replace(record, update_session=watcher_state.os.urandom(16).hex())
        self._resume_continuation = record
        self._update_resume_deadline = (
            watcher_state.time.monotonic() + UPDATE_CHECKPOINT_TIMEOUT_SECONDS
        )
        self._update_resume_status = 'stage=resume; reason=handoff_cancelled'
        self._continue_update_resume()

    def _discard_update_resume_intent(self, *, explicit: bool = False) -> None:
        """Persist an explicit Stop/Quit without deleting unfinished work.

        Args:
            explicit (bool): Whether Stop, unlink or true exit explicitly
                revokes an unclaimed startup nonce.

        Returns:
            None: Cancels only automatic continuation; photos, telemetry,
                credentials and accepted finalization remain with their owners.
        """
        owned = (
            getattr(self, '_resume_continuation', None)
            or getattr(self, '_update_continuation', None)
        )
        offered_retry = getattr(self, '_resume_retry_available', False) is True
        requested_nonce = getattr(self, '_after_update_nonce', None)
        self._resume_continuation = None
        self._resume_retry_available = False
        self._close_update_resume_context_key()
        if owned is None and not offered_retry and not explicit:
            return
        try:
            lease = self.flights.lease
            if lease is None:
                return
            current = WatcherContinuation.load(lease)
            if (current is None
                    or current.phase not in ('preparing', 'prepared', 'claimed')):
                return
            if owned is not None:
                matches_owner = (
                    current.session_id == owned.session_id
                    and current.binding == owned.binding
                    and (not owned.update_session
                         or current.update_session == owned.update_session)
                )
            else:
                if requested_nonce is not None and current.update_session != requested_nonce:
                    return
                if (runtime.APP_VERSION not in (
                        current.source_version, current.target_version,
                )):
                    return
                mode_view = self.mode_owner.view()
                if current.mode == 'legacy':
                    expected_binding = connection_binding(
                        watcher_state.normalize_upload_url(self.config.gallery_url),
                        self.config.api_key,
                    )
                    matches_owner = (
                        mode_view.state in LEGACY_UPLOAD_STATES
                        and current.binding == expected_binding
                    )
                elif current.mode == 'linked':
                    mode_record = mode_view.record
                    matches_owner = (
                        mode_record is not None
                        and mode_record.mode == 'linked'
                        and mode_record.scope == current.approved_scope
                        and linked_target_binding(
                            mode_record.issuer, mode_record.account_id,
                            mode_record.gallery_id,
                        ) == current.binding
                    )
                else:
                    matches_owner = False
            if matches_owner:
                watcher_state.replace(
                    current, phase='cancelled',
                    update_session=current.update_session or watcher_state.os.urandom(16).hex(),
                ).save(lease)
        except (OSError, ValueError, RuntimeError):
            self.write_log(
                '[watcher-resume] stage=stop; reason=checkpoint_cancel_unverified',
                'warning',
            )
