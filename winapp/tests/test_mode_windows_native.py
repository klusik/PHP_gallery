# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/tests/test_mode_windows_native.py
# Module Type: Windows Native Recovery Regression
# Purpose: Qualify selected real Windows mode-vault and process-crash boundaries.
# Responsibilities:
#   - Check interrupted vault-first mode publication against real config storage.
#   - Verify named metadata mutex and target leases across separate processes.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Disposable Windows-only native smoke; not a power-loss or mixed-version proof."""

import base64
from contextlib import redirect_stderr, redirect_stdout
import io
import secrets
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path
from types import TracebackType
from unittest import mock

from test_redesign import MAIN
from uploader import credential_store
from uploader.app_linking import (
    LinkedGallery, LinkedGalleryPage, LinkingError, LinkingSession,
    READ_UPLOAD_CREATE_SCOPE, UPLOAD_SCOPE,
)
from uploader.config_snapshot import ConfigSnapshot, ConfigSnapshotStore
from uploader.flight_jobs import ImportBindings
from uploader.linked_create import BoundCreateReceipt, GalleryCreateJournal
from uploader.linked_upload import (
    LinkedTargetLease, LinkedUploadTarget, verify_linked_upload_target,
)
from uploader.mode_coordinator import GuiModeOwner, ModeCoordinatorError
from uploader.windows_startup import WindowsInstance
from uploader.windows_file_identity import assert_local_windows_profile
from uploader.upload_mode import (
    ModeRecord, UploadModeError, encode_mode_record, profile_path_digest,
    resolve_mode,
)


# Purpose: Bound each independent child process, including lock contention.
# Type: int. Units: seconds. Scope: one disposable native Windows fixture.
# Consumers: NativeWindowsModeTests._run_child and native target tests.
# Rationale: A stalled child must not hang the full Python unittest owner.
NATIVE_CHILD_TIMEOUT_SECONDS = 12

# Purpose: Probe native cross-process contention without the production 10s wait.
# Type: int. Units: milliseconds. Scope: one isolated child lock probe.
# Consumers: _NATIVE_CHILD_CODE. Rationale: 200ms distinguishes contention while
# retaining the production timeout and lock policy unchanged.
NATIVE_MUTEX_PROBE_WAIT_MS = 200

# Each child runs the same repository source under an independent Windows PID.
# No child has access to a gallery, network credentials or actual user files.
_NATIVE_CHILD_CODE = """
import base64
import os
import sys
from pathlib import Path
from uploader import credential_store
from uploader.credential_store import (
    CredentialStoreError, WindowsUploadModeStore, _WindowsCredentialMutex,
)
from uploader.linked_upload import LinkedTargetLease
from uploader.windows_startup import WindowsInstance
from uploader.upload_mode import decode_mode_record

kind = sys.argv[1]
if kind == 'vault-crash':
    record = decode_mode_record(base64.b64decode(sys.argv[2]))
    with WindowsUploadModeStore().transaction() as transaction:
        transaction.write(record)
    os._exit(0)
elif kind == 'mutex-probe':
    credential_store._MUTEX_WAIT_MS = int(sys.argv[2])
    try:
        with _WindowsCredentialMutex(None, None, metadata=True):
            print('acquired', flush=True)
    except CredentialStoreError as error:
        if str(error) != 'Credential mutex acquisition failed.':
            raise
        print('busy', flush=True)
elif kind == 'desktop-instance':
    owner = WindowsInstance(Path(sys.argv[2]))
    try:
        print('acquired' if owner.acquire() else 'busy', flush=True)
    finally:
        owner.close()
elif kind in ('lease-probe', 'lease-crash'):
    lease = LinkedTargetLease(Path(sys.argv[2]), sys.argv[3])
    try:
        lease.acquire()
    except Exception as error:
        if str(error).startswith('Linked gallery is busy or its local lock is unavailable'):
            print('busy', flush=True)
        else:
            raise
    else:
        if kind == 'lease-crash':
            os._exit(0)
        print('acquired', flush=True)
        lease.release()
else:
    raise SystemExit('Unknown native probe')
"""



