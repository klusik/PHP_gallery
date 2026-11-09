# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/tests/test_mode_coordinator.py
# Module Type: Regression Test
# Purpose: Verify headless mode coordination with isolated storage doubles.
# Responsibilities:
#   - Keep missing, stale and uncertain mode evidence fail-closed.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Portable source fixtures; no native Windows or network operation runs."""

import unittest
from dataclasses import replace
from types import TracebackType
from typing import Literal
from unittest.mock import Mock

from uploader.app_linking import LinkingSession, UPLOAD_SCOPE
from uploader.config_snapshot import ConfigSnapshot
from uploader.linked_upload import LinkedUploadTarget
from uploader.mode_coordinator import GuiModeOwner, ModeCoordinator, ModeCoordinatorError
from uploader.upload_mode import ModeRecord, encode_mode_record, profile_path_digest


class _ConfigPort:
    """Simulate the future sole ConfigStore mode transaction without files."""

    def __init__(self, state: Literal['valid', 'missing', 'unavailable'] = 'valid') -> None:
        """Prepare one synthetic config with a confirmed absent mode anchor.

        Args:
            state (Literal['valid', 'missing', 'unavailable']): Config state.

        Returns:
            None: Creates isolated owner-bound snapshot evidence.
        """
        self.config_path = r'C:\Users\Tester\config.json'
        self.owner = object()
        self.parent_id = (1, 1)
        self.state = state
        self.anchor: bytes | None = None
        self.generation = 1
        self.fail_after_write = False

    def mode_transaction(self) -> '_ConfigPort':
        """Prepare the same isolated context as an entered config transaction.

        Returns:
            _ConfigPort: Fixture transaction with no native mutex.
        """
        return self

    def __enter__(self) -> '_ConfigPort':
        """Enter the isolated config transaction.

        Returns:
            _ConfigPort: The fixture transaction itself.
        """
        return self

    def __exit__(self, exc_type: type[BaseException] | None,
                 exc_value: BaseException | None,
                 traceback: TracebackType | None) -> bool:
        """Leave the isolated config transaction without swallowing errors.

        Args:
            exc_type (type[BaseException] | None): Context exception class.
            exc_value (BaseException | None): Context exception instance.
            traceback (TracebackType | None): Context exception traceback.

        Returns:
            bool: False to retain failures.
        """
        return False

    def snapshot(self) -> ConfigSnapshot:
        """Construct stable owner-bound evidence for the current fixture data.

        Returns:
            ConfigSnapshot: Synthetic immutable private configuration snapshot.
        """
        content = (b'{"fixture":' + str(self.generation).encode() + b'}'
                   if self.state == 'valid' else None)
        identity = (1, 2, len(content), self.generation) if content else None
        return ConfigSnapshot(self.state, self.owner, self.parent_id, identity, content)

    def read_mode_anchor(self, snapshot: ConfigSnapshot) -> bytes | None:
        """Read only against the exact current synthetic configuration evidence.

        Args:
            snapshot (ConfigSnapshot): Previously observed config evidence.

        Returns:
            bytes | None: Fixture anchor or a confirmed absent anchor.

        Raises:
            ValueError: The snapshot no longer matches the fixture owner.
        """
        if snapshot != self.snapshot() or snapshot.state != 'valid':
            raise ValueError('fixture changed')
        return self.anchor

    def write_mode_anchor(self, snapshot: ConfigSnapshot, payload: bytes) -> ConfigSnapshot:
        """Simulate a full-snapshot compare and update of just the mode anchor.

        Args:
            snapshot (ConfigSnapshot): Expected complete fixture evidence.
            payload (bytes): Encoded mode record to store as the new anchor.

        Returns:
            ConfigSnapshot: New owner-bound fixture evidence.

        Raises:
            ValueError: The expected snapshot is stale or the write is uncertain.
        """
        if snapshot != self.snapshot() or snapshot.state != 'valid':
            raise ValueError('fixture changed')
        self.anchor = payload
        self.generation += 1
        if self.fail_after_write:
            raise ValueError('fixture write uncertain')
        return self.snapshot()


