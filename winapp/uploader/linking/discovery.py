# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/linking/discovery.py
# Module Type: Windows Client Linking Discovery
# Purpose: Validate announced linking capabilities and select supported scopes.
# Responsibilities:
#   - Keep capability policy beside the bounded transport operation it consumes.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Validate announced linking capabilities and select supported scopes."""

import threading

from ..models import JsonValue
from .core import (
    ADMIN_MEDIA_SCOPE_SUFFIX,
    ADMIN_PRESENTATION_SCOPE_SUFFIX,
    BROWSER_HANDOFF_VERSION,
    CREATE_SCOPE,
    GALLERIES_SCOPE,
    GALLERY_EDIT_SCOPE_SUFFIX,
    GALLERY_SEARCH_SUFFIX,
    IDENTITY_SCOPE,
    LINKING_DISCOVERY_CONTRACT_VERSION,
    LINKING_PROTOCOL_VERSION,
    READ_SCOPE,
    READ_UPLOAD_SCOPE,
    ROLLING_ADMIN_DELETE_SCOPES,
    ROLLING_ADMIN_EDIT_SCOPES,
    ROLLING_ADMIN_MEDIA_SCOPES,
    ROLLING_PRESENTATION_SCOPES,
    ROLLING_SCOPE_SUFFIX,
    UPLOAD_CREATE_SCOPE,
    UPLOAD_SCOPE,
    LinkingError,
    scope_allows_admin_media,
    scope_allows_presentation,
)
from .transport import _request_json


def _discovery_admits_linking(
    data: dict[str, JsonValue], issuer: str, scope: str,
) -> bool:
    """Check the exact public capability envelope before sending a push.

    Args:
        data (dict[str, JsonValue]): Public discovery with integer
            contract_version and an app_linking object containing available,
            protocol_version, browser_handoff_version, issuer and the list
            of supported capability names.
        issuer (str): Canonical issuer selected for this attempt.
        scope (str): Exact registered scope bundle requested by the user.

    Returns:
        bool: True only for supported versions, issuer and requested scopes.
    """
    linking = data.get('app_linking')
    if (type(data.get('contract_version')) is not int
            or data['contract_version'] != LINKING_DISCOVERY_CONTRACT_VERSION
            or not isinstance(linking, dict)
            or linking.get('available') is not True
            or type(linking.get('protocol_version')) is not int
            or linking['protocol_version'] != LINKING_PROTOCOL_VERSION
            or type(linking.get('browser_handoff_version')) is not int
            or linking['browser_handoff_version'] != BROWSER_HANDOFF_VERSION
            or linking.get('issuer') != issuer):
        return False
    capabilities = linking.get('capabilities')
    if (not isinstance(capabilities, list)
            or any(not isinstance(value, str) for value in capabilities)):
        return False
    granted = set(capabilities)
    required = set(scope.split()) - {IDENTITY_SCOPE}
    if scope.endswith(ROLLING_SCOPE_SUFFIX):
        # Rolling has its own transport policy. Direct localhost need not
        # advertise the legacy HTTPS-only offline capability.
        required.remove('offline_access')
    if (scope in ROLLING_PRESENTATION_SCOPES
            or (scope in (*ROLLING_ADMIN_MEDIA_SCOPES, *ROLLING_ADMIN_DELETE_SCOPES,
                          *ROLLING_ADMIN_EDIT_SCOPES)
                and scope_allows_presentation(scope))):
        required.add('presentation:rolling')
    return 'linking' in granted and required.issubset(granted)


def _preferred_admin_media_scope(
    discovery: dict[str, JsonValue], issuer: str, base: str,
    rolling: bool, presentation: bool,
) -> str | None:
    """Select the advertised normal Photos link for explicit browser consent.

    Args:
        discovery (dict[str, JsonValue]): Current installation capabilities.
        issuer (str): Exact trusted installation issuer.
        base (str): Basic gallery listing bundle with optional approved upload request.
        rolling (bool): Whether the current client can securely store renewal.
        presentation (bool): Whether native presentation support is preferred.

    Returns:
        str | None: Canonical Admin Photos bundle, or None for an older server.
    """
    if base not in (GALLERIES_SCOPE, UPLOAD_SCOPE):
        return None
    read = READ_UPLOAD_SCOPE if base == UPLOAD_SCOPE else READ_SCOPE
    create = UPLOAD_CREATE_SCOPE if base == UPLOAD_SCOPE else CREATE_SCOPE
    for candidate in (
        read + ' galleries:create gallery:visibility',
        read + ' gallery:visibility',
        read + ' galleries:create',
        read, create, base,
    ):
        for renewal in (ROLLING_SCOPE_SUFFIX, '') if rolling else ('',):
            for media in (
                ADMIN_PRESENTATION_SCOPE_SUFFIX, ADMIN_MEDIA_SCOPE_SUFFIX,
            ) if presentation else (ADMIN_MEDIA_SCOPE_SUFFIX,):
                for search in (GALLERY_SEARCH_SUFFIX, ''):
                    for edit in (GALLERY_EDIT_SCOPE_SUFFIX, ''):
                        scope = candidate + search + media + edit + renewal
                        if (scope_allows_admin_media(scope)
                                and _discovery_admits_linking(discovery, issuer, scope)):
                            return scope
    return None


def discover_linking_capabilities(
    issuer: str, cancelled: threading.Event | None = None,
) -> frozenset[str]:
    """Inspect capabilities for the dialog without starting authorization.

    Args:
        issuer (str): Exact canonical HTTPS installation selected by the user.
        cancelled (threading.Event | None): Dialog cancellation for the read.

    Returns:
        frozenset[str]: Announced capabilities from the supported exact contract,
            or an empty set for an absent, unavailable or incompatible server.
    """
    try:
        data = _request_json(
            issuer, 'app_linking_discovery', 'GET', None, None, cancelled=cancelled,
        )
        if _discovery_admits_linking(data, issuer, IDENTITY_SCOPE):
            return frozenset(data['app_linking']['capabilities'])
    except LinkingError:
        pass
    return frozenset()