class _NativeConfigPort:
    """Expose real disposable config snapshots to headless GUI mode admission."""

    def __init__(self, path: Path) -> None:
        """Create one persistent snapshot owner for the isolated native test.

        Args:
            path (Path): Full temporary configuration file path.

        Returns:
            None: Retains this configuration without claiming a process mutex.
        """
        self.config_path = str(path)
        self._store = ConfigSnapshotStore(path, frozenset({'api_key'}))

    def mode_transaction(self) -> '_NativeConfigPort':
        """Return the test context; this does not fence external processes.

        Returns:
            _NativeConfigPort: The same snapshot adapter.
        """
        return self

    def __enter__(self) -> '_NativeConfigPort':
        """Enter the disposable configuration test context.

        Returns:
            _NativeConfigPort: This adapter.
        """
        return self

    def __exit__(
        self, exc_type: type[BaseException] | None,
        exc_value: BaseException | None, traceback: TracebackType | None,
    ) -> bool:
        """Propagate transaction failures.

        Args:
            exc_type (type[BaseException] | None): Optional failure type.
            exc_value (BaseException | None): Optional failure instance.
            traceback (TracebackType | None): Optional traceback.

        Returns:
            bool: False, preserving a caller failure.
        """
        return False

    def snapshot(self) -> ConfigSnapshot:
        """Read the complete real filesystem observation.

        Returns:
            ConfigSnapshot: New validated snapshot or an unavailable state.
        """
        return self._store.inspect()

    def read_mode_anchor(self, snapshot: ConfigSnapshot) -> bytes | None:
        """Read the exact mode field from the validated complete snapshot.

        Args:
            snapshot (ConfigSnapshot): Current immutable file evidence.

        Returns:
            bytes | None: Canonical anchor or confirmed absence.
        """
        return self._store.read_mode_anchor(snapshot)

    def write_mode_anchor(self, snapshot: ConfigSnapshot, payload: bytes) -> ConfigSnapshot:
        """Publish the exact mode field through the real snapshot writer.

        Args:
            snapshot (ConfigSnapshot): Pre-publication file evidence.
            payload (bytes): Canonical non-secret mode record.

        Returns:
            ConfigSnapshot: Read-back verified file evidence.
        """
        return self._store.save_mode_anchor(snapshot, payload)


