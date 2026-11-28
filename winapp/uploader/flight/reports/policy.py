# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/flight/reports/policy.py
# Module Type: Flight report policy
# Purpose: Own shared report identity and layout policy values.
# Responsibilities: Define constants consumed by report owners.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)

"""Shared flight report policies."""

from reportlab.lib import colors
from reportlab.lib.pagesizes import A4, landscape
from reportlab.lib.units import mm

REPORT_TEMPLATE_VERSION = "12"

# Purpose: Share map geometry between batch preparation and the overview page.
# Type: float; Units: page-width fraction; Scope: report overview layout.
# Consumers: prepare_report_maps and _build_story.
# Rationale: 0.4 preserves the existing metric panel and identical map pixels.
OVERVIEW_METRIC_FRACTION = 0.4

# Purpose: Keep the new analysis dense without shrinking the established report.
# Type: float; Units: PDF points; Scope: comparison dashboard and flightplan.
# Consumers: _comparison_table and compact chart owners.
# Rationale: 9-pt cells with 10.5-pt leading and 164-pt small multiples retain
# print legibility while avoiding repeated multi-line labels and empty pages.
COMPARISON_CELL_SIZE = 9.0
COMPARISON_CELL_LEADING = 10.5
COMPARISON_CHART_HEIGHT = 164.0
COMPARISON_PANEL_GAP = 12.0

# Purpose: Keep untrusted OFP identifiers readable inside repeated table cells.
# Type: int; Units: characters; Scope: displayed flightplan identifiers.
# Consumers: _flightplan_pages. Rationale: 40 characters retain normal fixes;
# longer provider identifiers remain complete in the saved dispatch/analysis.
FLIGHTPLAN_IDENT_CHARACTERS = 40
# Purpose: Bound the summary of observed cruise levels without dropping raw bins.
# Type: int; Units: displayed levels; Scope: comparison summary.
# Consumers: _comparison_pages. Rationale: six dominant levels fit one line;
# the shared analysis retains all bounded histogram bins and their durations.
CRUISE_DISPLAY_LEVELS = 6
# Purpose: Keep axis labels and histogram bars readable at landscape print size.
# Type: int; Units: axis divisions / histogram bars; Scope: deviation charts.
# Consumers: _deviation_chart and _deviation_histogram.
# Rationale: four divisions provide five reference labels; sixteen bars show
# distribution shape without unreadable per-sensor-bin labels.
DEVIATION_AXIS_DIVISIONS = 4
DEVIATION_HISTOGRAM_BARS = 16

# Purpose: Share true A4 landscape geometry across document and page chrome.
# Type: tuple[float, float]; Units: PDF points; Scope: rendered report pages.
# Consumers: render_report, _draw_page and all landscape panel owners.
# Rationale: 297 x 210 mm provides wide plots and tablet-friendly reading.
REPORT_PAGE_SIZE = landscape(A4)
# Purpose: Align page content, chart panels and running furniture.
# Type: float; Units: PDF points; Scope: horizontal report margins.
# Consumers: render_report and _draw_page. Rationale: fourteen millimetres
# leaves a printable margin without sacrificing the landscape content area.
REPORT_SIDE_MARGIN = 14 * mm
# Purpose: Leave room for the navy header and quiet bottom furniture.
# Type: tuple[float, float]; Units: PDF points; Scope: page frame.
# Consumers: render_report. Rationale: 22/16 mm separates content from chrome.
REPORT_VERTICAL_MARGINS = (22 * mm, 16 * mm)
# Preserve ReportLab's original inset and share it with the fit-sensitive timeline.
# Type: int; Units: PDF points per edge; Scope: report page frame.
# Consumers: _FlightReportFrame and _phase_infographic.
# Rationale: six points per edge preserve the previous printable content area.
REPORT_FRAME_PADDING = 6

