# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: tests/gallery_http_compare_cookie_test.py
# Module Type: Offline Measurement Regression Fixture
# Purpose: Verify actual comparison-runner cookie transport without HTTP or Git.
# Responsibilities: Mock only curl subprocess I/O; reject invalid session cohorts.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)

import hashlib
import importlib.util
import json
import pathlib
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch

sys.dont_write_bytecode = True
SPEC = importlib.util.spec_from_file_location(
    'gallery_http_compare_cookie_owner',
    pathlib.Path(__file__).resolve().parent.parent / 'scripts/gallery_http_compare.py',
)
if SPEC is None or SPEC.loader is None:
    raise RuntimeError('Actual comparison runner could not be imported.')
RUNNER = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(RUNNER)
COOKIE_NAME = 'gallery_admin_session'
TOKEN_A = 'fixtureTokenAlpha123'
TOKEN_B = 'fixtureTokenBeta456'


class CookieTransportTest(unittest.TestCase):
    """Exercise the real request builder/parser using private fake curl files."""

    def setUp(self) -> None:
        """Create a private temporary response root and empty transport observations.

        Returns:
            None: Initializes isolated per-case state without network access.
        """
        self.temporary = tempfile.TemporaryDirectory(prefix='gallery-cookie-contract-')
        self.directory = pathlib.Path(self.temporary.name)
        self.responses = []
        self.commands = []
        self.same = {'name': 'same', 'url': 'http://localhost/Galerie/', 'concurrency': 1, 'session': 'same'}
        self.fresh = dict(self.same, name='fresh', session='fresh')

    def tearDown(self) -> None:
        """Remove the exclusively owned response fixture tree.

        Returns:
            None: Releases temporary response files after each scenario.
        """
        self.temporary.cleanup()

    def curl(
        self,
        command: list[str],
        capture_output: bool,
        timeout: int,
        check: bool,
    ) -> subprocess.CompletedProcess[bytes]:
        """Replace only curl's external I/O while exercising real request parsing.

        Args:
            command (list[str]): Actual runner-created subprocess argument array.
            capture_output (bool): Required captured subprocess transport mode.
            timeout (int): Actual finite external-process deadline in seconds.
            check (bool): Runner-owned return-code handling switch.
        Returns:
            subprocess.CompletedProcess[bytes]: Successful fake curl timing stdout.
        """
        self.assertTrue(command[0] == 'curl-fixture', 'Only the external curl boundary may execute.')
        self.assertTrue(capture_output and timeout > 0 and not check, 'Curl invocation must retain bounded captured I/O.')
        self.commands.append(command)
        headers = pathlib.Path(command[command.index('--dump-header') + 1])
        body = pathlib.Path(command[command.index('--output') + 1])
        headers.write_text('HTTP/1.1 200 OK\r\n' + self.responses.pop(0) + '\r\n', encoding='latin-1', newline='')
        body.write_bytes(b'<img src="public.webp" data-image-id="1">')
        return subprocess.CompletedProcess(command, 0, b'200 0.01 0.02 0.001 45', b'')

    def test_custom_cookie_is_initialized_sent_and_redacted(self) -> None:
        """Prove cookieless discovery and subsequent transport of the configured name.

        Returns:
            None: Requires correct session transmission and value-free evidence.
        """
        self.responses = [
            'Set-Cookie: unrelated=ignored; Path=/\r\nSet-Cookie: ' + COOKIE_NAME + '=' + TOKEN_A + '; Path=/; HttpOnly\r\n',
            'Set-Cookie: ' + COOKIE_NAME + '=' + TOKEN_A + '; Path=/\r\n',
            '',
        ]
        with patch.object(RUNNER.subprocess, 'run', side_effect=self.curl):
            warmup = RUNNER.request('curl-fixture', self.same, '', self.directory, COOKIE_NAME, True)
            token = warmup.pop('_session')
            measured = RUNNER.request('curl-fixture', self.same, token, self.directory, COOKIE_NAME)
            measured.pop('_session')
            retained = RUNNER.request('curl-fixture', self.same, token, self.directory, COOKIE_NAME)
            retained.pop('_session')
        self.assertTrue('--cookie' not in self.commands[0], 'Warmup must send no Cookie header.')
        self.assertTrue(self.commands[1][self.commands[1].index('--cookie') + 1] == COOKIE_NAME + '=' + TOKEN_A, 'Measured curl must send the configured session name and initialized token.')
        self.assertTrue('--resolve' in self.commands[1], 'Localhost must remain pinned.')
        self.assertTrue(warmup['session_initialized'] and not warmup['session_cookie_sent'], 'Warmup evidence must distinguish initialization from sent cookies.')
        self.assertTrue(measured['session_initialized'] and measured['session_cookie_sent'] and measured['session_consistent'], 'Measured evidence must prove initialized consistent transport.')
        serialized = json.dumps([warmup, measured, retained])
        self.assertTrue(TOKEN_A not in serialized and hashlib.sha256(TOKEN_A.encode()).hexdigest() not in serialized, 'Persistable evidence must contain neither session value nor its hash.')
        self.assertTrue(not list(self.directory.iterdir()), 'Private raw response files must be removed.')

    def test_missing_or_wrong_warmup_cookie_fails(self) -> None:
        """Reject a same-session cohort whose warmup did not issue the exact name.

        Returns:
            None: Requires failure for absent, unrelated or wrong-case cookies.
        """
        for response in ['', 'Set-Cookie: PHPSESSID=' + TOKEN_A + '\r\n', 'Set-Cookie: GALLERY_ADMIN_SESSION=' + TOKEN_A + '\r\n']:
            with self.subTest(case='missing-exact-cookie'):
                self.responses = [response]
                with patch.object(RUNNER.subprocess, 'run', side_effect=self.curl):
                    with self.assertRaises(RuntimeError):
                        RUNNER.request('curl-fixture', self.same, '', self.directory, COOKIE_NAME, True)

    def test_malformed_duplicate_conflicting_and_rotated_tokens_fail(self) -> None:
        """Reject ambiguous initialization and invalid measured session continuity.

        Returns:
            None: Requires failure without accepting malformed or replaced tokens.
        """
        malformed = [
            'Set-Cookie: ' + COOKIE_NAME + '=\r\n',
            'Set-Cookie: ' + COOKIE_NAME + '=bad token\r\n',
            'Set-Cookie: ' + COOKIE_NAME + '="quoted"\r\n',
            'Set-Cookie: ' + COOKIE_NAME + ' =value\r\n',
            'Set-Cookie: ' + COOKIE_NAME + '=' + 'x' * (RUNNER.SESSION_TOKEN_MAX_LENGTH + 1) + '\r\n',
            'Set-Cookie: ' + COOKIE_NAME + '=' + TOKEN_A + '\r\nSet-Cookie: ' + COOKIE_NAME + '=' + TOKEN_A + '\r\n',
            'Set-Cookie: ' + COOKIE_NAME + '=' + TOKEN_A + '\r\nSet-Cookie: ' + COOKIE_NAME + '=' + TOKEN_B + '\r\n',
        ]
        for response in malformed:
            self.responses = [response]
            with patch.object(RUNNER.subprocess, 'run', side_effect=self.curl):
                with self.assertRaises(RuntimeError):
                    RUNNER.request('curl-fixture', self.same, '', self.directory, COOKIE_NAME, True)
        for response in malformed + ['Set-Cookie: ' + COOKIE_NAME + '=' + TOKEN_B + '\r\n']:
            self.responses = [response]
            with patch.object(RUNNER.subprocess, 'run', side_effect=self.curl):
                with self.assertRaises(RuntimeError):
                    RUNNER.request('curl-fixture', self.same, TOKEN_A, self.directory, COOKIE_NAME)

    def test_fresh_never_sends_or_adopts_cookies(self) -> None:
        """Keep fresh responses unaffiliated even when the server issues cookies.

        Returns:
            None: Requires an empty private token and no outgoing cookie transport.
        """
        self.responses = ['Set-Cookie: ' + COOKIE_NAME + '=' + TOKEN_A + '\r\n']
        with patch.object(RUNNER.subprocess, 'run', side_effect=self.curl):
            result = RUNNER.request('curl-fixture', self.fresh, '', self.directory, COOKIE_NAME)
        self.assertTrue('--cookie' not in self.commands[0] and result['_session'] == '', 'Fresh request must neither send nor adopt a session.')
        self.assertTrue(not result['session_initialized'] and not result['session_cookie_sent'] and result['session_consistent'] is None, 'Fresh evidence must not claim same-session qualification.')
        with self.assertRaises(ValueError):
            RUNNER.request('curl-fixture', self.fresh, TOKEN_A, self.directory, COOKIE_NAME)

    def test_cli_requires_explicit_name_before_external_operations(self) -> None:
        """Exercise actual CLI admission before any Git or curl process can start.

        Returns:
            None: Requires failure for a same-session case without its cookie-name argument.
        """
        cases = self.directory / 'cases.json'
        cases.write_text(json.dumps([self.same]), encoding='utf-8')
        original_arguments = sys.argv
        sys.argv = [
            'gallery_http_compare.py', '--baseline', 'a' * 40,
            '--candidate', 'b' * 40, '--branch', 'feature/offline-cookie-fixture',
            '--cases', str(cases), '--output', str(self.directory / 'new-output'),
        ]
        try:
            with patch.object(RUNNER.subprocess, 'run', side_effect=self.curl):
                with self.assertRaises(ValueError):
                    RUNNER.main()
        finally:
            sys.argv = original_arguments
        self.assertTrue(not self.commands, 'Missing cookie-name CLI admission must fail before external operations.')

    def test_explicit_safe_name_and_initialization_are_required(self) -> None:
        """Reject missing/unsafe names and measured requests without initialized state.

        Returns:
            None: Confirms required CLI validation and request preconditions.
        """
        self.assertTrue(RUNNER.validate_session_cookie_name(None, False) is None, 'Fresh-only runs need no cookie name.')
        for name in [None, '', 'name=value', 'name;header', 'name-with-dash', 'name\r\nInjected', 'x' * (RUNNER.SESSION_COOKIE_NAME_MAX_LENGTH + 1)]:
            with self.assertRaises(ValueError):
                RUNNER.validate_session_cookie_name(name, True)
        with self.assertRaises(ValueError):
            RUNNER.request('curl-fixture', self.same, '', self.directory, COOKIE_NAME)
        with self.assertRaises(ValueError):
            RUNNER.request('curl-fixture', self.same, TOKEN_A, self.directory, COOKIE_NAME, True)


if __name__ == '__main__':
    unittest.main()
