# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/tests/test_mode_admission.py
# Module Type: Regression Test
# Purpose: Exercise isolated mode and work lifecycle coordination.
# Responsibilities:
#   - Refuse stale admission and transitions while parent or child work runs.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Portable source fixtures; this module does not invoke Windows APIs."""

import threading
import unittest

from uploader.app_linking import UPLOAD_SCOPE
from uploader.mode_admission import ModeAdmission, ModeAdmissionError, ModeView
from uploader.upload_mode import ModeRecord, profile_path_digest


# Purpose: Bound the isolated thread fixture when a regression causes a hang.
# Type: float. Units: seconds. Scope: one work-lease release thread.
# Consumers: ModeAdmissionTests.test_work_can_finish_on_another_thread.
# Rationale: 5 seconds leaves scheduler time while preventing an indefinite wait.
MODE_FIXTURE_THREAD_TIMEOUT_SECONDS = 5.0


class ModeAdmissionTests(unittest.TestCase):
    """Verify process-local leases without integrating them into uploaders."""

    def setUp(self) -> None:
        """Prepare one valid, non-secret linked target record.

        Returns:
            None: Stores a test-only linked destination binding.
        """
        self.record = ModeRecord('a' * 32, 'b' * 32, 'linked',
                                 profile_path_digest(r'C:\Users\Tester\config.json'),
                                 'https://gallery.example', 7, UPLOAD_SCOPE, 9)

    def test_legacy_unmigrated_remains_usable_after_host_verification(self) -> None:
        """Keep a positively verified old profile usable without a prompt.

        Returns:
            None: Legacy and explicit revoke work hold separate leases.
        """
        owner = ModeAdmission('legacy_unmigrated')
        view = owner.view()
        with owner.acquire_work(view, 'legacy') as upload:
            with owner.acquire_work(view, 'legacy_revoke') as revoke:
                self.assertEqual('legacy', upload.kind)
                self.assertEqual('legacy_revoke', revoke.kind)
                with self.assertRaises(ModeAdmissionError):
                    owner.begin_transition(view)

    def test_unknown_and_disconnected_cannot_admit_legacy_or_linked_upload(self) -> None:
        """Never turn absent or disconnected linked state into a legacy target.

        Returns:
            None: Checks fail-closed modes and explicit reconnect category.
        """
        for state, record in (('unknown', None), ('first_setup', None),
                              ('linked_disconnected', self.record)):
            with self.subTest(state=state):
                owner = ModeAdmission(state, record)
                view = owner.view()
                for kind in ('legacy', 'legacy_revoke', 'linked'):
                    with self.assertRaises(ModeAdmissionError):
                        owner.acquire_work(view, kind)
                with owner.acquire_work(view, 'local'):
                    pass
                if state == 'linked_disconnected':
                    with owner.acquire_work(view, 'reconnect'):
                        pass
                else:
                    with self.assertRaises(ModeAdmissionError):
                        owner.acquire_work(view, 'reconnect')

    def test_parent_release_and_double_release_keep_child_active(self) -> None:
        """Do not unblock a transition while dependent drain work remains.

        Returns:
            None: Child and another parent each retain independent lifetimes.
        """
        owner = ModeAdmission('legacy_unmigrated')
        view = owner.view()
        parent = owner.acquire_work(view, 'legacy')
        child = parent.child()
        other = owner.acquire_work(view, 'legacy')
        parent.close()
        parent.close()
        with self.assertRaises(ModeAdmissionError):
            parent.child()
        with self.assertRaises(ModeAdmissionError):
            owner.begin_transition(view)
        child.close()
        child.close()
        with self.assertRaises(ModeAdmissionError):
            owner.begin_transition(view)
        other.close()
        with owner.begin_transition(view) as transition:
            new_view = transition.commit('linked_disconnected', self.record)
        self.assertEqual('linked_disconnected', new_view.state)

    def test_transition_reservation_and_uncertain_exit_fail_closed(self) -> None:
        """Block admission during external publication and forget uncertain state.

        Returns:
            None: An uncommitted transition closes into unknown with a new view.
        """
        owner = ModeAdmission('legacy_unmigrated')
        old = owner.view()
        transition = owner.begin_transition(old)
        with self.assertRaises(ModeAdmissionError):
            owner.view()
        with self.assertRaises(ModeAdmissionError):
            owner.acquire_work(old, 'legacy')
        with self.assertRaises(ModeAdmissionError):
            owner.begin_transition(old)
        transition.close()
        transition.close()
        self.assertEqual('unknown', owner.view().state)
        with self.assertRaises(ModeAdmissionError):
            owner.acquire_work(old, 'legacy')
        with self.assertRaises(ModeAdmissionError):
            transition.commit('legacy_unmigrated')

    def test_stale_view_and_forged_shape_never_admit(self) -> None:
        """Reject previous-generation views and inconsistent target bindings.

        Returns:
            None: A transition changes identity even for equal-looking values.
        """
        owner = ModeAdmission('legacy_unmigrated')
        old = owner.view()
        forged = ModeView('legacy_unmigrated', None)
        with self.assertRaises(ModeAdmissionError):
            owner.acquire_work(forged, 'legacy')
        with self.assertRaises(ModeAdmissionError):
            owner.begin_transition(forged)
        with owner.begin_transition(old) as transition:
            new = transition.commit('legacy_unmigrated')
        self.assertIsNot(old, new)
        with self.assertRaises(ModeAdmissionError):
            owner.acquire_work(old, 'legacy')
        with self.assertRaises(ModeAdmissionError):
            ModeAdmission('linked_active', None)
        with self.assertRaises(ModeAdmissionError):
            ModeAdmission('unknown', self.record)
        with self.assertRaises(ModeAdmissionError):
            ModeAdmission('legacy', None)

    def test_work_can_finish_on_another_thread(self) -> None:
        """Release a worker lease without moving a native mutex across threads.

        Returns:
            None: Another thread closes work before a new transition is admitted.
        """
        owner = ModeAdmission('linked_active', self.record)
        view = owner.view()
        lease = owner.acquire_work(view, 'linked')
        worker = threading.Thread(target=lease.close)
        worker.start()
        worker.join(MODE_FIXTURE_THREAD_TIMEOUT_SECONDS)
        self.assertFalse(worker.is_alive())
        with owner.begin_transition(view) as transition:
            self.assertEqual('unknown', transition.commit('unknown').state)