@unittest.skipUnless(sys.platform == 'win32', 'Native mode recovery requires Windows')
class NativeWindowsModeTests(unittest.TestCase):
    """Exercise real Windows mode persistence on a disposable isolated profile."""

    def setUp(self) -> None:
        """Allocate an unrelated config file and refuse an occupied mode vault.

        Returns:
            None: Prepares a non-secret unique test generation and cleanup.
        """
        temporary = tempfile.TemporaryDirectory(prefix='phpgallery-mode-native-')
        self.addCleanup(temporary.cleanup)
        self.directory = Path(temporary.name)
        self.config_path = self.directory / 'config.json'
        self.config_path.write_text('{"api_key":"test-only"}', encoding='utf-8')
        self.record = ModeRecord(
            secrets.token_hex(16), secrets.token_hex(16), 'linked',
            profile_path_digest(str(self.config_path)),
            'https://gallery.example', 7, UPLOAD_SCOPE, 42,
        )
        self._owned_records = {self.record}
        self.vault = credential_store.WindowsUploadModeStore()
        with self.vault.transaction() as transaction:
            if transaction.read() is not None:
                self.skipTest('Native fixed mode vault has existing state; not overwriting it.')
        self.addCleanup(self._remove_owned_record)

    def _remove_owned_record(self) -> None:
        """Delete only the disposable exact record, never another owner's data.

        Returns:
            None: Refuses to remove a different or unverifiable vault record.
        """
        with self.vault.transaction() as transaction:
            if transaction.read() in self._owned_records:
                self.vault._api.delete(credential_store._UPLOAD_MODE_TARGET)

    def _run_child(self, kind: str, *arguments: str) -> subprocess.CompletedProcess[str]:
        """Execute one isolated subprocess using the checked-out application.

        Args:
            kind (str): One explicitly supported native worker mode.
            *arguments (str): Exact command arguments for that mode.

        Returns:
            subprocess.CompletedProcess[str]: Child exit status and captured output.
        """
        return subprocess.run(
            [sys.executable, '-c', _NATIVE_CHILD_CODE, kind, *arguments],
            cwd=Path(__file__).resolve().parents[1], capture_output=True,
            text=True, timeout=NATIVE_CHILD_TIMEOUT_SECONDS, check=False,
        )

    def test_crash_after_vault_write_blocks_key_fallback_then_requires_reconnect(self) -> None:
        """Observe an abrupt child exit between real vault and config publication.

        Returns:
            None: One-sided evidence blocks legacy; matching evidence stays disconnected.
        """
        child = self._run_child(
            'vault-crash', base64.b64encode(encode_mode_record(self.record)).decode('ascii'),
        )
        self.assertEqual(0, child.returncode, child.stderr)
        config = ConfigSnapshotStore(self.config_path, frozenset({'api_key'}))
        snapshot = config.inspect()
        self.assertEqual('valid', snapshot.state)
        self.assertIsNone(config.read_mode_anchor(snapshot))
        with self.vault.transaction() as transaction:
            actual = transaction.read()
        self.assertEqual(self.record, actual)
        with self.assertRaises(UploadModeError):
            resolve_mode(
                None, encode_mode_record(actual), config_verified=True,
                config_path=str(self.config_path),
            )

        # Simulate a completed second publication step and a new process owner.
        config.save_mode_anchor(snapshot, encode_mode_record(self.record))
        restarted = ConfigSnapshotStore(self.config_path, frozenset({'api_key'}))
        anchor = restarted.read_mode_anchor(restarted.inspect())
        with self.vault.transaction() as transaction:
            vault_record = transaction.read()
        self.assertEqual(
            ('linked_disconnected', self.record),
            resolve_mode(
                anchor, encode_mode_record(vault_record), config_verified=True,
                config_path=str(self.config_path),
            ),
        )

    def test_another_profile_cannot_infer_legacy_from_fixed_vault_namespace(self) -> None:
        """Show one Windows SID's vault record blocks a different config profile.

        This verifies conservative refusal, not safe multi-profile operation.
        No credential is overwritten, copied or deleted during the check.

        Returns:
            None: The other profile cannot use its API key or modify the record.
        """
        with self.vault.transaction() as transaction:
            transaction.write(self.record)
        other_path = self.directory / 'other-profile' / 'config.json'
        other_path.parent.mkdir()
        other_path.write_text('{"api_key":"disposable-other-profile"}', encoding='utf-8')
        other_port = _NativeConfigPort(other_path)
        other_owner = GuiModeOwner(other_port, credential_store.WindowsUploadModeStore)
        with self.assertRaises(ModeCoordinatorError):
            other_owner.initialize()
        with self.assertRaises(ModeCoordinatorError):
            other_owner.acquire('legacy', other_port)
        with self.vault.transaction() as transaction:
            self.assertEqual(self.record, transaction.read())

    def test_case_alias_of_existing_config_fails_lexical_mode_binding(self) -> None:
        """Reject a differently spelled path to the very same config file.

        This is a lexical mismatch demonstration, not junction/reparse safety.

        Returns:
            None: The alternate spelling cannot activate either upload mode.
        """
        config = ConfigSnapshotStore(self.config_path, frozenset({'api_key'}))
        config.save_mode_anchor(config.inspect(), encode_mode_record(self.record))
        with self.vault.transaction() as transaction:
            transaction.write(self.record)
        aliased = self.config_path.with_name(self.config_path.name.upper())
        self.assertNotEqual(str(aliased), str(self.config_path))
        self.assertTrue(aliased.is_file())
        self.assertNotEqual(
            profile_path_digest(str(aliased)),
            profile_path_digest(str(self.config_path)),
        )
        other_port = _NativeConfigPort(aliased)
        owner = GuiModeOwner(other_port, credential_store.WindowsUploadModeStore)
        with self.assertRaises(ModeCoordinatorError):
            owner.initialize()
        with self.assertRaises(ModeCoordinatorError):
            owner.acquire('legacy', other_port)
        with self.vault.transaction() as transaction:
            self.assertEqual(self.record, transaction.read())

    def test_native_mismatched_anchor_and_missing_vault_are_unavailable(self) -> None:
        """Reject one-sided and mismatched evidence from real filesystem snapshots.

        Returns:
            None: No legacy admission is inferred while either record survives.
        """
        config = ConfigSnapshotStore(self.config_path, frozenset({'api_key'}))
        config.save_mode_anchor(config.inspect(), encode_mode_record(self.record))
        with self.assertRaises(UploadModeError):
            resolve_mode(
                encode_mode_record(self.record), None, config_verified=True,
                config_path=str(self.config_path),
            )
        with self.vault.transaction() as transaction:
            transaction.write(self.record)
        other = ModeRecord(
            self.record.profile_id, secrets.token_hex(16), 'legacy',
            self.record.profile_path_hash,
        )
        config.save_mode_anchor(config.inspect(), encode_mode_record(other))
        with self.assertRaises(UploadModeError):
            resolve_mode(
                encode_mode_record(other), encode_mode_record(self.record),
                config_verified=True, config_path=str(self.config_path),
            )

    def test_local_profile_guard_accepts_temp_profile_and_refuses_other_roots(self) -> None:
        """Admit a local temporary profile and reject nonlocal path syntax.

        Returns:
            None: Only the existing local fixed-drive profile is admitted.
        """
        assert_local_windows_profile(self.directory)
        for path_kind, rejected in (
            ('relative', Path('relative-profile')),
            ('UNC', Path(r'\\server\share\profile')),
            ('extended device', Path('\\\\?\\C:\\profile')),
            ('device', Path('\\\\.\\C:\\profile')),
        ):
            with self.subTest(path_kind=path_kind):
                with self.assertRaises(OSError):
                    assert_local_windows_profile(rejected)

    def test_first_gui_target_works_without_an_api_key_or_config(self) -> None:
        """Activate a localhost upload target on a fresh Windows profile.

        Returns:
            None: The real GUI owner admits uploads after explicit selection,
                keeps the key empty, and can reconnect the same saved target.
        """
        config_path = self.directory / 'fresh-config.json'
        app = object.__new__(MAIN.WatcherApp)
        app.background_work_active = lambda: False
        app.flights = mock.Mock()
        app.flights.is_alive.return_value = False
        app.flights.busy.return_value = False
        app.flights.pending.return_value = []
        app.flights.import_bindings = ImportBindings(self.directory / 'flights')
        app.job_store = MAIN.JobStateStore(self.directory / 'upload_state.json')
        app.config_store = MAIN.ConfigStore(config_path)
        app.config = app.config_store.load()
        app.mode_owner = GuiModeOwner(
            app.config_store.mode_port(app.config), credential_store.WindowsUploadModeStore,
        )
        app.mode_owner.initialize()
        self.assertEqual('first_setup', app.mode_owner.view().state)

        session = mock.Mock(spec=LinkingSession)
        session.can_upload.return_value = True
        session.matches_issuer.return_value = True
        session.approved_scope.return_value = UPLOAD_SCOPE
        target = LinkedUploadTarget(
            session, 'http://localhost:8888/galerie', 7, 29, 'Disposable uploads',
        )
        app.linking_manager = mock.Mock()
        app.linking_manager.linked_upload_mode_selected.return_value = True
        app.linking_manager.selected_upload_target.return_value = target

        with mock.patch.object(MAIN, 'CONFIG_DIR', self.directory):
            try:
                activated = app.commit_linked_upload_target(target)
            finally:
                # Retain cleanup ownership even if publication stops halfway.
                with self.vault.transaction() as transaction:
                    record = transaction.read()
                    if (record is not None
                            and record.profile_path_hash == profile_path_digest(str(config_path))
                            and record.issuer == target.issuer
                            and record.account_id == target.user_id
                            and record.gallery_id == target.gallery_id):
                        self._owned_records.add(record)
            self.assertTrue(activated)
            self.assertIs(target, app.capture_linked_target())
            with app.mode_owner.acquire('linked', app.config_store.mode_port(app.config)) as work:
                work.checkpoint()

            app.config_store = MAIN.ConfigStore(config_path)
            app.config = app.config_store.load()
            self.assertEqual('', app.config.api_key)
            self.assertEqual('', app.config.gallery_url)
            app.mode_owner = GuiModeOwner(
                app.config_store.mode_port(app.config), credential_store.WindowsUploadModeStore,
            )
            app.mode_owner.initialize()
            self.assertEqual('linked_disconnected', app.mode_owner.view().state)
            self.assertTrue(app.commit_linked_upload_target(target))
            self.assertEqual(record, app.mode_owner.view().record)
            self.assertIs(target, app.capture_linked_target())

    def test_gui_mode_owner_restart_needs_explicit_same_gallery_reconnect(self) -> None:
        """Exercise native legacy, linked, restart and explicit return transitions.

        Returns:
            None: Only explicit selection changes mode; the saved API key survives.
        """
        config = ConfigSnapshotStore(self.config_path, frozenset({'api_key'}))
        first_port = _NativeConfigPort(self.config_path)
        first = GuiModeOwner(first_port, credential_store.WindowsUploadModeStore)
        first.initialize()
        self.assertEqual('legacy_unmigrated', first.view().state)
        with first.acquire('legacy', first_port) as work:
            work.checkpoint()
        self.assertEqual('test-only', config.read(config.inspect())['api_key'])

        session = mock.Mock(spec=LinkingSession)
        session.can_upload.return_value = True
        session.matches_issuer.return_value = True
        session.approved_scope.return_value = UPLOAD_SCOPE
        target = LinkedUploadTarget(
            session, self.record.issuer, self.record.account_id,
            self.record.gallery_id, 'Disposable test gallery',
        )
        first.select_linked(target)
        self.assertEqual('linked_active', first.view().state)
        first_linked_record = first.view().record
        self.assertIsNotNone(first_linked_record)
        self._owned_records.add(first_linked_record)
        with self.vault.transaction() as transaction:
            self.assertEqual(first_linked_record, transaction.read())
        self.assertEqual('test-only', config.read(config.inspect())['api_key'])
        with first.acquire('linked', first_port) as work:
            with self.assertRaises(ModeCoordinatorError):
                first.select_legacy()
            work.checkpoint()
        self.assertEqual('linked_active', first.view().state)

        restarted_port = _NativeConfigPort(self.config_path)
        restarted = GuiModeOwner(
            restarted_port, credential_store.WindowsUploadModeStore,
        )
        restarted.initialize()
        self.assertEqual('linked_disconnected', restarted.view().state)
        with self.assertRaises(ModeCoordinatorError):
            restarted.acquire('linked', restarted_port)
        with self.assertRaises(ModeCoordinatorError):
            restarted.acquire('legacy', restarted_port)
        restarted.select_linked(target)
        self.assertEqual('linked_active', restarted.view().state)
        self.assertEqual(first_linked_record, restarted.view().record)
        with restarted.acquire('linked', restarted_port) as work:
            work.checkpoint()

        session.approved_scope.return_value = 'link:identity galleries:list'
        stale_port = _NativeConfigPort(self.config_path)
        stale = GuiModeOwner(stale_port, credential_store.WindowsUploadModeStore)
        stale.initialize()
        with self.assertRaises(ModeCoordinatorError):
            stale.select_linked(target)
        with self.assertRaises(ModeCoordinatorError):
            stale.acquire('legacy', stale_port)
        session.approved_scope.return_value = UPLOAD_SCOPE

        legacy = restarted.select_legacy()
        self.assertEqual('legacy', legacy.state)
        self.assertIsNotNone(legacy.record)
        self._owned_records.add(legacy.record)
        with self.vault.transaction() as transaction:
            legacy_record = transaction.read()
        self.assertEqual(legacy.record, legacy_record)
        self.assertEqual('test-only', config.read(config.inspect())['api_key'])
        with restarted.acquire('legacy', restarted_port) as work:
            work.checkpoint()

        unavailable_port = _NativeConfigPort(self.config_path)
        unavailable = GuiModeOwner(unavailable_port, lambda: self.vault)
        with mock.patch.object(
            self.vault._api, 'read',
            side_effect=credential_store.CredentialStoreError('vault unavailable'),
        ):
            with self.assertRaises(ModeCoordinatorError):
                unavailable.initialize()
        with self.assertRaises(ModeCoordinatorError):
            unavailable.acquire('legacy', unavailable_port)

    def test_native_refresh_credential_round_trip_is_vault_only(self) -> None:
        """Round-trip a synthetic credential without a plaintext file copy.

        Returns:
            None: The exact test credential is removed from its unique vault key.
        """
        store = credential_store.WindowsCredentialStore()
        # Random test-only issuer/account values isolate this disposable row.
        issuer = 'https://' + secrets.token_hex(16) + '.example'
        account_id = int(secrets.token_hex(4), 16) + 1
        # A 24-byte random suffix makes accidental disclosure observable.
        payload = b'test-only-refresh-' + secrets.token_hex(24).encode('ascii')
        output = io.StringIO()
        with redirect_stdout(output), redirect_stderr(output):
            try:
                with store.binding_transaction(issuer, account_id):
                    if store.read(issuer, account_id) is not None:
                        self.skipTest(
                            'Unique native refresh credential key is occupied.'
                        )
                    store.write(issuer, account_id, payload)
                    self.assertEqual(payload, store.read(issuer, account_id))
                    self.assertNotIn(payload.decode('ascii'), output.getvalue())
            finally:
                with store.binding_transaction(issuer, account_id):
                    if store.read(issuer, account_id) == payload:
                        self.assertTrue(store.delete(issuer, account_id))
                        self.assertIsNone(store.read(issuer, account_id))
        for candidate in self.directory.rglob('*'):
            if candidate.is_file():
                self.assertNotIn(payload, candidate.read_bytes())
        self.assertNotIn(payload.decode('ascii'), output.getvalue())

    def test_created_gallery_receipt_publishes_exact_native_linked_target(self) -> None:
        """Join a durable create receipt to native mode publication and restart.

        Returns:
            None: Only the reconciled exact gallery is published; key and receipt
                remain intact, and restart requires explicit target selection.
        """
        issuer = 'https://gallery.example'
        account_id = 7
        gallery = LinkedGallery(42, None, 'Disposable created gallery')
        session = mock.Mock(spec=LinkingSession)
        session.matches_issuer.return_value = True
        session.can_create_gallery.return_value = True
        session.can_upload.return_value = True
        session.approved_scope.return_value = READ_UPLOAD_CREATE_SCOPE
        session.identity.return_value = mock.Mock(user_id=account_id)
        session.gallery_create_request.return_value = gallery
        session.gallery_page.return_value = LinkedGalleryPage((gallery,), None)
        journal = GalleryCreateJournal(self.directory)
        bound = journal.create(
            session, issuer, account_id, gallery.title, gallery.parent_id,
        )
        intent_path = journal._path()
        receipt_path = journal._receipt_path()
        self.assertTrue(intent_path.is_file())
        self.assertTrue(receipt_path.is_file())

        session.gallery_create_request.reset_mock()
        reconciled = GalleryCreateJournal(self.directory).reconcile(
            session, issuer, account_id,
        )
        self.assertEqual(bound, reconciled)
        session.gallery_create_request.assert_called_once()
        self.assertIsNotNone(reconciled)
        target = verify_linked_upload_target(
            session, issuer, account_id, READ_UPLOAD_CREATE_SCOPE,
            reconciled.gallery.gallery_id,
        )

        config = ConfigSnapshotStore(self.config_path, frozenset({'api_key'}))
        first_port = _NativeConfigPort(self.config_path)
        first = GuiModeOwner(first_port, credential_store.WindowsUploadModeStore)
        first.initialize()
        self.assertEqual('legacy_unmigrated', first.view().state)

        stale = BoundCreateReceipt(
            LinkedGallery(43, None, gallery.title), bound.intent_fingerprint,
        )
        rejected = mock.Mock(return_value=True)
        with self.assertRaises(LinkingError):
            journal.apply_receipt(issuer, account_id, stale, rejected)
        rejected.assert_not_called()
        self.assertEqual('legacy_unmigrated', first.view().state)

        def publish_exact_target() -> bool:
            """Select the current gallery through the native mode owner.

            Returns:
                bool: True after the owner verifies and publishes this target.
            """
            first.select_linked(target)
            return True

        self.assertTrue(journal.apply_receipt(
            issuer, account_id, reconciled, publish_exact_target,
        ))
        active = first.view()
        self.assertEqual('linked_active', active.state)
        self.assertIsNotNone(active.record)
        self.assertEqual(gallery.gallery_id, active.record.gallery_id)
        self._owned_records.add(active.record)
        self.assertEqual('test-only', config.read(config.inspect())['api_key'])
        saved_intent = intent_path.read_bytes()
        saved_receipt = receipt_path.read_bytes()

        restarted_port = _NativeConfigPort(self.config_path)
        restarted = GuiModeOwner(
            restarted_port, credential_store.WindowsUploadModeStore,
        )
        restarted.initialize()
        self.assertEqual('linked_disconnected', restarted.view().state)
        with self.assertRaises(ModeCoordinatorError):
            restarted.acquire('legacy', restarted_port)
        with self.assertRaises(ModeCoordinatorError):
            restarted.acquire('linked', restarted_port)
        restarted.select_linked(target)
        self.assertEqual('linked_active', restarted.view().state)
        self.assertEqual(active.record, restarted.view().record)
        self.assertEqual(saved_intent, intent_path.read_bytes())
        self.assertEqual(saved_receipt, receipt_path.read_bytes())
        self.assertEqual('test-only', config.read(config.inspect())['api_key'])

    def test_native_profile_refuses_junction_ancestor(self) -> None:
        """Refuse a real NTFS junction even when its child is an ordinary folder.

        Returns:
            None: The redirected spelling is refused and owned data is retained.
        """
        folder = self.directory / 'ordinary'
        profile = folder / 'profile'
        profile.mkdir(parents=True)
        alias = self.directory / 'junction'
        created = subprocess.run(
            ['cmd.exe', '/d', '/c', 'mklink', '/J', str(alias), str(folder)],
            capture_output=True, text=True, timeout=NATIVE_CHILD_TIMEOUT_SECONDS,
            check=False,
        )
        self.assertEqual(0, created.returncode, created.stderr)
        self.addCleanup(alias.rmdir)
        assert_local_windows_profile(profile)
        with self.assertRaises(OSError):
            assert_local_windows_profile(alias / profile.name)
        self.assertTrue(profile.is_dir())