class _VaultPort:
    """Simulate a SID-bound vault transaction without calling Windows."""

    def __init__(self) -> None:
        """Prepare a positively absent record and an idle failure switch.

        Returns:
            None: Creates a fresh isolated vault double.
        """
        self.record: ModeRecord | None = None
        self.fail_read = False
        self.fail_after_write = False
        self.writes = 0

    def transaction(self) -> '_VaultPort':
        """Prepare the fixture transaction.

        Returns:
            _VaultPort: The isolated context-manager double.
        """
        return self

    def __enter__(self) -> '_VaultPort':
        """Enter the fixture transaction.

        Returns:
            _VaultPort: The isolated vault double.
        """
        return self

    def __exit__(self, exc_type: type[BaseException] | None,
                 exc_value: BaseException | None,
                 traceback: TracebackType | None) -> bool:
        """Leave the fixture transaction while retaining errors.

        Args:
            exc_type (type[BaseException] | None): Context exception class.
            exc_value (BaseException | None): Context exception instance.
            traceback (TracebackType | None): Context exception traceback.

        Returns:
            bool: False to retain failures.
        """
        return False

    def read(self) -> ModeRecord | None:
        """Read the simulated vault value or raise an unavailable error.

        Returns:
            ModeRecord | None: Current fixture record or confirmed absence.

        Raises:
            OSError: The synthetic vault read is unavailable.
        """
        if self.fail_read:
            raise OSError('fixture vault unavailable')
        return self.record

    def write(self, record: ModeRecord) -> None:
        """Write a fixture record, optionally reporting an uncertain result.

        Args:
            record (ModeRecord): New non-secret fixture mode record.

        Returns:
            None: The fixture now stores this record.

        Raises:
            OSError: An injected failure occurred after the simulated write.
        """
        self.record = record
        self.writes += 1
        if self.fail_after_write:
            raise OSError('fixture write uncertain')


