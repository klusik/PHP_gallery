# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/flight/ui/policy.py
# Module Type: Flight UI policy
# Purpose: Own bounded timing and compact layout choices for flight UI.
# Responsibilities: Define shared user-interface limits and cadences.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)

"""Shared flight interface policies."""

PROGRESS_TICK_MS = 250
# Purpose: Retry temporarily unavailable local capture persistence quietly.
# Type: float; Units: seconds; Scope: one interrupted capture generation.
# Consumers: FlightUI._recover_capture, FlightUI.force_finish, FlightUI.tick.
# Rationale: five seconds avoids a hot disk-error loop while retaining the
# original durable guard until publication and readback actually succeed.
CAPTURE_RECOVERY_RETRY_SECONDS = 5.0
# Let background workers sleep between cancellation/deadline checks.
# Type: float; Units: seconds; Scope: finalization drain worker.
# Consumers: FlightUI.wait_for_workers.
# Rationale: 0.1 s gives prompt stop/revoke response without busy-waiting.
DRAIN_POLL_SECONDS = 0.1
# Keep the saved-flight browser narrow while leaving identity details below.
# Type: tuple[int, ...]. Units: pixels. Scope: report catalog columns.
# Consumers: FlightUI.show_reports. Rationale: six compact metadata columns
# retain report dates, counts and states alongside the filename column.
REPORT_COLUMN_WIDTHS = (148, 64, 46, 150, 59, 112)
# Purpose: Keep the flight name, start and report count visible together.
# Type: tuple[int, int]. Units: pixels. Scope: catalog flight-name column.
# Consumers: FlightUI.show_reports, FlightUI.layout_report_columns.
# Rationale: 128 px keeps the name identifiable on a narrow viewport, and
# 192 px leaves room for all six secondary columns at desktop width.
REPORT_NAME_WIDTHS = (128, 192)
# Purpose: Reserve space for the followed gallery beside the report actions.
# Type: int. Units: pixels. Scope: the saved-report footer.
# Consumers: FlightUI.layout_report_footer. Rationale: 180 px accommodates
# common gallery names before stacking the actions or wrapping the label.
REPORT_TARGET_WIDTH = 180
# Show a short history while the Treeview retains ordinary wheel scrolling.
# Type: int. Units: visible rows. Scope: report catalog window.
# Consumers: FlightUI.show_reports. Rationale: seven flights fit a small dialog.
REPORT_VISIBLE_ROWS = 7
# Purpose: Keep recovery actions inside a compact progress window.
# Type: int; Units: button columns; Scope: finalization progress actions.
# Consumers: FlightUI.refresh_progress_actions.
# Rationale: two compact columns leave room for translated recovery actions.
PROGRESS_ACTION_COLUMNS = 2
# Purpose: Let successful work dismiss itself after a readable completion notice.
# Type: int. Units: seconds. Scope: one successful visible progress generation.
# Consumers: FlightUI.refresh_autoclose. Rationale: three seconds matches the
# requested acknowledgement window; errors and pending work never start it.
SUCCESS_AUTOCLOSE_SECONDS = 3
# Purpose: Show one current edition per language without a large empty table.
# Type: int. Units: rows. Scope: progress task viewport.
# Consumers: FlightUI.show_progress. Rationale: four rows fit the four editions;
# additional saved flights remain reachable through the attached scrollbar.
PROGRESS_TASK_ROWS = 4
# Purpose: Preserve task/status readability as the overlay narrows.
# Type: tuple[int, int]. Units: pixels. Scope: progress task columns.
# Consumers: FlightUI.show_progress. Rationale: 150/180 px share a narrow panel.
PROGRESS_TASK_WIDTHS = (150, 180)
# Purpose: Let the task viewport shrink while preserving an identifiable status.
# Type: tuple[int, int]. Units: pixels. Scope: progress task column minimums.
# Consumers: FlightUI.show_progress. Rationale: 50/100 px leave the footer intact.
PROGRESS_TASK_MIN_WIDTHS = (50, 100)
# Keep identity help and validation readable without a desktop-sized dialog.
# Type: int; Units: pixels; Scope: SimBrief identity sheet.
# Consumers: prompt_simbrief_identity. Rationale: 360 px fits its single input.
SIMBRIEF_PROMPT_WRAP_PX = 360
# Show a normal pilot identity without forcing a very wide control.
# Type: int; Units: character columns; Scope: SimBrief identity entry.
# Consumers: prompt_simbrief_identity. Rationale: 40 columns leaves editing room.
SIMBRIEF_IDENTITY_COLUMNS = 40
