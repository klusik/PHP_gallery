# Project: PHP Gallery
# Module Type: Windows Tooling
# Purpose: Render local multilingual postflight PDF reports from recorded flights.
# Responsibilities:
#   - Format the shared flight analysis without changing or inferring its data.
#   - Draw vector flight-profile and route graphics in four languages.
#   - Write atomic, checksummed reports with retry-stable identities.
# Repository: https://github.com/klusik/PHP_gallery
#
# File: winapp/uploader/flight_report.py
#
# Author:
#   Rudolf Klusal
#
# License:
#   MIT License (see LICENSE file in repository)

"""Render local multilingual postflight reports from normalized flight data."""


import hashlib
import io
import json
import math
import os
import re
import tempfile
from dataclasses import replace
from datetime import datetime, timezone
from functools import partial
from pathlib import Path
from typing import Dict, List, Optional, Sequence, Tuple, Union
from xml.sax.saxutils import escape

from PIL import Image as PillowImage

from reportlab.lib import colors
from reportlab.lib.enums import TA_CENTER, TA_LEFT
from reportlab.lib.pagesizes import A4, landscape
from reportlab.lib.styles import ParagraphStyle, getSampleStyleSheet
from reportlab.lib.units import mm
from reportlab.lib.utils import simpleSplit
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont
from reportlab.pdfgen.canvas import Canvas
from reportlab.platypus import (
    BaseDocTemplate,
    Flowable,
    Frame,
    KeepTogether,
    PageTemplate,
    Paragraph,
    PageBreak,
    Spacer,
    Table,
    TableStyle,
)
from reportlab.platypus.doctemplate import LayoutError
from reportlab.graphics.shapes import (
    Circle, Drawing, Group, Image as MapShape, Line, Path as ShapePath,
    PolyLine, Polygon, Rect, String,
)

from .flight_maps import map_viewport, print_size, render_map
from .diagnostics import redact_text
from .flight_data import (
    ANALYSIS_VERSION,
    MAX_DESCENT_FIT_SECONDS,
    MAX_DESCENT_SAMPLE_GAP_SECONDS,
    MIN_DESCENT_FIT_SECONDS,
    FlightAnalysis,
    FlightSample,
    Landing,
    _distance_nm,
)
from .flight_report_translations import supported_languages, tr
from .flight_render import render_report_variants
from .flight_comparison import (
    ALTITUDE_BAND_FT, ROUTE_BAND_NM, COVERAGE_EPSILON_SECONDS,
    GRAPH_INTERVAL_SECONDS, WeightedValue, grouped_phases, mass_kg,
    route_excursions,
)
from .flight_jobs import readable_pdf_name, read_document

from .flight.reports.policy import (
    ASSUMED_KG_PER_US_GALLON,
    AXIS_EPSILON,
    AXIS_FLAT_PADDING,
    AXIS_MAJOR_MULTIPLE,
    AXIS_MINOR_DIVISIONS,
    CHART_HEIGHT,
    CHART_WIDTH,
    COMPARISON_CELL_LEADING,
    COMPARISON_CELL_SIZE,
    COMPARISON_CHART_HEIGHT,
    COMPARISON_PANEL_GAP,
    CONTACT_TIME_DIVISIONS,
    COORDINATE_DECIMALS,
    CRUISE_DISPLAY_LEVELS,
    DEVIATION_AXIS_DIVISIONS,
    DEVIATION_HISTOGRAM_BARS,
    DISPATCH_POSITION_MATCH_NM,
    DISPLAY_DECIMALS,
    FEET_PER_MINUTE_PER_MPS,
    FLIGHTPLAN_IDENT_CHARACTERS,
    HOUR_SECONDS,
    KNOTS_PER_MPS,
    LANDING_CADENCE_WINDOW_SECONDS,
    LANDING_CHART_HEIGHT,
    MAP_OVERVIEW_FADE,
    MAX_CONFIGURATION_EVENTS,
    MAX_GRID_LINES,
    METHOD_THRESHOLD_DECIMALS,
    METRES_PER_NM,
    METRES_TO_FEET,
    METRIC_COLUMNS,
    METRIC_ROW_HEIGHT,
    OVERVIEW_METRIC_FRACTION,
    PHASE_COLUMNS,
    PHASE_ROW_HEIGHT,
    POUNDS_PER_KG,
    PRECISE_TIME_DECIMALS,
    PRECONTACT_WINDOW_SECONDS,
    PROFILE_MAX_BRIDGE_SECONDS,
    PROFILE_SHORT_BRIDGE_SECONDS,
    RECORDER_TARGET_HZ,
    REPORT_FRAME_PADDING,
    REPORT_PAGE_SIZE,
    REPORT_POSITION_JUMP_NM,
    REPORT_SIDE_MARGIN,
    REPORT_TEMPLATE_VERSION,
    REPORT_VERTICAL_MARGINS,
    ROLLOUT_WINDOW_SECONDS,
    SHORT_FLIGHT_SECONDS,
    TIME_GRID_SECONDS,
    VERTICAL_SPEED_GRID_FPM,
    _COLOR_BLUE,
    _COLOR_GREEN,
    _COLOR_GRID,
    _COLOR_INK,
    _COLOR_INTERPOLATED,
    _COLOR_MINOR_GRID,
    _COLOR_MUTED,
    _COLOR_NAVY,
    _COLOR_PAGE,
    _COLOR_PANEL,
    _COLOR_PLANNED,
    _COLOR_RED,
    _COLOR_TEAL,
    _FONT_BOLD,
    _FONT_NAME,
    _REQUIRED_GLYPHS,
)
from .flight.reports.identity import (
    _manifest_path,
    _register_font,
    _resource_directory,
)
from .flight.reports.presentation import (
    _axis_limits,
    _draw_page,
    _number,
    _recorded_flight_date,
    _styles,
)
from .flight.reports.route import (
    _dispatch_position_series,
    _dispatch_series,
    _profile_chart,
    _recorded_positions,
    _route_chart,
    prepare_report_maps,
)
from .flight.reports.landing import (
    _contact_sensor_tables,
    _landing_event_table,
    _landing_rows,
    _methodology_notes,
    _phase_event_table,
    _quality_notes,
)
from .flight.reports.graphics import (
    _build_story,
    _fuel_profile,
)
from .flight.reports.comparison import (
    _comparison_pages,
    _delta_color,
    _deviation_chart,
    _deviation_histogram,
    _flightplan_pages,
)
from .flight.reports.renderer import (
    render_report,
    render_reports,
)