class ModeCoordinatorTests(unittest.TestCase):
    """Exercise public coordinator behavior without Windows or Tk."""

    def setUp(self) -> None:
        """Prepare independent config and vault owners plus a linked record.

        Returns:
            None: Creates isolated fixture state for one profile.
        """
        self.config = _ConfigPort()
        self.vault = _VaultPort()
        self.record = ModeRecord('a' * 32, 'b' * 32, 'linked',
                                 profile_path_digest(self.config.config_path),
                                 'https://gallery.example', 7, UPLOAD_SCOPE, 9)
        self.owner = ModeCoordinator(self.config, self.vault)

    def test_verified_legacy_and_first_setup_have_distinct_admission(self) -> None:
        """Only an existing valid config with both absences admits old pgu.

        Returns:
            None: Checks compatible legacy and an empty first setup separately.
        """
        view = self.owner.refresh()
        self.assertEqual('legacy_unmigrated', view.state)
        with self.owner.acquire_work(view, 'legacy'):
            pass
        fresh = ModeCoordinator(_ConfigPort('missing'), _VaultPort())
        first = fresh.refresh()
        self.assertEqual('first_setup', first.state)
        with self.assertRaises(ModeCoordinatorError):
            fresh.acquire_work(first, 'legacy')
        missing_vault = _VaultPort()
        missing_vault.record = self.record
        missing_config = ModeCoordinator(_ConfigPort('missing'), missing_vault)
        with self.assertRaises(ModeCoordinatorError):
            missing_config.refresh()

    def test_gui_owner_never_admits_work_before_verified_startup(self) -> None:
        """Keep GUI work closed before initialization and on vault failure.

        Returns:
            None: No unverified state falls through to the API-key mode.
        """
        gui = GuiModeOwner(self.config, lambda: self.vault)
        with self.assertRaises(ModeCoordinatorError):
            gui.acquire('legacy', self.config)
        gui.initialize()
        with gui.acquire('legacy', self.config):
            pass
        unavailable = _VaultPort()
        unavailable.fail_read = True
        failed = GuiModeOwner(self.config, lambda: unavailable)
        with self.assertRaises(ModeCoordinatorError):
            failed.initialize()
        with self.assertRaises(ModeCoordinatorError):
            failed.acquire('legacy', self.config)

    def test_gui_save_is_serialized_with_active_and_child_work(self) -> None:
        """Block a GUI config write until all accepted work has closed.

        Returns:
            None: The callback runs only after both live leases are released.
        """
        gui = GuiModeOwner(self.config, lambda: self.vault)
        gui.initialize()
        parent = gui.acquire('legacy', self.config)
        child = parent.child()
        writes = []

        def save() -> _ConfigPort:
            """Write one fixture config generation under reserved admission.

            Returns:
                _ConfigPort: Same owner after the synthetic save.
            """
            writes.append(True)
            self.config.generation += 1
            return self.config

        with self.assertRaises(ModeCoordinatorError):
            gui.save(save)
        parent.close()
        with self.assertRaises(ModeCoordinatorError):
            gui.save(save)
        self.assertEqual([], writes)
        child.close()
        gui.save(save)
        self.assertEqual([True], writes)
        with gui.acquire('legacy', self.config):
            pass

    def test_gui_worker_config_and_children_must_match_owner_snapshot(self) -> None:
        """Refuse a stale captured config before admission and after entry.

        Returns:
            None: A different snapshot or later change closes new work.
        """
        gui = GuiModeOwner(self.config, lambda: self.vault)
        gui.initialize()
        stale = _ConfigPort()
        stale.owner = self.config.owner
        stale.generation += 1
        with self.assertRaises(ModeCoordinatorError):
            gui.acquire('legacy', stale)
        # The owner fails closed after observing contradictory evidence.
        with self.assertRaises(ModeCoordinatorError):
            gui.acquire('legacy', self.config)

        self.setUp()
        gui = GuiModeOwner(self.config, lambda: self.vault)
        gui.initialize()
        captured = _ConfigPort()
        captured.owner = self.config.owner
        work = gui.acquire('legacy', captured)
        child = work.child()
        captured.generation += 1
        with self.assertRaises(ModeCoordinatorError):
            child.checkpoint()
        with self.assertRaises(ModeCoordinatorError):
            work.checkpoint()
        child.close()
        work.close()

    def test_mismatch_and_vault_failure_never_fallback_to_pgu(self) -> None:
        """An incomplete pair or unavailable vault cannot authorize legacy.

        Returns:
            None: Keeps view and legacy work unavailable after both failures.
        """
        self.config.anchor = encode_mode_record(self.record)
        with self.assertRaises(ModeCoordinatorError):
            self.owner.refresh()
        with self.assertRaises(ModeCoordinatorError):
            self.owner.view()
        self.config.anchor = None
        self.vault.fail_read = True
        with self.assertRaises(ModeCoordinatorError):
            self.owner.refresh()
        with self.assertRaises(ModeCoordinatorError):
            self.owner.view()

    def test_legacy_unc_path_does_not_become_a_linked_profile(self) -> None:
        """Keep old profiles usable while refusing unsafe mode publication.

        Returns:
            None: An unmigrated UNC config works but cannot publish v2 mode.
        """
        self.config.config_path = r'\\server\share\config.json'
        view = self.owner.refresh()
        with self.owner.acquire_work(view, 'legacy'):
            pass
        with self.assertRaises(ModeCoordinatorError):
            self.owner.publish(view, self.record)
        self.assertEqual(0, self.vault.writes)

    def test_matching_linked_record_requires_explicit_reconnect(self) -> None:
        """Recognize matched linked evidence without activating a session.

        Returns:
            None: Reconnect is admitted and both upload transports are refused.
        """
        self.vault.record = self.record
        self.config.anchor = encode_mode_record(self.record)
        view = self.owner.refresh()
        self.assertEqual('linked_disconnected', view.state)
        for kind in ('legacy', 'linked'):
            with self.assertRaises(ModeCoordinatorError):
                self.owner.acquire_work(view, kind)
        with self.owner.acquire_work(view, 'reconnect'):
            pass

    def test_rebind_retains_owner_and_mode_but_reissues_view(self) -> None:
        """Adopt one saved generation without replacing mode or admission owner.

        Returns:
            None: Old views are stale and the new port controls later work.
        """
        self.vault.record = self.record
        self.config.anchor = encode_mode_record(self.record)
        view = self.owner.refresh()
        replacement = _ConfigPort()
        replacement.owner = self.config.owner
        replacement.anchor = self.config.anchor
        replacement.generation = self.config.generation + 1
        current = self.owner.rebind_config(view, replacement)
        self.assertEqual('linked_disconnected', current.state)
        self.assertEqual(self.record, current.record)
        with self.assertRaises(ModeCoordinatorError):
            self.owner.acquire_work(view, 'reconnect')
        with self.owner.acquire_work(current, 'reconnect'):
            pass
        replacement.generation += 1
        with self.assertRaises(ModeCoordinatorError):
            self.owner.acquire_work(current, 'reconnect')
        self.assertEqual(0, self.vault.writes)

    def test_save_reserves_admission_until_the_new_port_is_verified(self) -> None:
        """Keep the same owner exclusive during the entire config update.

        Returns:
            None: Work cannot start inside save and the new view stays legacy.
        """
        view = self.owner.refresh()

        def save() -> _ConfigPort:
            """Publish one synthetic generation while testing admission.

            Returns:
                _ConfigPort: Same config owner at its new fixture generation.
            """
            with self.assertRaises(ModeCoordinatorError):
                self.owner.acquire_work(view, 'legacy')
            self.config.generation += 1
            return self.config

        current = self.owner.save_and_rebind(view, save)
        self.assertEqual('legacy_unmigrated', current.state)
        with self.owner.acquire_work(current, 'legacy'):
            pass
        with self.assertRaises(ModeCoordinatorError):
            self.owner.acquire_work(view, 'legacy')
        self.assertEqual(0, self.vault.writes)

    def test_first_setup_needs_an_explicit_save_before_legacy_work(self) -> None:
        """Never infer a legacy profile from an unsaved first-run config.

        Returns:
            None: Only the explicitly authorized valid save admits legacy.
        """
        config = _ConfigPort('missing')
        owner = ModeCoordinator(config, _VaultPort())
        first = owner.refresh()
        calls = []

        def save() -> _ConfigPort:
            """Create the same synthetic owner's initial configuration.

            Returns:
                _ConfigPort: The new valid fixture generation.
            """
            calls.append(True)
            config.state = 'valid'
            config.generation += 1
            return config

        with self.assertRaises(ModeCoordinatorError):
            owner.save_and_rebind(first, save)
        self.assertEqual([], calls)
        legacy = owner.save_and_rebind(first, save, first_setup=True)
        self.assertEqual('legacy_unmigrated', legacy.state)
        with owner.acquire_work(legacy, 'legacy'):
            pass

    def test_save_failure_or_changed_mode_remains_unavailable(self) -> None:
        """Never accept a partial save or a changed mode as the old decision.

        Returns:
            None: Both uncertainty cases close further work admission.
        """
        for scenario in ('failure', 'vault'):
            with self.subTest(scenario=scenario):
                self.setUp()
                view = self.owner.refresh()

                def save() -> _ConfigPort:
                    """Change the local fixture and optionally fail mid-save.

                    Returns:
                        _ConfigPort: Same fixture owner after the saved update.

                    Raises:
                        OSError: A synthetic save ended with unknown outcome.
                    """
                    self.config.generation += 1
                    if scenario == 'failure':
                        raise OSError('fixture uncertain')
                    self.vault.record = self.record
                    return self.config

                with self.assertRaises(ModeCoordinatorError):
                    self.owner.save_and_rebind(view, save)
                with self.assertRaises(ModeCoordinatorError):
                    self.owner.view()
                with self.assertRaises(ModeCoordinatorError):
                    self.owner.acquire_work(view, 'legacy')

    def test_confirmed_revoke_work_hands_off_atomically_to_save(self) -> None:
        """Keep admission closed from completed revoke through config readback.

        Returns:
            None: Only exact revoke work can authorize the exceptional save path.
        """
        view = self.owner.refresh()
        completed = self.owner.acquire_work(view, 'legacy_revoke', self.config)
        writes = []

        def save() -> _ConfigPort:
            """Save one fixture generation while admission is reserved.

            Returns:
                _ConfigPort: The same config owner at its next generation.
            """
            writes.append(True)
            self.config.generation += 1
            with self.assertRaises(ModeCoordinatorError):
                self.owner.acquire_work(view, 'legacy', self.config)
            return self.config

        with self.assertRaises(ModeCoordinatorError):
            self.owner.save_and_rebind(view, lambda: self.config)
        self.assertEqual([], writes)
        current = self.owner.save_and_rebind(
            view, save, completed_work=completed,
        )
        self.assertEqual([True], writes)
        self.assertEqual('legacy_unmigrated', current.state)
        with self.assertRaises(ModeCoordinatorError):
            self.owner.acquire_work(view, 'legacy', self.config)
        with self.owner.acquire_work(current, 'legacy', self.config):
            pass

    def test_revoke_handoff_rejects_nonsole_and_stale_work(self) -> None:
        """Require current revoke work with no child or peer at handoff.

        Returns:
            None: Failed handoff leaves the old lease active for cleanup.
        """
        view = self.owner.refresh()
        ordinary = self.owner.acquire_work(view, 'legacy', self.config)
        with self.assertRaises(ModeCoordinatorError):
            self.owner.save_and_rebind(
                view, lambda: self.config, completed_work=ordinary,
            )
        ordinary.close()

        revoke = self.owner.acquire_work(view, 'legacy_revoke', self.config)
        child = revoke.child()
        with self.assertRaises(ModeCoordinatorError):
            self.owner.save_and_rebind(
                view, lambda: self.config, completed_work=revoke,
            )
        child.close()
        peer = self.owner.acquire_work(view, 'legacy_revoke', self.config)
        with self.assertRaises(ModeCoordinatorError):
            self.owner.save_and_rebind(
                view, lambda: self.config, completed_work=revoke,
            )
        peer.close()
        revoke.close()

        stale = self.owner.acquire_work(view, 'legacy_revoke', self.config)
        stale.close()
        current = self.owner.refresh()
        with self.assertRaises(ModeCoordinatorError):
            self.owner.save_and_rebind(
                current, lambda: self.config, completed_work=stale,
            )

    def test_revoke_handoff_uncertain_save_stays_unavailable(self) -> None:
        """Do not restore legacy admission after an uncertain revoke save.

        Returns:
            None: Failed save invalidates both the handed lease and old view.
        """
        view = self.owner.refresh()
        completed = self.owner.acquire_work(view, 'legacy_revoke', self.config)

        def fail_after_change() -> _ConfigPort:
            """Make fixture persistence uncertain after changing generation.

            Returns:
                _ConfigPort: Unreachable because the callback raises.

            Raises:
                OSError: The fixture simulates uncertain persistence.
            """
            self.config.generation += 1
            raise OSError('fixture save outcome unknown')

        with self.assertRaises(ModeCoordinatorError):
            self.owner.save_and_rebind(
                view, fail_after_change, completed_work=completed,
            )
        with self.assertRaises(ModeCoordinatorError):
            self.owner.view()
        with self.assertRaises(ModeCoordinatorError):
            self.owner.acquire_work(view, 'legacy', self.config)

    def test_rebind_preserves_active_linked_mode(self) -> None:
        """Preserve an already verified live mode across an idle config save.

        Returns:
            None: Only the newly issued view admits the existing linked mode.
        """
        self.vault.record = self.record
        self.config.anchor = encode_mode_record(self.record)
        disconnected = self.owner.refresh()
        session = Mock(spec=LinkingSession)
        session.matches_issuer.return_value = True
        session.can_upload.return_value = True
        session.approved_scope.return_value = UPLOAD_SCOPE
        target = LinkedUploadTarget(session, 'https://gallery.example', 7, 9, 'Gallery')
        active = self.owner.activate_linked(disconnected, target)
        replacement = _ConfigPort()
        replacement.owner = self.config.owner
        replacement.anchor = self.config.anchor
        replacement.generation += 1
        rebound = self.owner.rebind_config(active, replacement)
        self.assertEqual('linked_active', rebound.state)
        self.assertEqual(self.record, rebound.record)
        with self.owner.acquire_work(rebound, 'linked'):
            pass
        with self.assertRaises(ModeCoordinatorError):
            self.owner.acquire_work(active, 'linked')

    def test_rebind_rejects_stale_view_and_active_parent_or_child(self) -> None:
        """Never replace the config port while accepted work is still alive.

        Returns:
            None: Stale decisions and both parent and child leases block rebind.
        """
        old = self.owner.refresh()
        current = self.owner.refresh()
        replacement = _ConfigPort()
        replacement.owner = self.config.owner
        with self.assertRaises(ModeCoordinatorError):
            self.owner.rebind_config(old, replacement)
        parent = self.owner.acquire_work(current, 'legacy')
        child = parent.child()
        with self.assertRaises(ModeCoordinatorError):
            self.owner.rebind_config(current, replacement)
        parent.close()
        with self.assertRaises(ModeCoordinatorError):
            self.owner.rebind_config(current, replacement)
        child.close()
        rebound = self.owner.rebind_config(current, replacement)
        self.assertEqual('legacy_unmigrated', rebound.state)

    def test_rebind_failure_never_publishes_changed_mode_or_owner(self) -> None:
        """Fail closed for a foreign owner, parent, path, anchor or vault.

        Returns:
            None: Uncertain replacements leave no available mode or vault write.
        """
        for case in ('owner', 'parent', 'path', 'anchor', 'vault', 'unavailable'):
            with self.subTest(case=case):
                self.setUp()
                self.vault.record = self.record
                self.config.anchor = encode_mode_record(self.record)
                view = self.owner.refresh()
                replacement = _ConfigPort()
                replacement.owner = self.config.owner
                replacement.anchor = self.config.anchor
                replacement.generation += 1
                if case == 'owner':
                    replacement.owner = object()
                elif case == 'parent':
                    replacement.parent_id = (1, 2)
                elif case == 'path':
                    replacement.config_path = r'C:\Users\Tester\other.json'
                elif case == 'anchor':
                    replacement.anchor = None
                elif case == 'vault':
                    self.vault.record = None
                else:
                    replacement.state = 'unavailable'
                with self.assertRaises(ModeCoordinatorError):
                    self.owner.rebind_config(view, replacement)
                with self.assertRaises(ModeCoordinatorError):
                    self.owner.view()
                with self.assertRaises(ModeCoordinatorError):
                    self.owner.acquire_work(view, 'legacy')
                self.assertEqual(0, self.vault.writes)

    def test_verified_linked_target_activates_only_the_current_view(self) -> None:
        """Activate the matching local binding without issuing network requests.

        Returns:
            None: Only the new owner-issued view can admit linked work.
        """
        self.vault.record = self.record
        self.config.anchor = encode_mode_record(self.record)
        view = self.owner.refresh()
        session = Mock(spec=LinkingSession)
        session.matches_issuer.return_value = True
        session.can_upload.return_value = True
        session.approved_scope.return_value = UPLOAD_SCOPE
        target = LinkedUploadTarget(session, 'https://gallery.example', 7, 9, 'Gallery')
        active = self.owner.activate_linked(view, target)
        self.assertEqual('linked_active', active.state)
        self.assertEqual(self.record, active.record)
        with self.owner.acquire_work(active, 'linked'):
            pass
        with self.assertRaises(ModeCoordinatorError):
            self.owner.acquire_work(view, 'linked')
        session.identity.assert_not_called()
        session.gallery_page.assert_not_called()
        self.assertEqual(0, self.vault.writes)

    def test_activation_refuses_changed_target_or_local_session(self) -> None:
        """Reject a mismatched binding or unavailable locally held session.

        Returns:
            None: No mismatch activates a target or writes to the vault.
        """
        for case in ('issuer', 'account', 'gallery', 'scope', 'session_issuer', 'closed'):
            with self.subTest(case=case):
                self.setUp()
                self.vault.record = self.record
                self.config.anchor = encode_mode_record(self.record)
                view = self.owner.refresh()
                session = Mock(spec=LinkingSession)
                session.matches_issuer.return_value = True
                session.can_upload.return_value = True
                session.approved_scope.return_value = UPLOAD_SCOPE
                target = LinkedUploadTarget(session, 'https://gallery.example', 7, 9, 'Gallery')
                if case == 'issuer':
                    target = replace(target, issuer='https://other.example')
                elif case == 'account':
                    target = replace(target, user_id=8)
                elif case == 'gallery':
                    target = replace(target, gallery_id=10)
                elif case == 'scope':
                    session.approved_scope.return_value = 'link:identity'
                elif case == 'session_issuer':
                    session.matches_issuer.return_value = False
                else:
                    session.can_upload.return_value = False
                with self.assertRaises(ModeCoordinatorError):
                    self.owner.activate_linked(view, target)
                with self.assertRaises(ModeCoordinatorError):
                    self.owner.view()
                self.assertEqual(0, self.vault.writes)
                session.identity.assert_not_called()
                session.gallery_page.assert_not_called()

    def test_activation_refuses_stale_or_busy_and_changed_evidence(self) -> None:
        """Recheck the owner-issued view, work lifetime and both stores.

        Returns:
            None: No stale, busy or changed observation activates linked work.
        """
        for case in ('stale', 'busy', 'config', 'vault'):
            with self.subTest(case=case):
                self.setUp()
                self.vault.record = self.record
                self.config.anchor = encode_mode_record(self.record)
                view = self.owner.refresh()
                session = Mock(spec=LinkingSession)
                session.matches_issuer.return_value = True
                session.can_upload.return_value = True
                session.approved_scope.return_value = UPLOAD_SCOPE
                target = LinkedUploadTarget(session, 'https://gallery.example', 7, 9, 'Gallery')
                work = None
                if case == 'stale':
                    self.owner.refresh()
                elif case == 'busy':
                    work = self.owner.acquire_work(view, 'reconnect')
                elif case == 'config':
                    self.config.generation += 1
                else:
                    self.vault.record = None
                with self.assertRaises(ModeCoordinatorError):
                    self.owner.activate_linked(view, target)
                if work is not None:
                    work.close()
                if case in ('config', 'vault'):
                    with self.assertRaises(ModeCoordinatorError):
                        self.owner.view()
                self.assertEqual(0, self.vault.writes)

    def test_stale_complete_config_refuses_publish_before_vault_write(self) -> None:
        """A changed config cannot be overwritten using a prior mode choice.

        Returns:
            None: No vault record is written and future admission is unavailable.
        """
        view = self.owner.refresh()
        self.config.generation += 1
        with self.assertRaises(ModeCoordinatorError):
            self.owner.publish(view, self.record)
        self.assertEqual(0, self.vault.writes)
        with self.assertRaises(ModeCoordinatorError):
            self.owner.view()

    def test_work_and_children_block_publishing_until_the_last_close(self) -> None:
        """Keep publication reserved until all accepted work has finished.

        Returns:
            None: Publication succeeds only after parent and child both close.
        """
        view = self.owner.refresh()
        work = self.owner.acquire_work(view, 'legacy')
        child = work.child()
        work.close()
        with self.assertRaises(ModeCoordinatorError):
            self.owner.publish(view, self.record)
        self.assertEqual(0, self.vault.writes)
        child.close()
        linked = self.owner.publish(view, self.record)
        self.assertEqual('linked_disconnected', linked.state)
        with self.assertRaises(ModeCoordinatorError):
            self.owner.acquire_work(linked, 'legacy')

    def test_uncertain_vault_or_anchor_write_closes_admission(self) -> None:
        """A possible side effect cannot leave a reusable legacy decision.

        Returns:
            None: Verifies fail-closed behavior for both publication stages.
        """
        for which in ('vault', 'config'):
            with self.subTest(which=which):
                config = _ConfigPort()
                vault = _VaultPort()
                owner = ModeCoordinator(config, vault)
                view = owner.refresh()
                vault.fail_after_write = which == 'vault'
                config.fail_after_write = which == 'config'
                with self.assertRaises(ModeCoordinatorError):
                    owner.publish(view, self.record)
                with self.assertRaises(ModeCoordinatorError):
                    owner.view()
                with self.assertRaises(ModeCoordinatorError):
                    owner.acquire_work(view, 'legacy')

    def test_current_evidence_is_rechecked_before_each_work_admission(self) -> None:
        """Detect a changed vault instead of using a cached legacy decision.

        Returns:
            None: A stale mode cannot begin another operation.
        """
        view = self.owner.refresh()
        self.vault.record = self.record
        with self.assertRaises(ModeCoordinatorError):
            self.owner.acquire_work(view, 'legacy')
        with self.assertRaises(ModeCoordinatorError):
            self.owner.view()

    def test_stale_evidence_blocks_child_and_next_transport_but_not_close(self) -> None:
        """Never admit dependent work after known loss of mode evidence.

        Returns:
            None: Parent and already admitted child can still release safely.
        """
        view = self.owner.refresh()
        parent = self.owner.acquire_work(view, 'legacy')
        child = parent.child()
        self.config.generation += 1
        with self.assertRaises(ModeCoordinatorError):
            parent.checkpoint()
        with self.assertRaises(ModeCoordinatorError):
            parent.child()
        with self.assertRaises(ModeCoordinatorError):
            child.checkpoint()
        parent.close()
        child.close()
        refreshed = self.owner.refresh()
        self.assertEqual('legacy_unmigrated', refreshed.state)

    def test_context_entry_failure_releases_its_own_lease(self) -> None:
        """Do not strand an active token if context entry detects stale evidence.

        Returns:
            None: Failed entry still allows refresh after evidence is available.
        """
        view = self.owner.refresh()
        work = self.owner.acquire_work(view, 'legacy')
        self.config.generation += 1
        with self.assertRaises(ModeCoordinatorError):
            with work:
                self.fail('stale work entered')
        fresh = self.owner.refresh()
        self.assertEqual('legacy_unmigrated', fresh.state)

    def test_gui_restart_requires_explicit_target_reactivation(self) -> None:
        """Keep a saved linked mode disconnected across a fresh GUI process.

        Returns:
            None: Legacy work remains blocked before and after new activation.
        """
        self.vault.record = self.record
        self.config.anchor = encode_mode_record(self.record)
        owner = GuiModeOwner(self.config, lambda: self.vault)
        owner.initialize()
        self.assertEqual('linked_disconnected', owner.view().state)
        with self.assertRaises(ModeCoordinatorError):
            owner.acquire('legacy', self.config)
        with self.assertRaises(ModeCoordinatorError):
            owner.acquire('linked', self.config)

        session = Mock(spec=LinkingSession)
        session.matches_issuer.return_value = True
        session.can_upload.return_value = True
        session.approved_scope.return_value = UPLOAD_SCOPE
        target = LinkedUploadTarget(session, 'https://gallery.example', 7, 9, 'Gallery')
        active = owner.activate_linked(target)
        self.assertEqual('linked_active', active.state)
        with owner.acquire('linked', self.config) as work:
            work.checkpoint()
        with self.assertRaises(ModeCoordinatorError):
            owner.acquire('legacy', self.config)

        restarted = GuiModeOwner(self.config, lambda: self.vault)
        restarted.initialize()
        self.assertEqual('linked_disconnected', restarted.view().state)
        with self.assertRaises(ModeCoordinatorError):
            restarted.acquire('linked', self.config)
        with self.assertRaises(ModeCoordinatorError):
            restarted.acquire('legacy', self.config)

    def test_gui_publication_requires_idle_verified_storage(self) -> None:
        """Publish through the GUI owner without bypassing admitted work.

        Returns:
            None: Busy publication refuses; successful one remains disconnected.
        """
        owner = GuiModeOwner(self.config, lambda: self.vault)
        owner.initialize()
        with owner.acquire('legacy', self.config):
            with self.assertRaises(ModeCoordinatorError):
                owner.publish(self.record)
        linked = owner.publish(self.record)
        self.assertEqual('linked_disconnected', linked.state)
        self.assertEqual(self.record, self.vault.record)
        self.assertEqual(encode_mode_record(self.record), self.config.anchor)
        with self.assertRaises(ModeCoordinatorError):
            owner.acquire('legacy', self.config)

    def test_gui_activation_mismatch_never_reopens_legacy(self) -> None:
        """Treat bad live target and changed evidence as explicit failure.

        Returns:
            None: Invalid target cannot revive legacy work or mask storage loss.
        """
        self.vault.record = self.record
        self.config.anchor = encode_mode_record(self.record)
        owner = GuiModeOwner(self.config, lambda: self.vault)
        owner.initialize()
        session = Mock(spec=LinkingSession)
        session.matches_issuer.return_value = True
        session.can_upload.return_value = True
        session.approved_scope.return_value = UPLOAD_SCOPE
        target = LinkedUploadTarget(session, 'https://gallery.example', 7, 10, 'Wrong')
        with self.assertRaises(ModeCoordinatorError):
            owner.activate_linked(target)
        with self.assertRaises(ModeCoordinatorError):
            owner.acquire('legacy', self.config)
        with self.assertRaises(ModeCoordinatorError):
            owner.view()

    def test_explicit_target_selection_persists_and_rechecks_after_restart(self) -> None:
        """Rotate only changed destinations, and never auto-activate on restart.

        Returns:
            None: Exact saved linked selection reactivates only after user choice.
        """
        session = Mock(spec=LinkingSession)
        session.matches_issuer.return_value = True
        session.can_upload.return_value = True
        session.approved_scope.return_value = UPLOAD_SCOPE
        first = LinkedUploadTarget(session, 'https://gallery.example', 7, 9, 'First')
        alternate = LinkedUploadTarget(session, 'https://gallery.example', 7, 10, 'Other')
        owner = GuiModeOwner(self.config, lambda: self.vault)
        owner.initialize()
        first_view = owner.select_linked(first)
        self.assertEqual('linked_active', first_view.state)
        self.assertIsNotNone(first_view.record)
        self.assertEqual(9, first_view.record.gallery_id)
        self.assertEqual(first_view.record, self.vault.record)
        initial_writes = self.vault.writes
        first_generation = first_view.record.generation

        restarted = GuiModeOwner(self.config, lambda: self.vault)
        restarted.initialize()
        self.assertEqual('linked_disconnected', restarted.view().state)
        self.assertEqual(first_generation, restarted.select_linked(first).record.generation)
        self.assertEqual(initial_writes, self.vault.writes)
        other = restarted.select_linked(alternate)
        self.assertEqual('linked_active', other.state)
        self.assertEqual(10, other.record.gallery_id)
        self.assertNotEqual(first_generation, other.record.generation)
        self.assertEqual(initial_writes + 1, self.vault.writes)
        with self.assertRaises(ModeCoordinatorError):
            restarted.acquire('legacy', self.config)

        legacy = restarted.select_legacy()
        self.assertEqual('legacy', legacy.state)
        self.assertEqual(other.record.profile_id, legacy.record.profile_id)
        self.assertIsNone(legacy.record.gallery_id)
        with restarted.acquire('legacy', self.config) as work:
            work.checkpoint()
        with self.assertRaises(ModeCoordinatorError):
            restarted.acquire('linked', self.config)

    def test_linked_selection_refuses_busy_or_unavailable_evidence(self) -> None:
        """Keep prior mode untouched when a saved-worker lease is still active.

        Returns:
            None: Neither target selection nor key fallback bypasses active work.
        """
        session = Mock(spec=LinkingSession)
        session.matches_issuer.return_value = True
        session.can_upload.return_value = True
        session.approved_scope.return_value = UPLOAD_SCOPE
        target = LinkedUploadTarget(session, 'https://gallery.example', 7, 9, 'Gallery')
        owner = GuiModeOwner(self.config, lambda: self.vault)
        owner.initialize()
        with owner.acquire('legacy', self.config):
            with self.assertRaises(ModeCoordinatorError):
                owner.select_linked(target)
        self.assertIsNone(self.vault.record)
        linked = owner.select_linked(target)
        with owner.acquire('linked', self.config):
            with self.assertRaises(ModeCoordinatorError):
                owner.select_legacy()
        self.assertEqual(linked.record, self.vault.record)

    def test_explicit_legacy_fallback_never_occurred_during_restart(self) -> None:
        """Preserve disconnected linked mode when no local grant is available.

        Returns:
            None: Only a separate explicit legacy decision can change it.
        """
        self.vault.record = self.record
        self.config.anchor = encode_mode_record(self.record)
        owner = GuiModeOwner(self.config, lambda: self.vault)
        owner.initialize()
        self.assertEqual('linked_disconnected', owner.view().state)
        with self.assertRaises(ModeCoordinatorError):
            owner.acquire('legacy', self.config)
        self.assertEqual('linked_disconnected', owner.view().state)
        legacy = owner.select_legacy()
        self.assertEqual('legacy', legacy.state)
        with owner.acquire('legacy', self.config):
            pass
