# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/desktop_connection.py
# Module Type: Desktop upload connection contract
# Purpose: Present explicit verified connection transitions to desktop views.
# Responsibilities: Carry the current target and typed profile settings without
#   exposing credential storage or upload admission to toolkit widgets.
#   Persist independently approved browsing and upload grants transactionally.
#   Remove one exactly owned saved grant and make one best-effort revoke request.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)

"""Desktop connection and shared settings commands."""

from dataclasses import dataclass
from pathlib import Path
import sys
from threading import Event
import time
from typing import Callable, NamedTuple

from .app_linking import (
    ADMIN_DELETE_SCOPE_SUFFIX, GALLERIES_SCOPE, GALLERY_TREE_CAPABILITY,
    LINKING_GALLERY_ID_MAX,
    ROLLING_GALLERIES_SCOPE, ROLLING_GALLERY_SEARCH_SCOPES,
    ROLLING_PRESENTATION_SCOPES, ROLLING_ADMIN_EDIT_SCOPES, ROLLING_ADMIN_MEDIA_SCOPES,
    ROLLING_SCOPE_SUFFIX,
    SLIDING_ACCESS_CAPABILITY, THUMBNAIL_BATCH_CAPABILITY, LinkedIdentity,
    LinkingError, LinkingGrantRejected, LinkingReconnectRequired, LinkingSession,
    discover_linking_capabilities, linking_issuer, scope_base,
)
from .credential_store import (
    CREDENTIAL_ACCOUNT_ID_MAX, CredentialStoreUnavailable, KeychainCredentialStore,
    NativeCredentialStore, WindowsCredentialStore,
)
from .linked_upload import LinkedUploadTarget
from .offline_linking import (
    OfflineBinding, discard_uncertain_refresh, enable_rolling_renewal, load_offline_binding,
    offline_binding_path, remove_offline_binding, remove_saved_offline_link,
    restore_saved_offline_session, revoke_saved_refresh, save_offline_binding,
    saved_offline_hint_path, store_approved_refresh,
)
from .session_resume import (
    KeychainAccessUnavailable, TransientGrant, delete_transient, load_transient, save_transient,
)
from .upload_mode import ModeRecord

SettingValue = str | bool | int | float
RuntimeValues = dict[str, SettingValue]

_BROWSER_GALLERY_ROLLING_SCOPES = tuple(
    scope for scope in (
        ROLLING_GALLERIES_SCOPE,
        *ROLLING_GALLERY_SEARCH_SCOPES,
        *ROLLING_PRESENTATION_SCOPES,
        *ROLLING_ADMIN_MEDIA_SCOPES,
        *ROLLING_ADMIN_EDIT_SCOPES,
    )
    if (scope.endswith(ROLLING_SCOPE_SUFFIX) and scope_base(scope) == GALLERIES_SCOPE
        and ADMIN_DELETE_SCOPE_SUFFIX not in scope)
)


def gallery_editor_url(session: LinkingSession, issuer: str, gallery_id: int) -> str | None:
    """Build the verified site's fixed gallery editor route for the browser.

    Args:
        session (LinkingSession): Exact live gallery session selected by the operator.
        issuer (str): Canonical installation approved in that session.
        gallery_id (int): Exact physical gallery from the current linked tree.
    Returns:
        str | None: Same-site editor link, or no route for a mismatched input.
    """
    canonical = linking_issuer(issuer + '/index.php')
    if (canonical != issuer or not session.matches_issuer(canonical)
            or type(gallery_id) is not int or not 0 < gallery_id <= LINKING_GALLERY_ID_MAX):
        return None
    return f'{canonical}/index.php?page=admin_edit_gallery&id={gallery_id}&open_panel=1'


@dataclass(frozen=True)
class ConnectionTestResult:
    """Carry non-secret gallery labels from an authenticated API-key check.

    Attributes:
        gallery_label (str): Server's concise gallery name for status display.
        gallery_title (str): Server's optional descriptive gallery title.
    """

    gallery_label: str
    gallery_title: str


class DesktopLinkedSite(NamedTuple):
    """Carry one live gallery approval and its exact verified Admin account.

    Attributes:
        issuer (str): Canonical site address approved by the live session.
        session (LinkingSession): Exact in-memory approval for that site.
        user_id (int): Admin account identifier verified with the approval.
    """

    issuer: str
    session: LinkingSession
    user_id: int


