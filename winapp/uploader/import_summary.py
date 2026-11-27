# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/import_summary.py
# Module Type: Manual import presentation model
# Purpose: Build the shared read-only preflight summary for desktop frontends.
# Responsibilities: Format bounded accepted, skipped, archive and media details.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Format one preflight plan without depending on a desktop toolkit."""

from typing import Mapping

from .gallery_information import format_gallery_bytes
from .localization import gettext
from .media import MediaCapability
from .models import ImportPlan, RECOVERABLE_ITEM_STATES

# Purpose: Limit unsafe archive entry names in the human-readable preview.
# Type: int. Units: entry names. Scope: one preflight summary.
# Consumers: format_preflight_plan_summary. Rationale: Three names expose the
# rejected path shape while keeping untrusted archive detail bounded.
PREFLIGHT_UNSAFE_ENTRY_PREVIEW_LIMIT = 3
# Purpose: Limit warning text displayed in the compact preflight summary.
# Type: int. Units: warnings. Scope: one preflight summary.
# Consumers: format_preflight_plan_summary. Rationale: Matches the stable Tk
# summary and keeps review focused on the first actionable findings.
PREFLIGHT_WARNING_PREVIEW_LIMIT = 3
# Purpose: Keep the local decoder details on the formats needing operator review.
# Type: tuple[str, ...]. Units: file suffixes. Scope: preflight media summary.
# Consumers: format_preflight_plan_summary. Rationale: HEIC, HEIF and DNG vary
# across installed decoders while ordinary formats need no extra explanation.
PREFLIGHT_SPECIAL_MEDIA_SUFFIXES = ('.heic', '.heif', '.dng')


def format_preflight_plan_summary(
    plan: ImportPlan, *, use_local_thumbnails: bool,
    local_thumbnail_available: bool,
    media_capabilities: Mapping[str, MediaCapability],
) -> str:
    """Build the shared bounded summary for an already completed preflight.

    Args:
        plan (ImportPlan): Read-only job, unsupported groups, unsafe archive
            entries, duplicate counts and warnings from shared preflight.
        use_local_thumbnails (bool): Current explicit Server/This PC choice.
        local_thumbnail_available (bool): Runtime capability for local thumbnail
            generation, captured from the existing shared media owner.
        media_capabilities (Mapping[str, MediaCapability]): Current runtime's
            verified suffix decoder capabilities, keyed by normalized suffix.
    Returns:
        str: Localized newline-separated target, byte totals, archive decisions,
            thumbnail mode, specialized media and bounded review warnings.
    """
    job = plan.job
    ready = sum(item.state in RECOVERABLE_ITEM_STATES for item in job.items)
    duplicates = plan.duplicate_local + plan.duplicate_remote
    unsupported = sum(plan.unsupported.values())
    lines = [
        gettext('Target: {gallery}').format(gallery=job.target_gallery_label),
        gettext(
            'Supported files found: {found}  |  Ready to upload: {ready}  |  '
            'Duplicate candidates: {duplicates}'
        ).format(found=len(job.items), ready=ready, duplicates=duplicates),
        gettext(
            'Estimated accepted bytes: {size}  |  Unsupported skipped: {unsupported}  |  '
            'Unsafe ZIP entries rejected: {rejected}'
        ).format(
            size=format_gallery_bytes(job.bytes_total), unsupported=unsupported,
            rejected=len(plan.unsafe_entries),
        ),
        gettext('Thumbnail mode: {mode}').format(mode=(
            gettext('This PC creates derivatives with server fallback.')
            if use_local_thumbnails and local_thumbnail_available
            else gettext('Server creates thumbnails.')
        )),
        gettext('Source deletion: Original files and ZIP archives are kept for manual verification.'),
    ]
    special = []
    for suffix in PREFLIGHT_SPECIAL_MEDIA_SUFFIXES:
        capability = media_capabilities.get(suffix)
        if capability is not None:
            decoding = (
                gettext('local decoder available') if capability.local_previewable
                else gettext('server upload only on this PC')
            )
            special.append(f'{suffix}: {decoding}')
    if special:
        lines.append(gettext('Local HEIC/DNG capability: ') + '; '.join(special))
    if plan.unsupported:
        grouped = ', '.join(
            f'{extension}: {count}'
            for extension, count in sorted(plan.unsupported.items())
        )
        lines.append(gettext('Unsupported: {types}').format(types=grouped))
    if plan.unsafe_entries:
        rejected = '; '.join(
            plan.unsafe_entries[:PREFLIGHT_UNSAFE_ENTRY_PREVIEW_LIMIT],
        )
        suffix = ' ...' if len(plan.unsafe_entries) > PREFLIGHT_UNSAFE_ENTRY_PREVIEW_LIMIT else ''
        lines.append(gettext('Rejected archive entries: ') + rejected + suffix)
    if plan.warnings:
        details = ' | '.join(
            gettext(item) for item in plan.warnings[:PREFLIGHT_WARNING_PREVIEW_LIMIT]
        )
        lines.append(gettext('Warnings: {details}').format(details=details))
    return '\n'.join(lines)
