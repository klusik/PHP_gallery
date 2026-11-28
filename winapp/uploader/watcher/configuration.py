# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/watcher/configuration.py
# Module Type: Windows Desktop Configuration Owner
# Purpose: Own saved and linked upload configuration for desktop backends.
# Responsibilities:
#   - Verify exact local and linked destinations before changing configuration.
#   - Persist user-approved connection and runtime settings through shared owners.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Own desktop configuration and linked destination lifecycle."""

from dataclasses import replace
import math
import threading

from uploader.config import (
    BYTES_PER_GIB,
    MAX_THUMBNAIL_WORKERS,
    MAX_UPLOAD_WORKERS,
)
from uploader.desktop_connection import (
    ConnectionActions,
    ConnectionTestResult,
    LinkedRecoveryActions,
    RuntimeSettings,
    RuntimeValues,
    open_saved_upload_session,
)
from uploader.flight_jobs import (
    ImportBindings,
    WorkspaceLease,
    connection_binding,
    pending_sessions,
)
from uploader.http_transport import normalize_upload_url, post_json_resolved
from uploader.linked_upload import (
    LinkedUploadTarget,
    linked_target_binding,
    verify_linked_upload_target,
)
from uploader.mode_admission import LEGACY_UPLOAD_STATES
from uploader.mode_coordinator import (
    CoordinatedWork,
    GuiModeOwner,
    ModeCoordinatorError,
)
from uploader.state_store import JobStateStore, accepted_work_owner
from uploader.upload_mode import ModeRecord
from uploader.watcher import state as watcher_state
from uploader.watcher.profile import WATCHER_PREFERENCE_TYPES, WatcherProfile
from uploader.watcher.state import (
    AI_DETECTION_RANGE,
    AI_WORKER_MAX_LEASE_SECONDS,
    AI_WORKER_MIN_LEASE_SECONDS,
    AI_WORKER_MIN_POLL_SECONDS,
    MIN_INTERVAL_SECONDS,
    MIN_INVENTORY_REFRESH_SECONDS,
    MIN_STABLE_SECONDS,
    STAGING_RETENTION_RANGE,
    ZIP_ENTRY_RANGE,
    ZIP_EXPANSION_RANGE,
    ZIP_FILE_RANGE,
    ZIP_TOTAL_RANGE,
    _ConfigModePort,
    capture_worker_config,
    probe_linked_upload_outcome,
    publish_linked_upload_observation,
    review_linked_upload_marker,
)
from uploader.windows_startup import synchronize_startup