def live_link_sites(
    selected: DesktopLinkedSite | None,
    parked: tuple[DesktopLinkedSite, ...],
) -> tuple[DesktopLinkedSite, ...]:
    """Project the selected and parked sessions that remain available.

    Args:
        selected (DesktopLinkedSite | None): Current site view, when selected.
        parked (tuple[DesktopLinkedSite, ...]): Other retained in-memory links.
    Returns:
        tuple[DesktopLinkedSite, ...]: Available selected site first, followed
            by available parked sites in their existing order.
    """
    available_parked = tuple(site for site in parked if site.session.available())
    if selected is None or not selected.session.available():
        return available_parked
    return (selected, *available_parked)


def find_live_link(
    sites: tuple[DesktopLinkedSite, ...], issuer: str,
) -> DesktopLinkedSite | None:
    """Find a projected live link by its exact canonical issuer.

    Args:
        sites (tuple[DesktopLinkedSite, ...]): Current live-site projection.
        issuer (str): Exact issuer to find, or empty for an invalid field.
    Returns:
        DesktopLinkedSite | None: Matching session and Admin ID, if present.
    """
    return next((site for site in sites if site.issuer == issuer), None)


def verify_live_gallery_activity(site: DesktopLinkedSite) -> None:
    """Check idle approved access using its read-only identity route.

    Args:
        site (DesktopLinkedSite): Exact reusable grant and original Admin account.
    Returns:
        None: A matching response refreshes the existing session's idle deadline.
    Raises:
        LinkingError: The response belongs to a different account or access is over.
    """
    if site.session.identity().user_id != site.user_id:
        site.session.close()
        raise LinkingError('Gallery account changed.')


@dataclass(frozen=True)
class LinkedRecoveryActions:
    """Expose the shared lease and journal owner for one selected linked target.

    Attributes:
        probe (Callable[[LinkedUploadTarget, int], tuple[str, str]]): Observe
            bytes of the explicit size and return the outcome and journal marker.
        is_current (Callable[[LinkedUploadTarget, str], bool]): Verify that
            the observation still belongs to the selected target and marker.
        review (Callable[[LinkedUploadTarget, str | None], str | None]): Read
            the marker or record explicit review of the same marker.
    """

    probe: Callable[[LinkedUploadTarget, int], tuple[str, str]]
    is_current: Callable[[LinkedUploadTarget, str], bool]
    review: Callable[[LinkedUploadTarget, str | None], str | None]


@dataclass(frozen=True)
class ConnectionActions:
    """Select a verified destination or explicitly use the saved API key.

    Attributes:
        select (Callable[[LinkedUploadTarget], None]): Accept a verified link.
        legacy (Callable[[], None]): Explicitly select the saved API key.
        saved (Callable[[], ModeRecord | None]): Read the selected local mode.
        active (Callable[[], LinkedUploadTarget | None]): Read a live verified link.
        test_legacy (Callable[[Event], ConnectionTestResult] | None): Test the
            exact saved API-key generation, when the frontend supports it.
        commit_scope_upgrade (Callable[[ModeRecord, LinkedUploadTarget,
            LinkedUploadTarget], bool] | None): Atomically update Admin scope for
            the unchanged selected target.
        recovery (LinkedRecoveryActions | None): Observe or review an explicitly
            selected target's uncertain outcome through the existing owner.
    """

    select: Callable[[LinkedUploadTarget], None]
    legacy: Callable[[], None]
    saved: Callable[[], ModeRecord | None]
    active: Callable[[], LinkedUploadTarget | None]
    test_legacy: Callable[[Event], ConnectionTestResult] | None = None
    commit_scope_upgrade: Callable[[
        ModeRecord, LinkedUploadTarget, LinkedUploadTarget,
    ], bool] | None = None
    recovery: LinkedRecoveryActions | None = None


@dataclass(frozen=True)
class RuntimeSettings:
    """Read and save native form fields through the persistent config owner."""

    load: Callable[[], RuntimeValues]
    save: Callable[[RuntimeValues], RuntimeValues]


@dataclass(frozen=True)
class DependencyActions:
    """Expose current-runtime status and explicit dependency repair actions.

    Attributes:
        status (Callable[[], str]): Read the current process runtime summary.
        repair (Callable[[], tuple[bool, str]]): Repair required packages in
            the same Python runtime and return success with safe output.
        install_ai (Callable[[], tuple[bool, str]]): Install optional local AI
            packages in the same source runtime and return status with output.
        bundled (bool): Whether this process runs from the standalone bundle.
    """

    status: Callable[[], str]
    repair: Callable[[], tuple[bool, str]]
    install_ai: Callable[[], tuple[bool, str]]
    bundled: bool