# Type: float; Units: PDF points; Scope: landscape chart geometry.
# Consumers: _profile_chart, _route_chart and panel layout owners.
# Rationale: use the full printable width of A4 landscape, inside shared margins.
CHART_WIDTH = REPORT_PAGE_SIZE[0] - 2 * REPORT_SIDE_MARGIN
# Type: int; Units: PDF points; Scope: chart drawing geometry;
# Consumers: _profile_chart and _route_chart; Rationale: two full-width
# profiles fit one landscape page with larger labels and a short legend.
CHART_HEIGHT = 184
# Keep first-page map detail subordinate to the recorded vector route.
# Type: float; Units: white blend fraction; Scope: overview map background.
# Consumers: _route_chart. Rationale: 0.72 makes labels/terrain a light context.
MAP_OVERVIEW_FADE = 0.72
# Keep OFP plans distinct from amber warnings about interpolated recorded gaps.
_COLOR_PLANNED = colors.HexColor("#8056AD")
# Match OFP fixes only to nearby captured legs when takeoff was not recorded.
# Type: float; Units: nautical miles; Scope: positional OFP profile comparison.
# Consumers: _dispatch_position_series. Rationale: 10 NM admits ordinary route
# offsets while refusing distant fixes and unrelated routes; no endpoint extension.
DISPATCH_POSITION_MATCH_NM = 10.0
# Keep report geometry consistent with the analysis owner's jump exclusion.
# Type: float; Units: nautical miles; Scope: report position continuity.
# Consumers: _recorded_positions and _dispatch_position_series. Rationale: the
# analysis rejects 50-NM jumps; the map also uses this boundary to identify
# detached blocks bracketed by a return within the same local neighborhood.
REPORT_POSITION_JUMP_NM = 50.0
# Type: float; Units: knots per metre per second; Scope: displayed speeds;
# Consumers: _format_speed; Rationale: convert SI capture values to aviation
# units without changing source data; value: 1.9438444924406.
KNOTS_PER_MPS = 1.9438444924406
# Type: int; Units: decimal places; Scope: displayed numeric values;
# Consumers: _number; Rationale: readable flight summaries without implying
# instrument precision beyond the captured values; value: 1.
DISPLAY_DECIMALS = 1
# Type: int; Units: decimal places; Scope: contact and event timestamps.
# Consumers: _precise_elapsed; Rationale: distinguish retained 20 Hz
# observations without implying finer precision than two decimal places;
# value: 2.
PRECISE_TIME_DECIMALS = 2
# Type: int; Units: decimal places; Scope: reported GPS coordinates.
# Consumers: _coordinate; Rationale: show useful position precision without
# implying gear-impact accuracy; value: 5.
COORDINATE_DECIMALS = 5
# Type: int; Units: samples per second; Scope: acquisition-method note.
# Consumers: _methodology_notes; Rationale: report the recorder target while
# acknowledging that actual simulator delivery varies; value: 20.
RECORDER_TARGET_HZ = 20
# Type: int; Units: decimal places; Scope: disclosed analysis thresholds.
# Consumers: _methodology_notes; Rationale: retain the configured 0.25-second
# gap boundary instead of rounding it into a misleading 0.3-second claim;
# value: 2.
METHOD_THRESHOLD_DECIMALS = 2
# Type: float; Units: feet per metre; Scope: approach/rollout height chart.
# Consumers: _landing_section; Rationale: display captured SI altitude in
# aviation height units without altering stored values; value: 3.280839895.
METRES_TO_FEET = 3.280839895
# Type: float; Units: feet per minute per metre per second;
# Scope: observed vertical-speed charts; Consumers: _build_story and
# _landing_section; Rationale: distinguish converted sample rate from landing
# analysis rate; value: 196.8503937.
FEET_PER_MINUTE_PER_MPS = 196.8503937
# Type: float; Units: seconds before detected first contact;
# Scope: each landing profile; Consumers: _landing_samples;
# Rationale: show a fixed one-minute approach context where samples exist;
# value: 60.0.
PRECONTACT_WINDOW_SECONDS = 60.0
# Type: float; Units: seconds after detected last contact;
# Scope: each landing profile; Consumers: _landing_samples;
# Rationale: show a fixed two-minute rollout context where samples exist;
# value: 120.0.
ROLLOUT_WINDOW_SECONDS = 120.0