class DesktopConfiguration:
    """Retain one saved profile and explicitly verified linked target in Qt."""

    def __init__(self, store: watcher_state.ConfigStore, config: watcher_state.WatcherConfig, mode: GuiModeOwner) -> None:
        """Bind the frontend to the existing local and credential owners.

        Args:
            store (ConfigStore): Current profile's exact configuration owner.
            config (WatcherConfig): Last observed configuration and generation.
            mode (GuiModeOwner): Verified local selection and writer admission.
        Returns:
            None: Creates no new persistent owner and starts no work.
        """
        self.store = store
        self.config = config
        self.mode = mode
        self.target: LinkedUploadTarget | None = None
        self._target_lock = threading.RLock()
        self.connections = ConnectionActions(
            self.select, self.legacy, self.saved, self.active,
            self.test_legacy_connection, self.commit_scope_upgrade,
            recovery=LinkedRecoveryActions(
                self.probe_linked_recovery, self.linked_recovery_is_current,
                self.review_linked_recovery,
            ),
        )
        self.settings = RuntimeSettings(self.values, self.save_values)

    def current(self) -> watcher_state.WatcherConfig:
        """Return the latest accepted config for worker snapshot construction.

        Returns:
            WatcherConfig: Current configuration owned by the desktop.
        """
        return self.config

    def saved(self) -> ModeRecord | None:
        """Read the persisted selection without attempting browser authorization.

        Returns:
            ModeRecord | None: Non-secret exact saved upload selection.
        """
        with self._target_lock:
            return self.mode.view().record

    def active(self) -> LinkedUploadTarget | None:
        """Return a live grant only when it matches the verified saved mode.

        Returns:
            LinkedUploadTarget | None: The active scoped target, or no authority.
        """
        with self._target_lock:
            target = self.target
            view = self.mode.view()
            record = view.record
            if (target is not None and view.state == 'linked_active' and record is not None
                    and record.issuer == target.issuer and record.account_id == target.user_id
                    and record.gallery_id == target.gallery_id
                    and record.scope == target.session.approved_scope()
                    and target.session.available()
                    and target.session.matches_issuer(target.issuer)
                    and target.session.can_upload()):
                return target
            return None

    def probe_linked_recovery(self, target: LinkedUploadTarget, size: int) -> tuple[str, str]:
        """Run one observation while the exact target owns upload permission.

        Args:
            target (LinkedUploadTarget): Current verified linked upload target.
            size (int): Operator-supplied size of the original source in bytes.
        Returns:
            tuple[str, str]: Server observation and exact local marker digest.
        Raises:
            ValueError: The upload target or retained marker changed.
            OSError: The target's recovery evidence cannot be read safely.
            RuntimeError: The mode, target lease or observation was unavailable.
        """
        if self.active() is not target:
            raise ValueError('Linked recovery target is no longer selected.')
        result = probe_linked_upload_outcome(target, size)
        if self.active() is not target:
            raise ValueError('Linked recovery target changed during observation.')
        return result

    def linked_recovery_is_current(self, target: LinkedUploadTarget, marker: str) -> bool:
        """Check observation currency before displaying it in the linked picker.

        Args:
            target (LinkedUploadTarget): Exact gallery and Admin observed.
            marker (str): Original locally observed marker digest.
        Returns:
            bool: True only for the same live target and journal entry.
        """
        try:
            if self.active() is not target:
                return False
        except ModeCoordinatorError:
            return False
        return publish_linked_upload_observation(target, marker, self.active)

    def review_linked_recovery(self, target: LinkedUploadTarget, marker: str | None) -> str | None:
        """Read or acknowledge a retained marker while the selection is stable.

        Args:
            target (LinkedUploadTarget): Exact verified linked upload target.
            marker (str | None): None to inspect or the explicitly reviewed digest.
        Returns:
            str | None: Verified marker or None if the profile no longer owns it.
        """
        with self._target_lock:
            try:
                if self.active() is not target:
                    return None
            except ModeCoordinatorError:
                return None
            return review_linked_upload_marker(target, marker, self.active)

    def _verify_target_change(self, binding: str) -> None:
        """Verify accepted local work before changing its mode or credentials.

        Args:
            binding (str): Exact proposed non-secret destination fingerprint.
        Returns:
            None: No accepted capture or Import will be orphaned.
        """
        root = watcher_state.CONFIG_DIR / 'flights'
        current = self.saved()
        old_key = connection_binding(normalize_upload_url(self.config.gallery_url), self.config.api_key)
        if current is not None and current.mode == 'linked':
            assert current.issuer is not None and current.account_id is not None and current.gallery_id is not None
            old_binding = linked_target_binding(current.issuer, current.account_id, current.gallery_id)
        else:
            old_binding = old_key
        with accepted_work_owner(watcher_state.CONFIG_DIR):
            if ((binding != old_binding and pending_sessions(root, old_binding))
                    or not JobStateStore(watcher_state.STATE_PATH).admitted_imports_match_target(
                        ImportBindings(root).entries(), binding,
                    )):
                raise ValueError('Finish or explicitly resolve current work before replacing its gallery connection.')

    def _persist(self, proposed: watcher_state.WatcherConfig) -> None:
        """Publish the current form through the shared configuration-mode transaction.

        Args:
            proposed (WatcherConfig): Validated replacement with the original generation.
        Returns:
            None: The new config replaces the desktop reference after readback.
        """
        first = self.mode.view().state == 'first_setup'
        if not first and proposed.to_dict() == self.config.to_dict():
            return

        def save() -> _ConfigModePort:
            """Perform the single config write authorized by the mode owner.

            Returns:
                _ConfigModePort: Exact port for the newly published settings.
            """
            self.store.save(proposed)
            return self.store.mode_port(proposed)

        self.mode.save(save, first_setup=first)
        self.config = proposed

    def save_watcher_preference(self, proposed: watcher_state.WatcherConfig) -> watcher_state.WatcherConfig:
        """Persist a narrow Watcher preference through the shared mode owner.

        Args:
            proposed (WatcherConfig): Complete saved profile with one preference changed.
        Returns:
            WatcherConfig: Current configuration after the owner accepted it.
        """
        self._persist(proposed)
        return self.config

    def persist_revoked_config(
        self, proposed: watcher_state.WatcherConfig, work: CoordinatedWork,
    ) -> watcher_state.WatcherConfig:
        """Exchange the exact confirmed revoke ticket for one atomic key cleanup.

        Args:
            proposed (WatcherConfig): Complete profile with the captured key removed.
            work (CoordinatedWork): Exact server-confirmed legacy_revoke ticket.
        Returns:
            WatcherConfig: Persisted profile with its refreshed generation token.
        Raises:
            ValueError: The captured connection or selected mode became stale.
            OSError: The exact ConfigStore snapshot cannot be published.
        """
        current = self.config
        if (proposed._snapshot_token is not current._snapshot_token
                or proposed.to_dict() != replace(current, api_key='').to_dict()
                or work.kind != 'legacy_revoke'
                or not current.api_key.strip()
                or not normalize_upload_url(current.gallery_url)
                or self.mode.view().state not in LEGACY_UPLOAD_STATES):
            raise ValueError('The saved API-key connection changed during revocation.')
        work.checkpoint()

        def save() -> _ConfigModePort:
            """Persist through the existing ConfigStore owner.

            Returns:
                _ConfigModePort: Exact generation selected by the atomic write.
            """
            self.store.save(proposed)
            return self.store.mode_port(proposed)

        self.mode.save(save, completed_work=work)
        self.config = proposed
        self.target = None
        return proposed

    def persist_abandoned_config(
        self, proposed: watcher_state.WatcherConfig, binding: str,
    ) -> watcher_state.WatcherConfig:
        """Remove only the exact key after explicit local flight abandonment.

        Args:
            proposed (WatcherConfig): Complete profile with its key removed.
            binding (str): Non-secret fingerprint of the captured URL and key.
        Returns:
            WatcherConfig: Persisted profile with a refreshed generation token.
        Raises:
            ValueError: Saved mode, exact key or config generation changed.
            OSError: The exact ConfigStore snapshot cannot be published.
        """
        current = self.config
        current_binding = connection_binding(
            normalize_upload_url(current.gallery_url), current.api_key.strip(),
        )
        if (proposed._snapshot_token is not current._snapshot_token
                or proposed.to_dict() != replace(current, api_key='').to_dict()
                or binding != current_binding
                or self.mode.view().state not in LEGACY_UPLOAD_STATES):
            raise ValueError('The saved API-key connection changed during abandonment.')

        def save() -> _ConfigModePort:
            """Persist through the existing ConfigStore owner.

            Returns:
                _ConfigModePort: Exact generation selected by the atomic write.
            """
            self.store.save(proposed)
            return self.store.mode_port(proposed)

        self.mode.save(save)
        self.config = proposed
        self.target = None
        return proposed

    def select(self, target: LinkedUploadTarget) -> None:
        """Publish an explicitly selected server-verified exact target.

        Args:
            target (LinkedUploadTarget): Fresh verified gallery and browser grant.
        Returns:
            None: Admits the selected target for later explicit upload commands.
        """
        with self._target_lock:
            self._verify_target_change(target.binding())
            record = self.saved()
            if (record is not None and record.mode == 'linked'
                    and record.issuer == target.issuer and record.account_id == target.user_id
                    and record.gallery_id == target.gallery_id
                    and pending_sessions(watcher_state.CONFIG_DIR / 'flights', target.binding())
                    and record.scope != target.session.approved_scope()):
                raise ValueError('Reconnect with the original Watcher upload permissions.')
            if self.mode.view().state == 'first_setup':
                self._persist(self.config)
            self.mode.select_linked(target)
            self.target = target
            self._save_watcher_binding(target.binding())

    def commit_scope_upgrade(
        self, expected_mode: ModeRecord, expected_target: LinkedUploadTarget,
        target: LinkedUploadTarget,
    ) -> bool:
        """Commit Admin scope for the exact already selected linked gallery.

        Args:
            expected_mode (ModeRecord): Exact saved linked mode before approval.
            expected_target (LinkedUploadTarget): Same active target captured earlier.
            target (LinkedUploadTarget): Freshly verified grant for that target.
        Returns:
            bool: True only when the owner publishes the new scope and live target.
        """
        with self._target_lock:
            try:
                view = self.mode.view()
                if (self.target is not expected_target
                        or view.state != 'linked_active'
                        or view.record != expected_mode
                        or self.active() is not expected_target
                        or expected_mode.mode != 'linked'
                        or (expected_mode.issuer, expected_mode.account_id,
                            expected_mode.gallery_id)
                        != (target.issuer, target.user_id, target.gallery_id)
                        or (expected_target.issuer, expected_target.user_id,
                            expected_target.gallery_id)
                        != (target.issuer, target.user_id, target.gallery_id)
                        or not target.session.available()
                        or not target.session.matches_issuer(target.issuer)
                        or not target.session.can_upload()):
                    return False
                if (expected_mode.scope != target.session.approved_scope()
                        and pending_sessions(
                            watcher_state.CONFIG_DIR / 'flights', target.binding(),
                        )):
                    return False
                self._verify_target_change(target.binding())
                if self.mode.view() is not view:
                    return False
                self.mode.upgrade_linked_scope(target, expected=view)
                self.target = target
                return True
            except Exception:
                return False

    def legacy(self) -> None:
        """Restore the saved API key only after an explicit operator request.

        Returns:
            None: The platform mode owner selected the original legacy key.
        """
        with self._target_lock:
            if not normalize_upload_url(self.config.gallery_url) or not self.config.api_key.strip():
                raise ValueError('Gallery URL and API key are required before starting the watcher.')
            binding = connection_binding(normalize_upload_url(self.config.gallery_url), self.config.api_key)
            self._verify_target_change(binding)
            self.mode.select_legacy()
            self.target = None
            self._save_watcher_binding(binding)

    def test_legacy_connection(self, cancelled: threading.Event) -> ConnectionTestResult:
        """Check the saved API-key endpoint through exact legacy-mode admission.

        Args:
            cancelled (threading.Event): Current Qt read operation lifetime.
        Returns:
            ConnectionTestResult: Non-secret gallery labels from empty inventory.
        Raises:
            RuntimeError: The operation was cancelled before or during admission.
            ValueError: The saved endpoint, API key or target change is unavailable.
            OSError: The request could not complete at the transport layer.
            ModeCoordinatorError: Current mode evidence no longer authorizes the read.
            GalleryKeyRedirectError: The endpoint attempted an unsafe credential redirect.
        """
        if cancelled.is_set():
            raise RuntimeError('Connection test cancelled.')
        if self.mode.view().state in ('linked_active', 'linked_disconnected'):
            raise ModeCoordinatorError(
                'Select the API-key connection explicitly before testing.',
            )
        captured = capture_worker_config(self.config)
        config_port = self.store.mode_port(captured)
        upload_url = normalize_upload_url(captured.gallery_url)
        api_key = captured.api_key.strip()
        if not upload_url or not api_key:
            raise ValueError('Gallery URL and API key are required.')
        with self.mode.acquire('legacy', config_port) as work:
            if cancelled.is_set():
                raise RuntimeError('Connection test cancelled.')
            payload, _resolved_url = post_json_resolved(
                upload_url, api_key,
                {'action': 'inventory', 'files': [], 'deep_check': False},
                timeout_seconds=20.0, before_request=work.checkpoint,
            )
        if cancelled.is_set():
            raise RuntimeError('Connection test cancelled.')
        label = str(payload.get('gallery_folder', '') or payload.get('gallery', '') or 'gallery').strip()
        title = str(payload.get('gallery_title', '') or '').strip()
        return ConnectionTestResult(label, title)

    def _save_watcher_binding(self, binding: str) -> None:
        """Admit an explicitly selected destination to the reset Watcher profile.

        Args:
            binding (str): Verified target fingerprint selected by the operator.
        Returns:
            None: The dedicated Watcher owner retains its own preferences.
        """
        root = watcher_state.CONFIG_DIR / 'flights'
        profile = WatcherProfile.load(root)
        if profile is None or profile.pending or profile.binding == binding:
            return
        lease = WorkspaceLease(root)
        try:
            if WatcherProfile.load(root) != profile:
                raise ValueError('The saved Watcher profile changed.')
            replace(profile, binding=binding).save(lease)
        finally:
            lease.release()

    def restore_capture_access(self) -> None:
        """Restore exactly the same previously authorized native linked grant.

        Returns:
            None: Selects the verified unchanged target without starting a transfer.
        """
        record = self.saved()
        if (record is None or record.mode != 'linked' or record.issuer is None
                or record.account_id is None or record.gallery_id is None or not record.scope):
            raise ValueError('Reconnect the selected linked gallery.')
        cancelled = threading.Event()
        session = open_saved_upload_session(watcher_state.CONFIG_DIR, record, cancelled)
        try:
            target = verify_linked_upload_target(
                session, record.issuer, record.account_id, record.scope, record.gallery_id, cancelled,
            )
            self.select(target)
        except Exception:
            session.close()
            raise

    def values(self) -> RuntimeValues:
        """Read settings from the same persistent owners used by capture.

        Returns:
            RuntimeValues: Current typed values including dedicated Watcher choices.
        """
        values = self.config.to_dict()
        profile = WatcherProfile.load(watcher_state.CONFIG_DIR / 'flights')
        if profile is not None:
            values.update(profile.preferences)
        return values

    def save_values(self, values: RuntimeValues) -> RuntimeValues:
        """Validate a native form and save through the current profile owners.

        Args:
            values (RuntimeValues): Changed typed values from the current form.
        Returns:
            RuntimeValues: Read back shared and dedicated Watcher settings.
        """
        root = watcher_state.CONFIG_DIR / 'flights'
        profile = WatcherProfile.load(root)
        current = self.values()
        for key, value in values.items():
            expected = current.get(key)
            if expected is None or type(value) is not type(expected):
                raise ValueError('Configuration selection is stale.')
            if isinstance(value, float) and not math.isfinite(value):
                raise ValueError('Timing, worker, archive-safety, and AI numeric settings must contain valid numbers.')
        ranges = {
            'manual_thumbnail_workers': (0, MAX_THUMBNAIL_WORKERS),
            'manual_upload_workers': (0, MAX_UPLOAD_WORKERS),
            'archive_max_entries': ZIP_ENTRY_RANGE,
            'archive_max_uncompressed_bytes': tuple(int(gib * BYTES_PER_GIB) for gib in ZIP_TOTAL_RANGE),
            'archive_max_file_bytes': tuple(int(gib * BYTES_PER_GIB) for gib in ZIP_FILE_RANGE),
            'archive_max_compression_ratio': ZIP_EXPANSION_RANGE,
            'staging_cleanup_age_hours': STAGING_RETENTION_RANGE,
            'ai_worker_lease_seconds': (AI_WORKER_MIN_LEASE_SECONDS, AI_WORKER_MAX_LEASE_SECONDS),
            'ai_transformers_detection_threshold': AI_DETECTION_RANGE,
        }
        current.update({
            key: max(ranges[key][0], min(ranges[key][1], value)) if key in ranges else value
            for key, value in values.items()
        })
        proposed = watcher_state.WatcherConfig.from_dict(current)
        if proposed.gallery_url.strip():
            proposed.gallery_url = normalize_upload_url(proposed.gallery_url)
            if not proposed.gallery_url:
                raise ValueError('Gallery URL must use HTTP or HTTPS and contain a valid host.')
        proposed.api_key = proposed.api_key.strip()
        proposed.scan_interval_seconds = max(MIN_INTERVAL_SECONDS, proposed.scan_interval_seconds)
        proposed.stable_seconds = max(MIN_STABLE_SECONDS, proposed.stable_seconds)
        proposed.ai_worker_poll_seconds = max(AI_WORKER_MIN_POLL_SECONDS, proposed.ai_worker_poll_seconds)
        proposed.inventory_refresh_seconds = max(MIN_INVENTORY_REFRESH_SECONDS, proposed.inventory_refresh_seconds)
        if (proposed.gallery_url, proposed.api_key) != (self.config.gallery_url, self.config.api_key):
            self._verify_target_change(connection_binding(proposed.gallery_url, proposed.api_key))
        proposed._snapshot_token = self.config._snapshot_token
        if profile is None:
            self._persist(proposed)
        else:
            captured = proposed.to_dict()
            preferences = {key: captured[key] for key in WATCHER_PREFERENCE_TYPES}
            self._persist(replace(proposed, **{
                key: getattr(self.config, key) for key in WATCHER_PREFERENCE_TYPES
            }))
            if preferences != profile.preferences:
                lease = WorkspaceLease(root)
                try:
                    replace(profile, preferences=preferences).save(lease)
                finally:
                    lease.release()
        if 'start_with_windows' in values:
            synchronize_startup(self.config.start_with_windows)
        return self.values()