def desktop_upload_vault(profile: Path) -> NativeCredentialStore | None:
    """Probe the native store used by the same platform's existing saved mode.

    Args:
        profile (Path): Owner of the existing macOS Keychain lock and hint files.
    Returns:
        NativeCredentialStore | None: Live Windows vault or macOS Keychain,
            or only transient access when native storage is unavailable.
    """
    try:
        if sys.platform == 'win32':
            store = WindowsCredentialStore()
        elif sys.platform == 'darwin':
            store = KeychainCredentialStore(profile)
        else:
            return None
        return store if store.is_available() else None
    except (RuntimeError, OSError):
        return None


def saved_desktop_gallery_binding(path: Path, issuer: str) -> OfflineBinding | None:
    """Find the original native-vault hint for one exact gallery address.

    Args:
        path (Path): Profile's existing global offline-link hint.
        issuer (str): Independently chosen canonical gallery address.
    Returns:
        OfflineBinding | None: Per-site rolling hint, legacy Windows hint or no binding.
    Raises:
        RuntimeError: The selected rolling hint is malformed or unsafe to read.
    """
    binding = load_offline_binding(offline_binding_path(path, issuer), issuer)
    if binding is not None:
        if not binding.scope.endswith(ROLLING_SCOPE_SUFFIX):
            raise LinkingError('Saved gallery binding is invalid.')
        return binding
    if sys.platform == 'win32' and issuer.startswith('https://'):
        # The supported global Windows hint can legitimately belong to another site.
        try:
            return load_offline_binding(path, issuer)
        except RuntimeError:
            return None
    return None


def remember_desktop_transient_link(session: LinkingSession, account_id: int) -> None:
    """Retain an already-approved short-lived link in macOS Keychain.

    Args:
        session (LinkingSession): Original verified transient gallery grant.
        account_id (int): Exact Admin identity verified on that grant.
    Returns:
        None: Stores the original lifetime when native storage is available.
    """
    if sys.platform != 'darwin' or not session.can_list_galleries():
        return
    try:
        snapshot = session.transient_snapshot()
        save_transient(TransientGrant(
            snapshot.issuer, account_id, snapshot.scope, snapshot.expires_at, snapshot.token,
        ))
    except Exception:  # noqa: BLE001
        # Preserve the live grant when optional native persistence fails.
        pass


def restore_desktop_transient_link(
    issuer: str, cancelled: Event,
) -> tuple[LinkingSession, LinkedIdentity] | None:
    """Check one native transient grant without extending its lifetime.

    Args:
        issuer (str): Independently chosen canonical gallery address.
        cancelled (Event): Lifetime of the owning read request.
    Returns:
        tuple[LinkingSession, LinkedIdentity] | None: Verified original session
            with the same account, or None if no usable hint exists.
    Raises:
        CredentialStoreUnavailable: The Keychain read requires interaction or
            native storage is unavailable before any server request.
        LinkingReconnectRequired: The server refused the original grant or account.
        RuntimeError: Native read or verification could not complete.
    """
    if sys.platform != 'darwin' or cancelled.is_set():
        return None
    try:
        grant = load_transient(issuer)
    except KeychainAccessUnavailable:
        raise CredentialStoreUnavailable('Saved gallery access is unavailable.') from None
    if grant is None or 'galleries:list' not in grant.scope.split() or 'offline_access' in grant.scope.split():
        return None
    remaining = grant.expires_at - time.time()
    if remaining <= 0:
        delete_transient(issuer)
        raise LinkingReconnectRequired('Saved gallery access expired.')
    expires_at = time.monotonic() + remaining
    try:
        capabilities = discover_linking_capabilities(issuer, cancelled)
    except Exception:
        if cancelled.is_set():
            raise
        capabilities = frozenset()
    session = LinkingSession(
        issuer, grant.token, expires_at, grant.scope,
        gallery_tree_supported=GALLERY_TREE_CAPABILITY in capabilities,
        thumbnail_batch_supported=THUMBNAIL_BATCH_CAPABILITY in capabilities,
        sliding_access_supported=SLIDING_ACCESS_CAPABILITY in capabilities,
    )
    try:
        identity = session.identity()
        if identity.user_id != grant.account_id:
            raise LinkingGrantRejected('Saved gallery account changed.')
        if cancelled.is_set() or not session.can_list_galleries():
            raise LinkingError('Saved gallery access is unavailable.')
        return session, identity
    except LinkingGrantRejected:
        session.close()
        if not cancelled.is_set():
            delete_transient(issuer)
        raise LinkingReconnectRequired('Saved gallery access expired.') from None
    except Exception:
        session.close()
        raise