__all__ = [
    '_axis_limits',
    '_comparison_pages',
    '_contact_sensor_tables',
    '_delta_color',
    '_deviation_chart',
    '_deviation_histogram',
    '_dispatch_position_series',
    '_dispatch_series',
    '_draw_page',
    '_fuel_profile',
    '_flightplan_pages',
    '_landing_event_table',
    '_landing_rows',
    '_manifest_path',
    '_methodology_notes',
    '_number',
    '_phase_event_table',
    '_profile_chart',
    '_quality_notes',
    '_recorded_flight_date',
    '_recorded_positions',
    '_register_font',
    '_resource_directory',
    '_route_chart',
    '_styles',
    'ASSUMED_KG_PER_US_GALLON',
    'AXIS_EPSILON',
    'AXIS_FLAT_PADDING',
    'AXIS_MAJOR_MULTIPLE',
    'AXIS_MINOR_DIVISIONS',
    'CHART_HEIGHT',
    'CHART_WIDTH',
    'COMPARISON_CELL_LEADING',
    'COMPARISON_CELL_SIZE',
    'COMPARISON_CHART_HEIGHT',
    'COMPARISON_PANEL_GAP',
    'CONTACT_TIME_DIVISIONS',
    'COORDINATE_DECIMALS',
    'CRUISE_DISPLAY_LEVELS',
    'DEVIATION_AXIS_DIVISIONS',
    'DEVIATION_HISTOGRAM_BARS',
    'DISPATCH_POSITION_MATCH_NM',
    'DISPLAY_DECIMALS',
    'FEET_PER_MINUTE_PER_MPS',
    'FLIGHTPLAN_IDENT_CHARACTERS',
    'HOUR_SECONDS',
    'KNOTS_PER_MPS',
    'LANDING_CADENCE_WINDOW_SECONDS',
    'LANDING_CHART_HEIGHT',
    'MAP_OVERVIEW_FADE',
    'MAX_CONFIGURATION_EVENTS',
    'MAX_GRID_LINES',
    'METHOD_THRESHOLD_DECIMALS',
    'METRES_PER_NM',
    'METRES_TO_FEET',
    'METRIC_COLUMNS',
    'METRIC_ROW_HEIGHT',
    'OVERVIEW_METRIC_FRACTION',
    'PHASE_COLUMNS',
    'PHASE_ROW_HEIGHT',
    'POUNDS_PER_KG',
    'PRECISE_TIME_DECIMALS',
    'PRECONTACT_WINDOW_SECONDS',
    'PROFILE_MAX_BRIDGE_SECONDS',
    'PROFILE_SHORT_BRIDGE_SECONDS',
    'RECORDER_TARGET_HZ',
    'REPORT_FRAME_PADDING',
    'REPORT_PAGE_SIZE',
    'REPORT_POSITION_JUMP_NM',
    'REPORT_SIDE_MARGIN',
    'REPORT_TEMPLATE_VERSION',
    'REPORT_VERTICAL_MARGINS',
    'ROLLOUT_WINDOW_SECONDS',
    'SHORT_FLIGHT_SECONDS',
    'TIME_GRID_SECONDS',
    'VERTICAL_SPEED_GRID_FPM',
    '_COLOR_BLUE',
    '_COLOR_GREEN',
    '_COLOR_GRID',
    '_COLOR_INK',
    '_COLOR_INTERPOLATED',
    '_COLOR_MINOR_GRID',
    '_COLOR_MUTED',
    '_COLOR_NAVY',
    '_COLOR_PAGE',
    '_COLOR_PANEL',
    '_COLOR_PLANNED',
    '_COLOR_RED',
    '_COLOR_TEAL',
    '_FONT_BOLD',
    '_FONT_NAME',
    '_REQUIRED_GLYPHS',
    '_build_story',
    'prepare_report_maps',
    'render_report',
    'render_reports',
]