@unittest.skipUnless(sys.platform == 'win32', 'Native process exclusion requires Windows')
class NativeWindowsProcessTests(unittest.TestCase):
    """Exercise cooperating native process locks without changing live user data."""

    def _run_child(self, kind: str, *arguments: str) -> subprocess.CompletedProcess[str]:
        """Start a bounded child for one real Windows contention attempt.

        Args:
            kind (str): Worker mode, including a controlled abrupt termination.
            *arguments (str): Isolated lock directory, binding or wait limit.

        Returns:
            subprocess.CompletedProcess[str]: Native child status and transcript.
        """
        return subprocess.run(
            [sys.executable, '-c', _NATIVE_CHILD_CODE, kind, *arguments],
            cwd=Path(__file__).resolve().parents[1], capture_output=True,
            text=True, timeout=NATIVE_CHILD_TIMEOUT_SECONDS, check=False,
        )

    def test_native_metadata_mutex_serializes_two_windows_processes(self) -> None:
        """Show cooperating processes cannot concurrently own the same metadata lock.

        Returns:
            None: A live first owner blocks and then releases the second owner.
        """
        with credential_store._WindowsCredentialMutex(None, None, metadata=True):
            blocked = self._run_child(
                'mutex-probe', str(NATIVE_MUTEX_PROBE_WAIT_MS),
            )
            self.assertEqual(0, blocked.returncode, blocked.stderr)
            self.assertEqual('busy', blocked.stdout.strip())
        admitted = self._run_child('mutex-probe', str(NATIVE_MUTEX_PROBE_WAIT_MS))
        self.assertEqual(0, admitted.returncode, admitted.stderr)
        self.assertEqual('acquired', admitted.stdout.strip())

    def test_current_desktop_mutex_excludes_same_profile_across_pids(self) -> None:
        """Qualify only cooperating desktop processes, not old uploader builds.

        A separate profile is admitted by design and shares the fixed mode
        vault namespace. Running-process enumeration is not an upload fence.

        Returns:
            None: Same-profile child is blocked until the owner releases it.
        """
        with tempfile.TemporaryDirectory(prefix='phpgallery-desktop-native-') as folder:
            first = Path(folder) / 'first-profile'
            second = Path(folder) / 'second-profile'
            first.mkdir()
            second.mkdir()
            owner = WindowsInstance(first)
            try:
                self.assertTrue(owner.acquire())
                blocked = self._run_child('desktop-instance', str(first))
                self.assertEqual(0, blocked.returncode, blocked.stderr)
                self.assertEqual('busy', blocked.stdout.strip())
                separate = self._run_child('desktop-instance', str(second))
                self.assertEqual(0, separate.returncode, separate.stderr)
                self.assertEqual('acquired', separate.stdout.strip())
            finally:
                owner.close()
            released = self._run_child('desktop-instance', str(first))
            self.assertEqual(0, released.returncode, released.stderr)
            self.assertEqual('acquired', released.stdout.strip())

    def test_target_lease_recovers_after_process_exit_but_blocks_live_owner(self) -> None:
        """Use Windows file ownership to distinguish a live and crashed owner.

        Returns:
            None: A native child is blocked by the live owner and can reuse
                the original v2 lock only after its process has exited.
        """
        with tempfile.TemporaryDirectory(prefix='phpgallery-target-native-') as directory:
            binding = 'a' * 64
            crashed = self._run_child('lease-crash', directory, binding)
            self.assertEqual(0, crashed.returncode, crashed.stderr)
            lease = LinkedTargetLease(Path(directory), binding)
            lease.acquire()
            try:
                refused = self._run_child('lease-probe', directory, binding)
                self.assertEqual(0, refused.returncode, refused.stderr)
                self.assertEqual('busy', refused.stdout.strip())
            finally:
                lease.release()
            recovered = self._run_child('lease-probe', directory, binding)
            self.assertEqual(0, recovered.returncode, recovered.stderr)
            self.assertEqual('acquired', recovered.stdout.strip())