def restore_desktop_gallery_link(
    profile: Path, issuer: str, cancelled: Event,
) -> DesktopLinkedSite | None:
    """Restore native Photos browsing without selecting an upload destination.

    Args:
        profile (Path): Current user profile holding the existing binding hint.
        issuer (str): Exact canonical address from the recent list or mode.
        cancelled (Event): Lifetime of the owning Photos read.
    Returns:
        DesktopLinkedSite | None: Verified browsing authority, or no saved grant.
    Raises:
        CredentialStoreUnavailable: The native vault is unavailable before
            refreshing the saved grant. An explicit link can use fresh consent.
        RuntimeError: The saved grant or exact account could not be verified safely.
    """
    if sys.platform not in ('win32', 'darwin') or cancelled.is_set():
        return None
    path = profile / 'offline-link.json'
    binding = saved_desktop_gallery_binding(path, issuer)
    if binding is None:
        restored = restore_desktop_transient_link(issuer, cancelled)
    else:
        store = desktop_upload_vault(profile)
        if store is None:
            raise CredentialStoreUnavailable('Saved gallery access is unavailable.')
        restored = restore_saved_offline_session(
            store, saved_offline_hint_path(path, issuer, binding.scope), binding, cancelled,
        )
        if restored is None and not cancelled.is_set():
            raise LinkingError('The securely saved gallery connection is unavailable.')
    if restored is None:
        return None
    session, identity = restored
    if cancelled.is_set() or not session.can_list_galleries():
        session.close()
        return None
    return DesktopLinkedSite(issuer, session, identity.user_id)


def persist_browser_scope_upgrade(
    legacy_hint_path: Path, store: NativeCredentialStore, binding: OfflineBinding,
    previous_scope: str | None, refresh: str, remaining: int,
    commit: Callable[[], bool] | None = None,
) -> None:
    """Persist one explicitly approved Photos scope upgrade transactionally.

    Args:
        legacy_hint_path (Path): Exact existing hint path owned by the caller.
        store (NativeCredentialStore): Existing current-user credential owner.
        binding (OfflineBinding): Exact newly approved issuer/account/scope.
        previous_scope (str | None): Exact prior binding scope, or None when the
            old session did not have persistent offline access.
        refresh (str): Refresh credential returned by this browser exchange.
        remaining (int): Conservative remaining lifetime from that exchange.
        commit (Callable[[], bool] | None): Optional host mode publication run
            after secure persistence; failure restores the prior hint and vault.
    Returns:
        None: The new hint and vault generation, plus optional mode, are accepted.
    Raises:
        LinkingError: The prior scope changed, persistence failed, or host commit
            did not accept this exact upgrade.
        RuntimeError: Native storage or binding-hint publication failed.
    """
    issuer, account = binding.issuer, binding.account_id
    path = saved_offline_hint_path(legacy_hint_path, issuer, binding.scope)
    preceding = (
        OfflineBinding(issuer, account, previous_scope)
        if previous_scope is not None and previous_scope.endswith(' offline_access')
        else None
    )
    prior_path = (
        saved_offline_hint_path(legacy_hint_path, issuer, preceding.scope)
        if preceding is not None else path
    )
    migrating = prior_path != path

    def restore(original: bytes | None) -> None:
        """Restore the original vault bytes and exact preceding hint.

        Args:
            original (bytes | None): Existing vault record before publication.
        Returns:
            None: Restores prior state or removes the new state if none existed.
        """
        try:
            if original is not None:
                store.write(issuer, account, original)
            else:
                store.delete(issuer, account)
        finally:
            if migrating and preceding is not None:
                if load_offline_binding(prior_path, issuer) is None:
                    save_offline_binding(prior_path, preceding)
                remove_offline_binding(path, binding)
            elif preceding is not None:
                save_offline_binding(path, preceding, replaces=binding)
            else:
                remove_offline_binding(path, binding)

    with store.binding_transaction(issuer, account):
        original = store.read(issuer, account)
        if ((original is None) != (preceding is None)
                or (migrating and load_offline_binding(prior_path, issuer) != preceding)):
            raise LinkingError('Saved gallery credential changed.')
        save_offline_binding(path, binding, replaces=None if migrating else preceding)
        try:
            store_approved_refresh(store, issuer, account, binding.scope, refresh, remaining)
            published = store.read(issuer, account)
            if published is None:
                raise LinkingError('Saved gallery credential could not be verified.')
            if migrating and preceding is not None:
                remove_offline_binding(prior_path, preceding)
        except Exception:
            restore(original)
            raise
    if commit is None:
        return
    try:
        # The host admission callback owns its own exact mode transaction.
        if not commit():
            raise LinkingError('Linked upload mode could not be verified.')
    except Exception:
        with store.binding_transaction(issuer, account):
            if (load_offline_binding(path, issuer) == binding
                    and store.read(issuer, account) == published
                    and (not migrating or not prior_path.exists())):
                restore(original)
        raise