# Purpose: Pad constant sensor ranges to keep a visible enclosing axis.
# Type: float; Units: fraction; Scope: chart axes; Consumers: _axis_limits.
# Rationale: five percent avoids a zero-height range without changing readings.
AXIS_FLAT_PADDING = 0.05
# Purpose: Keep log/tick rounding defined at zero and avoid floating grid noise.
# Type: float; Units: display units or tick-ratio tolerance; Scope: chart axes.
# Consumers: _nice_step and _profile_chart. Rationale: one millionth is below
# captured display precision while giving a positive logarithm argument.
AXIS_EPSILON = 0.000001
# Purpose: Keep aviation minor ticks fine but bounded.
# Type: int; Units: intervals; Scope: Y grids; Consumers: _axis_limits.
# Rationale: forty minor divisions allow readable thousand-foot altitude ticks.
AXIS_MINOR_DIVISIONS = 40
# Purpose: Use familiar descent/climb grid spacing.
# Type: float; Units: fpm; Scope: V/S charts; Consumers: _axis_limits.
# Rationale: 200 fpm minor lines and five-times major lines match aviation reading.
VERTICAL_SPEED_GRID_FPM = 200.0
# Purpose: Bound vector grid density for unusually long/extreme captures.
# Type: int; Units: lines per axis; Scope: profiles; Consumers: _axis_limits,
# _profile_chart. Rationale: two hundred lines keep output size predictable.
MAX_GRID_LINES = 200
# Purpose: Distinguish minor and emphasized tick levels.
# Type: int; Units: minor intervals; Scope: chart axes; Consumers: _axis_limits,
# _profile_chart. Rationale: five minor ticks make one labeled major interval.
AXIS_MAJOR_MULTIPLE = 5
# Purpose: Share a fine elapsed grid across short contact windows.
# Type: int; Units: intervals; Scope: short-time axes; Consumers: _profile_chart.
# Rationale: eighteen desired divisions gives ten-second ticks over three minutes.
CONTACT_TIME_DIVISIONS = 18
# Purpose: Use ten-minute context through ordinary and long flights.
# Type: float; Units: seconds; Scope: full-flight grids; Consumers: _profile_chart.
# Rationale: 600 seconds stays subtle while hourly lines receive emphasis.
TIME_GRID_SECONDS = 600.0
# Purpose: Label long-flight major ticks as elapsed hours.
# Type: int; Units: seconds; Scope: profile axes; Consumers: _profile_chart.
# Rationale: one hour contains 3600 seconds.
HOUR_SECONDS = 3600
# Purpose: Select a finer grid for short recordings.
# Type: int; Units: seconds; Scope: time axes; Consumers: _profile_chart.
# Rationale: below twenty minutes a ten-minute minor grid would be too sparse.
SHORT_FLIGHT_SECONDS = 1200
# Purpose: Keep summary cards legible within A4 width.
# Type: int; Units: columns; Scope: dashboards; Consumers: _visual_metrics,
# _configuration_graphic. Rationale: three cards fit readable labels and values.
METRIC_COLUMNS = 3
# Purpose: Reserve consistent dashboard card height.
# Type: int; Units: PDF points; Scope: metric grids; Consumers: _visual_metrics.
# Rationale: 74 points fits two label lines and one or two large value lines.
METRIC_ROW_HEIGHT = 74
# Purpose: Wrap observed phases without long evidence tables.
# Type: int; Units: columns; Scope: phase timeline; Consumers: _phase_infographic.
# Rationale: fourteen small milestones keep an ordinary flight on one line.
PHASE_COLUMNS = 14
# Purpose: Keep phase icons and elapsed times vertically separated.
# Type: int; Units: PDF points; Scope: phase timeline; Consumers: _phase_infographic.
# Rationale: 54 points fits readable labels and a larger aircraft symbol.
PHASE_ROW_HEIGHT = 54
# Purpose: Fill small chart-only omissions without changing measured evidence.
# Type: float; Units: seconds; Scope: scalar profile drawing.
# Consumers: _profile_chart. Rationale: up to five seconds uses the series color;
# longer bounded gaps use an amber dashed estimate and an explicit legend.
PROFILE_SHORT_BRIDGE_SECONDS = 5.0
# Purpose: Refuse long-range extrapolation disguised as a continuous flight.
# Type: float; Units: seconds; Scope: missing scalar plot segments.
# Consumers: _profile_chart. Rationale: five minutes bridges a short
# interruption in a long flight;
# longer losses, reloads, pauses and position jumps remain visibly separated.
PROFILE_MAX_BRIDGE_SECONDS = 300.0
# Purpose: Fit four readable landing profiles beside concise analysis on A4.
# Type: int; Units: PDF points; Scope: paired landing charts.
# Consumers: _landing_visuals. Rationale: 174 points fits two chart rows on
# a dedicated detail page with nine-point axis labels.
LANDING_CHART_HEIGHT = 174
# Purpose: Describe observed delivery cadence close to touchdown.
# Type: float; Units: seconds; Scope: pre-contact cadence summary.
# Consumers: _landing_visuals. Rationale: ten seconds smooths frame jitter
# while exposing substantial sampling gaps within the landing window.
LANDING_CADENCE_WINDOW_SECONDS = 10.0
# Purpose: Keep noisy configuration changes from becoming an unreadable wall.
# Type: int; Units: event cards; Scope: one landing infographic.
# Consumers: _configuration_events. Rationale: show up to eighteen first/last
# changes and disclose the displayed/total count; raw observations remain intact.
MAX_CONFIGURATION_EVENTS = 18
# Purpose: Offer explicitly estimated historical fuel mass when density was absent.
# Type: float; Units: kg per US gallon; Scope: old fuel captures.
# Consumers: _fuel_profile. Rationale: assume 0.80 kg/L and 3.785411784 L/US gal;
# always mark this display conversion as estimated, never alter source telemetry.
ASSUMED_KG_PER_US_GALLON = 0.80 * 3.785411784
# Purpose: Convert measured fuel mass for Boeing-style displays.
# Type: float; Units: lb/kg; Scope: fuel display; Consumers: _fuel_profile.
# Rationale: reciprocal of the exact international pound conversion.
POUNDS_PER_KG = 1.0 / 0.45359237
# Purpose: Show observed rollout distance using familiar runway-distance units.
# Type: int; Units: metres/NM; Scope: landing cards; Consumers: _landing_visuals.
# Rationale: one nautical mile equals exactly 1852 metres.
METRES_PER_NM = 1852

_COLOR_INK = colors.HexColor("#17324D")
_COLOR_BLUE = colors.HexColor("#1677A8")
_COLOR_GREEN = colors.HexColor("#25836B")
_COLOR_RED = colors.HexColor("#C64A45")
_COLOR_MUTED = colors.HexColor("#637381")
_COLOR_GRID = colors.HexColor("#D9E2E8")
_COLOR_MINOR_GRID = colors.HexColor("#F0F3F6")
_COLOR_PANEL = colors.HexColor("#F2F6F8")
_COLOR_PAGE = colors.HexColor("#F4F7FC")
_COLOR_NAVY = colors.HexColor("#0E1B2E")
_COLOR_TEAL = colors.HexColor("#00A8AC")
_COLOR_INTERPOLATED = colors.HexColor("#C58A22")
_FONT_NAME = "FlightReportSans"
_FONT_BOLD = "FlightReportSansBold"
_REQUIRED_GLYPHS = "Příliš žluťoučký kůň åäö üß"