def persist_browser_gallery_grant(
    profile: Path, store: NativeCredentialStore, issuer: str,
    identity: LinkedIdentity, session: LinkingSession, refresh: str,
    remaining: int, cancelled: Event,
) -> OfflineBinding:
    """Save an exact rolling Photos grant including approved gallery edits.

    Browser Photos consent may authorize gallery details, creation, editing
    and visibility. Upload and photo deletion retain their own approval paths.

    Args:
        profile (Path): Current per-user profile for the non-secret hint.
        store (NativeCredentialStore): Existing current-user vault owner.
        issuer (str): Canonical installation approved by browser consent.
        identity (LinkedIdentity): Admin identity just verified on the session.
        session (LinkingSession): The exact newly approved read-only session.
        refresh (str): Refresh credential from that same browser exchange.
        remaining (int): Conservative refresh lifetime remaining in seconds.
        cancelled (Event): Owning browser/link request lifetime.
    Returns:
        OfflineBinding: Exact durable issuer/account/listing-scope binding.
    Raises:
        LinkingError: Scope, identity, session, ownership or storage is unsafe.
        RuntimeError: Native storage or binding-hint publication failed.
    """
    if (not isinstance(identity, LinkedIdentity)
            or type(identity.user_id) is not int
            or not 0 < identity.user_id <= CREDENTIAL_ACCOUNT_ID_MAX
            or not isinstance(cancelled, Event) or cancelled.is_set()
            or type(remaining) is not int or remaining <= 0):
        raise LinkingError('Secure gallery browsing storage is unavailable.')
    try:
        canonical = linking_issuer(issuer + '/index.php')
        scope = session.approved_scope()
        valid_session = (
            canonical == issuer and scope in _BROWSER_GALLERY_ROLLING_SCOPES
            and scope_base(scope) == GALLERIES_SCOPE
            and session.matches_issuer(issuer)
            and session.can_list_galleries() and not session.can_upload()
            and session.available() and not cancelled.is_set()
        )
        verified_identity = session.identity() if valid_session else None
    except Exception:
        raise LinkingError('Secure gallery browsing storage is unavailable.') from None
    if verified_identity != identity or cancelled.is_set():
        raise LinkingError('Secure gallery browsing storage is unavailable.')

    binding = OfflineBinding(issuer, identity.user_id, scope)
    legacy_path = profile / 'offline-link.json'
    path = offline_binding_path(legacy_path, issuer)
    with store.binding_transaction(issuer, identity.user_id):
        if cancelled.is_set() or not session.available():
            raise LinkingError('Secure gallery browsing storage is unavailable.')
        previous = load_offline_binding(path, issuer)
        try:
            legacy = load_offline_binding(legacy_path, issuer)
        except RuntimeError:
            # The shared historical hint may belong to a different issuer.
            legacy = None
        if ((legacy is not None and legacy != binding)
                or (previous is not None and previous != binding)):
            raise LinkingError('Saved gallery credential changed.')
        original = store.read(issuer, identity.user_id)
        if previous is None and original is not None:
            raise LinkingError('Saved gallery credential changed.')
        if previous == binding:
            discard_uncertain_refresh(store, issuer, identity.user_id)
        else:
            save_offline_binding(path, binding)
        try:
            store_approved_refresh(
                store, issuer, identity.user_id, scope, refresh, remaining,
            )
            published = store.read(issuer, identity.user_id)
            if (published is None or cancelled.is_set()
                    or not session.available()):
                raise LinkingError('Secure gallery browsing storage is unavailable.')
        except Exception:
            try:
                if original is None:
                    store.delete(issuer, identity.user_id)
                else:
                    store.write(issuer, identity.user_id, original)
            finally:
                if previous is None:
                    remove_offline_binding(path, binding)
            raise
    enable_rolling_renewal(session, store, path, binding)
    return binding


def persist_browser_upload_grant(
    profile: Path, store: NativeCredentialStore, issuer: str, identity: LinkedIdentity,
    session: LinkingSession, refresh: str, remaining: int, cancelled: Event,
    *, previous_mode: ModeRecord | None = None,
) -> None:
    """Publish verified browser consent under the original native binding owner.

    Args:
        profile (Path): Current per-user profile for the non-secret restart hint.
        store (NativeCredentialStore): Verified current-user native vault.
        issuer (str): Exact browser installation independently verified.
        identity (LinkedIdentity): Live account verified with the approved bearer.
        session (LinkingSession): The exact newly approved upload session.
        refresh (str): In-memory credential from that one-use exchange.
        remaining (int): Conservative remaining credential lifetime in seconds.
        cancelled (Event): Owning request lifetime before durable publication.
        previous_mode (ModeRecord | None): Exact selected account, issuer and
            scope already reviewed on an explicit saved-link reapproval.
    Returns:
        None: The same scope is available for future verified restart.
    Raises:
        LinkingError: Existing authority conflicts or the new grant is unavailable.
    """
    scope = session.approved_scope()
    expected = (issuer, identity.user_id, scope)
    reapproved = (
        previous_mode is not None and previous_mode.mode == 'linked'
        and (previous_mode.issuer, previous_mode.account_id, previous_mode.scope) == expected
    )
    if (cancelled.is_set() or remaining <= 0 or not session.can_upload()
            or not session.matches_issuer(issuer) or not scope.endswith(' offline_access')
            or previous_mode is not None and not reapproved
            or not reapproved and not scope.endswith(ROLLING_SCOPE_SUFFIX)):
        raise LinkingError('Secure offline storage is unavailable.')
    binding = OfflineBinding(issuer, identity.user_id, scope)
    path = saved_offline_hint_path(profile / 'offline-link.json', issuer, scope)
    with store.binding_transaction(issuer, identity.user_id):
        if cancelled.is_set() or not session.available():
            raise LinkingError('Secure offline storage is unavailable.')
        _require_original_hint_owner(profile, binding)
        previous = load_offline_binding(path, issuer)
        if previous == binding:
            # Fresh browser authorization may replace the exact old credential.
            discard_uncertain_refresh(store, issuer, identity.user_id)
        elif previous is not None or store.read(issuer, identity.user_id) is not None:
            raise LinkingError('Saved gallery credential changed.')
        else:
            # The same non-secret restart hint survives interrupted vault storage.
            save_offline_binding(path, binding)
        store_approved_refresh(store, issuer, identity.user_id, scope, refresh, remaining)
    enable_rolling_renewal(session, store, path, binding)


def open_saved_upload_session(
    profile: Path, record: ModeRecord, cancelled: Event,
) -> LinkingSession:
    """Resume only the same securely saved upload grant with verified evidence.

    Args:
        profile (Path): Current per-user WinApp profile.
        record (ModeRecord): Exact saved linked uploader account, gallery and scope.
        cancelled (Event): Owner-controlled lifetime of this explicit reconnect.
    Returns:
        LinkingSession: Verified native session with ordinary rolling support.
    Raises:
        LinkingError: Original grant, account, scope or native storage is unavailable.
    """
    path, binding = _saved_upload_binding(profile, record)
    store = desktop_upload_vault(profile)
    if store is None:
        raise LinkingError('The securely saved gallery connection is unavailable.')
    restored = restore_saved_offline_session(store, path, binding, cancelled)
    if restored is None:
        raise LinkingError('The securely saved gallery connection is unavailable.')
    session, _identity = restored
    if not session.can_upload():
        session.close()
        raise LinkingError('Gallery connection is unavailable.')
    return session


def forget_browser_upload_grant(profile: Path, record: ModeRecord) -> None:
    """Forget exactly the selected account's local offline grant.

    Args:
        profile (Path): Current per-user profile holding the bound hint.
        record (ModeRecord): Existing linked choice approved for local removal.
    Returns:
        None: Removes the matching local hint and credential without revocation.
    Raises:
        LinkingError: The grant is missing, mismatched or cannot be verified.
        RuntimeError: The native store could not finish removal safely.
    """
    path, binding = _saved_upload_binding(profile, record)
    store = desktop_upload_vault(profile)
    if store is None:
        raise LinkingError('The securely saved gallery connection is unavailable.')
    remove_saved_offline_link(
        store, path, binding, verify=lambda: _require_original_hint_owner(profile, binding),
    )


def revoke_saved_gallery_grant(
    profile: Path, issuer: str, account_id: int, binding: OfflineBinding,
    store: NativeCredentialStore,
) -> bool | None:
    """Remove and revoke only the exact currently saved gallery grant.

    Args:
        profile (Path): Current user's profile holding the original hint.
        issuer (str): Canonical issuer selected for this disconnect operation.
        account_id (int): Live Admin identity selected for the operation.
        binding (OfflineBinding): Exact persisted scope and issuer being removed.
        store (NativeCredentialStore): Same current-user vault used by the link.
    Returns:
        bool | None: True when one captured refresh was revoked, False when
            revocation failed, or None when only the live access bearer can be
            revoked by the caller.
    Raises:
        LinkingError: Issuer, account, or original saved hint no longer matches.
        RuntimeError: Local credential removal could not complete safely.
    """
    canonical = linking_issuer(issuer + '/index.php')
    if (canonical != issuer or binding.issuer != issuer
            or binding.account_id != account_id or type(account_id) is not int
            or not 0 < account_id <= CREDENTIAL_ACCOUNT_ID_MAX):
        raise LinkingError('Saved gallery connection changed.')
    path = saved_offline_hint_path(profile / 'offline-link.json', issuer, binding.scope)

    def verify_owner() -> None:
        """Require the exact preexisting hint while the vault lock is held.

        Returns:
            None: Only the exact original owner can be removed.
        Raises:
            LinkingError: The owner changed or the original hint disappeared.
        """
        _require_original_hint_owner(profile, binding)
        if load_offline_binding(path, issuer) != binding:
            raise LinkingError('Saved gallery connection changed.')

    record = remove_saved_offline_link(
        store, path, binding, capture_refresh=True, verify=verify_owner,
    )
    if record is None:
        return None
    try:
        revoke_saved_refresh(binding, record)
    except (LinkingError, RuntimeError, OSError):
        return False
    return True


def _saved_upload_binding(profile: Path, record: ModeRecord) -> tuple[Path, OfflineBinding]:
    """Read one scoped hint matching the existing selected upload mode.

    Args:
        profile (Path): Root holding the rolling and historical hint formats.
        record (ModeRecord): Approved upload choice read from the existing mode.
    Returns:
        tuple[Path, OfflineBinding]: Current verifiable hint with original owner.
    Raises:
        LinkingError: The mode, issuer, account or original scope is unavailable.
    """
    issuer, account, scope = record.issuer, record.account_id, record.scope
    if record.mode != 'linked' or issuer is None or account is None or not scope or not scope.endswith(' offline_access'):
        raise LinkingError('Gallery connection is unavailable.')
    path = saved_offline_hint_path(profile / 'offline-link.json', issuer, scope)
    binding = load_offline_binding(path, issuer)
    if binding is None:
        raise LinkingReconnectRequired('The securely saved gallery connection is unavailable.')
    if (binding.issuer, binding.account_id, binding.scope) != (issuer, account, scope):
        raise LinkingError('Gallery connection is unavailable.')
    return path, binding


def _require_original_hint_owner(profile: Path, binding: OfflineBinding) -> None:
    """Refuse legacy vault writes when rolling linking owns the same vault slot.

    Args:
        profile (Path): Current authenticated user's existing hint directory.
        binding (OfflineBinding): Exact approved binding for a local mutation.
    Returns:
        None: A current rolling hint or an unambiguous legacy hint may proceed.
    Raises:
        LinkingError: A migrated binding owns the same native credential slot.
        RuntimeError: The competing rolling hint cannot be verified safely.
    """
    if binding.scope.endswith(ROLLING_SCOPE_SUFFIX):
        return
    rolling_path = offline_binding_path(profile / 'offline-link.json', binding.issuer)
    rolling = load_offline_binding(rolling_path, binding.issuer)
    if rolling is not None and rolling.account_id == binding.account_id:
        raise LinkingError('Saved gallery credential changed.')
