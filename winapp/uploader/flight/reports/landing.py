# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/flight/reports/landing.py
# Module Type: Flight report landing owner
# Purpose: Own landing behavior for multilingual postflight PDF reports.
# Responsibilities: Preserve report rendering behavior behind explicit owners.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)

"""Own the landing domain of flight report generation."""

import math
from typing import Dict, List, Sequence, Tuple, Union


from reportlab.lib import colors
from reportlab.lib.styles import ParagraphStyle
from reportlab.lib.units import mm
from reportlab.platypus import Flowable, Paragraph, Spacer, Table, TableStyle

from uploader.flight_data import MAX_DESCENT_FIT_SECONDS, MAX_DESCENT_SAMPLE_GAP_SECONDS, MIN_DESCENT_FIT_SECONDS, FlightAnalysis, FlightSample, Landing
from uploader.flight_report_translations import tr

from .policy import (
    FEET_PER_MINUTE_PER_MPS,
    KNOTS_PER_MPS,
    METHOD_THRESHOLD_DECIMALS,
    METRES_TO_FEET,
    PRECONTACT_WINDOW_SECONDS,
    RECORDER_TARGET_HZ,
    ROLLOUT_WINDOW_SECONDS,
    _COLOR_BLUE,
    _COLOR_GREEN,
    _COLOR_GRID,
    _COLOR_PANEL,
    _COLOR_RED,
)

from .presentation import (
    _coordinate,
    _format_duration,
    _format_speed,
    _number,
    _paragraph,
    _precise_elapsed,
    _safe_source_text,
)

from .route import (
    _profile_chart,
)


def _landing_samples(
    analysis: FlightAnalysis,
    landing: Landing,
) -> Tuple[List[FlightSample], Tuple[float, float], Tuple[float, float]]:
    """Select observed samples around one detected landing window.

    Args:
        analysis (FlightAnalysis): Shared session with ordered sensor samples.
        landing (Landing): Detected first/last contact interval.

    Returns:
        Tuple[List[FlightSample], Tuple[float, float], Tuple[float, float]]:
            Captured samples in range, fixed display bounds, and exact contact
            bounds in session elapsed seconds. Original retained landing detail
            takes priority; older in-memory analyses without it keep their
            bounded overview series as a permanently supported display fallback.
    """
    contact_start = float(landing.first_contact_elapsed_seconds)
    contact_end = float(landing.last_contact_elapsed_seconds)
    window_start = contact_start - PRECONTACT_WINDOW_SECONDS
    window_end = contact_end + ROLLOUT_WINDOW_SECONDS
    samples = [
        sample
        for sample in (landing.profile_samples or analysis.samples)
        if window_start <= sample.session_elapsed_seconds <= window_end
    ]
    return samples, (window_start, window_end), (contact_start, contact_end)

def _landing_event_table(
    samples: Sequence[FlightSample],
    landing: Landing,
    language: str,
    styles: Dict[str, ParagraphStyle],
) -> Table:
    """Summarize captured configuration transitions and contact events.

    Args:
        samples (Sequence[FlightSample]): Captured samples in contact window.
        landing (Landing): Detected contact interval with evidence.
        language (str): Localized event labels.
        styles (Dict[str, ParagraphStyle]): Paragraph styles for event rows.

    Returns:
        Table: Time-ordered observed state transitions, with no interpolation.
    """
    events: List[Tuple[float, str, str]] = []
    contact_start = float(landing.first_contact_elapsed_seconds)
    events.append(
        (
            contact_start,
            tr(language, "first_contact"),
            tr(language, "sampled_state"),
        )
    )
    events.append(
        (
            float(landing.last_contact_elapsed_seconds),
            tr(language, "last_contact"),
            tr(language, "sampled_state"),
        )
    )
    previous: Optional[FlightSample] = None
    for sample in samples:
        elapsed = float(sample.session_elapsed_seconds)
        relative = _precise_elapsed(elapsed, contact_start, language)
        if sample.discontinuity_before is not None:
            events.append(
                (
                    elapsed,
                    tr(language, "recorder_gap"),
                    _safe_source_text(sample.discontinuity_before),
                )
            )
            state_parts = _sampled_state_parts(sample, language)
            if state_parts:
                events.append(
                    (
                        elapsed,
                        tr(language, "sampled_state"),
                        "; ".join(state_parts),
                    )
                )
            previous = sample
            continue
        if previous is None:
            state_parts = _sampled_state_parts(sample, language)
            if state_parts:
                events.append(
                    (
                        elapsed,
                        tr(language, "sampled_state"),
                        "; ".join(state_parts),
                    )
                )
            previous = sample
            continue
        if (
            sample.on_ground is not None
            and previous.on_ground is not None
            and sample.on_ground != previous.on_ground
        ):
            events.append(
                (
                    elapsed,
                    tr(language, "on_ground")
                    if sample.on_ground
                    else tr(language, "airborne"),
                    relative,
                )
            )
        for attribute, label in (
            ("gear_position", "gear"),
            ("flaps_position", "flaps"),
        ):
            current = getattr(sample, attribute)
            prior = getattr(previous, attribute)
            if current is not None and prior is not None and current != prior:
                events.append(
                    (
                        elapsed,
                        tr(language, label),
                        _number(float(current), language) + "%",
                    )
                )
        for light_name in sorted(set(previous.lights) | set(sample.lights)):
            current_light = sample.lights.get(light_name)
            prior_light = previous.lights.get(light_name)
            if (
                prior_light is not None
                and current_light is not None
                and current_light != prior_light
            ):
                events.append(
                    (
                        elapsed,
                        tr(language, "light") + " " + light_name,
                        tr(language, "state_on")
                        if current_light
                        else tr(language, "state_off"),
                    )
                )
        previous = sample

    rows: List[List[Union[Paragraph, str]]] = [
        [
            _paragraph(tr(language, "contact_elapsed_short"), styles["small"]),
            _paragraph(tr(language, "recorded_events"), styles["small"]),
            _paragraph(tr(language, "detail"), styles["small"]),
        ]
    ]
    if not events:
        rows.append(
            [
                "",
                _paragraph(tr(language, "no_state_changes"), styles["small"]),
                "",
            ]
        )
    else:
        for elapsed, event_name, detail in sorted(events, key=lambda row: row[0]):
            rows.append(
                [
                    _paragraph(
                        _precise_elapsed(elapsed, contact_start, language),
                        styles["small"],
                    ),
                    _paragraph(event_name, styles["small"]),
                    _paragraph(detail, styles["small"]),
                ]
            )
    table = Table(rows, colWidths=[28 * mm, 53 * mm, 90 * mm], repeatRows=1)
    table.setStyle(
        TableStyle(
            [
                ("BACKGROUND", (0, 0), (-1, 0), _COLOR_PANEL),
                ("LINEBELOW", (0, 0), (-1, 0), 0.6, _COLOR_GRID),
                ("VALIGN", (0, 0), (-1, -1), "TOP"),
                ("LEFTPADDING", (0, 0), (-1, -1), 2),
                ("RIGHTPADDING", (0, 0), (-1, -1), 2),
                ("TOPPADDING", (0, 0), (-1, -1), 3),
                ("BOTTOMPADDING", (0, 0), (-1, -1), 3),
            ]
        )
    )
    return table

def _sampled_state_parts(
    sample: FlightSample,
    language: str,
) -> List[str]:
    """Describe the first observed configuration in a landing window.

    Args:
        sample (FlightSample): Exact sample at the beginning of the displayed
            window.
        language (str): Localized state labels.

    Returns:
        List[str]: Known gear, flap, ground and light states; omitted values
            remain unavailable rather than defaulting to zero or off.
    """
    values: List[str] = []
    if sample.on_ground is not None:
        values.append(
            tr(language, "on_ground")
            if sample.on_ground
            else tr(language, "airborne")
        )
    if sample.gear_position is not None:
        values.append(
            tr(language, "gear")
            + " "
            + _number(float(sample.gear_position), language)
            + "%"
        )
    if sample.flaps_position is not None:
        values.append(
            tr(language, "flaps")
            + " "
            + _number(float(sample.flaps_position), language)
            + "%"
        )
    for light_name, enabled in sorted(sample.lights.items()):
        if enabled is not None:
            values.append(
                tr(language, "light")
                + " "
                + light_name
                + " "
                + (
                    tr(language, "state_on")
                    if enabled
                    else tr(language, "state_off")
                )
            )
    return values

def _landing_section(
    analysis: FlightAnalysis,
    landing: Landing,
    language: str,
    styles: Dict[str, ParagraphStyle],
) -> List[Flowable]:
    """Build synchronized precontact/rollout sensor charts and event timeline.

    Args:
        analysis (FlightAnalysis): Shared immutable flight-analysis model.
        landing (Landing): One detected landing and exact contact interval.
        language (str): Report language.
        styles (Dict[str, ParagraphStyle]): Report paragraph styles.

    Returns:
        List[Flowable]: Landing heading, synchronized profiles, and sampled
            configuration/event timeline.
    """
    samples, bounds, contacts = _landing_samples(analysis, landing)
    zero_time = contacts[0]
    title = (
        tr(language, "approach_rollout")
        + " — "
        + tr(language, "landing")
        + " "
        + str(landing.index)
    )
    story: List[Flowable] = [
        _paragraph(title, styles["heading"]),
        _paragraph(
            tr(language, "observed_only")
            + "  "
            + tr(language, "contact_relative"),
            styles["small"],
        ),
        _profile_chart(
            analysis,
            "agl_m",
            tr(language, "agl_altitude"),
            _COLOR_BLUE,
            METRES_TO_FEET,
            "ft",
            language,
            samples,
            bounds,
            zero_time,
            contacts,
        ),
        _profile_chart(
            analysis,
            "ground_speed_mps",
            tr(language, "ground_speed"),
            _COLOR_GREEN,
            KNOTS_PER_MPS,
            "kt",
            language,
            samples,
            bounds,
            zero_time,
            contacts,
        ),
        _profile_chart(
            analysis,
            "vertical_speed_mps",
            tr(language, "vertical_speed"),
            _COLOR_RED,
            FEET_PER_MINUTE_PER_MPS,
            "ft/min",
            language,
            samples,
            bounds,
            zero_time,
            contacts,
        ),
        _profile_chart(
            analysis,
            "pitch_deg",
            tr(language, "pitch"),
            _COLOR_BLUE,
            1.0,
            "°",
            language,
            samples,
            bounds,
            zero_time,
            contacts,
        ),
        _profile_chart(
            analysis,
            "bank_deg",
            tr(language, "bank"),
            _COLOR_GREEN,
            1.0,
            "°",
            language,
            samples,
            bounds,
            zero_time,
            contacts,
        ),
        _profile_chart(
            analysis,
            "indicated_airspeed_mps",
            tr(language, "indicated_airspeed"),
            _COLOR_BLUE,
            KNOTS_PER_MPS,
            "kt",
            language,
            samples,
            bounds,
            zero_time,
            contacts,
        ),
        _profile_chart(
            analysis,
            "load_factor_g",
            tr(language, "load_factor"),
            _COLOR_GREEN,
            1.0,
            "g",
            language,
            samples,
            bounds,
            zero_time,
            contacts,
        ),
        *_contact_sensor_tables(landing, language, styles),
        _landing_event_table(samples, landing, language, styles),
        Spacer(1, 2 * mm),
    ]
    return story

def _translate_confidence(value: str, language: str) -> str:
    """Translate known evidence confidence values while preserving unknowns.

    Args:
        value (str): Stable confidence category from landing analysis.
        language (str): Report language.

    Returns:
        str: Localized category or original nonempty value when unknown.
    """
    normalized = value.strip().lower()
    translated = tr(language, "confidence_" + normalized)
    if translated != "confidence_" + normalized:
        return translated
    return value or tr(language, "unavailable")

def _translate_phase_event(value: str, language: str) -> str:
    """Translate known recorder phase/event codes with a localized fallback.

    Args:
        value (str): Stable phase or event code emitted by analysis.
        language (str): Report language.

    Returns:
        str: Localized event name, or localized generic phase plus source code.
    """
    normalized = value.strip().lower().replace("-", "_").replace(" ", "_")
    key = "event_" + normalized
    translated = tr(language, key)
    if translated != key:
        return translated
    return tr(language, "phase_event") + ": " + value

def _phase_event_table(
    analysis: FlightAnalysis,
    language: str,
    styles: Dict[str, ParagraphStyle],
) -> Table:
    """Build a session-elapsed phase timeline from analyzer evidence.

    Args:
        analysis (FlightAnalysis): Immutable analysis and ordered phase events.
        language (str): Report language.
        styles (Dict[str, ParagraphStyle]): Paragraph styles for table cells.

    Returns:
        Table: Localized timeline; absent events are stated without inference.
    """
    headings = ["phase_time_seconds", "phase", "confidence", "evidence"]
    rows: List[List[Union[Paragraph, str]]] = [
        [_paragraph(tr(language, key), styles["small"]) for key in headings]
    ]
    events = getattr(analysis, "events", ())
    if not events:
        rows.append([
            "",
            _paragraph(tr(language, "no_phase_events"), styles["small"]),
            "",
            "",
        ])
    else:
        for event in events:
            confidence_key = "confidence_" + event.confidence.strip().lower()
            confidence = tr(language, confidence_key)
            if confidence == confidence_key:
                confidence = tr(language, "confidence_uncertain")
            evidence = ", ".join(
                _safe_source_text(value) for value in event.evidence
            ) or tr(language, "unavailable")
            rows.append([
                _paragraph(
                    _precise_elapsed(event.elapsed_seconds, 0.0, language),
                    styles["small"],
                ),
                _paragraph(_translate_phase_event(event.phase, language), styles["small"]),
                _paragraph(confidence, styles["small"]),
                _paragraph(evidence, styles["small"]),
            ])
    table = Table(
        rows,
        colWidths=[24 * mm, 39 * mm, 28 * mm, 83 * mm],
        repeatRows=1,
        hAlign="LEFT",
    )
    table.setStyle(
        TableStyle([
            ("BACKGROUND", (0, 0), (-1, 0), _COLOR_PANEL),
            ("LINEBELOW", (0, 0), (-1, 0), 0.6, _COLOR_GRID),
            ("LINEBELOW", (0, 1), (-1, -1), 0.35, _COLOR_GRID),
            ("VALIGN", (0, 0), (-1, -1), "TOP"),
            ("LEFTPADDING", (0, 0), (-1, -1), 2),
            ("RIGHTPADDING", (0, 0), (-1, -1), 2),
            ("TOPPADDING", (0, 0), (-1, -1), 3),
            ("BOTTOMPADDING", (0, 0), (-1, -1), 3),
        ])
    )
    return table

def _contact_configuration(sample: FlightSample, language: str) -> str:
    """Format known brake, gear, flap, light and autopilot states.

    Args:
        sample (FlightSample): Full-resolution contact sensor observation.
        language (str): Language for configuration labels and state values.

    Returns:
        str: Known values separated by semicolons, or unavailable.
    """
    parts: List[str] = []
    for attribute, key in (
        ("gear_position", "gear"),
        ("flaps_position", "flaps"),
        ("left_brake_position", "brakes"),
        ("right_brake_position", "brakes"),
    ):
        value = getattr(sample, attribute, None)
        if value is not None and math.isfinite(float(value)):
            parts.append(
                tr(language, key) + " "
                + _number(float(value), language) + "%"
            )
    if sample.autopilot_master is not None:
        parts.append(
            tr(language, "autopilot") + " "
            + (tr(language, "yes") if sample.autopilot_master else tr(language, "no"))
        )
    for name, enabled in sorted(sample.lights.items()):
        if enabled is not None:
            parts.append(
                tr(language, "light") + " " + _safe_source_text(name) + " "
                + (tr(language, "state_on") if enabled else tr(language, "state_off"))
            )
    return "; ".join(parts) or tr(language, "unavailable")

def _contact_sensor_tables(
    landing: Landing,
    language: str,
    styles: Dict[str, ParagraphStyle],
) -> List[Flowable]:
    """Build synchronized tables from retained full-resolution contact rows.

    Args:
        landing (Landing): One landing containing optional contact snapshots.
        language (str): Language for all sensor headings and values.
        styles (Dict[str, ParagraphStyle]): Report paragraph styles.

    Returns:
        List[Flowable]: Two compact tables for motion and configuration data.
    """
    samples = getattr(landing, "contact_samples", ())
    rates = getattr(landing, "contact_descent_rates_fpm", ())
    motion_headings = [
        "contact_elapsed_short",
        "latitude_short",
        "longitude_short",
        "contact_descent_short",
        "contact_ias_short",
        "contact_ground_speed_short",
        "contact_vertical_speed_short",
    ]
    state_headings = [
        "contact_elapsed_short",
        "pitch",
        "bank",
        "heading",
        "wind",
        "configuration",
        "load_factor",
        "fuel",
    ]
    motion_rows: List[List[Union[Paragraph, str]]] = [[
        _paragraph(tr(language, key), styles["small"])
        for key in motion_headings
    ]]
    state_rows: List[List[Union[Paragraph, str]]] = [[
        _paragraph(tr(language, key), styles["small"])
        for key in state_headings
    ]]
    if not samples:
        empty = _paragraph(tr(language, "no_contact_samples"), styles["small"])
        motion_rows.append(["", empty, "", "", "", "", ""])
        state_rows.append(["", empty, "", "", "", "", "", ""])
    else:
        for index, sample in enumerate(samples):
            elapsed = _precise_elapsed(
                float(sample.session_elapsed_seconds),
                float(landing.first_contact_elapsed_seconds),
                language,
            )
            latitude = _coordinate(
                getattr(sample, "latitude_deg", None), language
            )
            longitude = _coordinate(
                getattr(sample, "longitude_deg", None), language
            )
            contact_rate = (
                rates[index]
                if index < len(rates)
                else None
            )
            descent = (
                tr(language, "unavailable")
                if contact_rate is None
                else _number(float(contact_rate), language) + " ft/min"
            )
            indicated = getattr(sample, "indicated_airspeed_mps", None)
            ground = getattr(sample, "ground_speed_mps", None)
            vertical = getattr(sample, "vertical_speed_mps", None)
            vertical_text = (
                tr(language, "unavailable")
                if vertical is None
                else _number(
                    float(vertical) * FEET_PER_MINUTE_PER_MPS,
                    language,
                ) + " ft/min"
            )
            motion_rows.append([
                _paragraph(elapsed, styles["small"]),
                _paragraph(latitude, styles["small"]),
                _paragraph(longitude, styles["small"]),
                _paragraph(descent, styles["small"]),
                _paragraph(_format_speed(indicated, language), styles["small"]),
                _paragraph(_format_speed(ground, language), styles["small"]),
                _paragraph(vertical_text, styles["small"]),
            ])
            wind_velocity = getattr(sample, "wind_velocity_mps", None)
            wind_direction = getattr(sample, "wind_direction_deg", None)
            if wind_velocity is None and wind_direction is None:
                wind = tr(language, "unavailable")
            else:
                wind = (
                    _format_speed(wind_velocity, language)
                    + " / "
                    + (
                        _number(float(wind_direction), language) + "°"
                        if wind_direction is not None
                        else tr(language, "unavailable")
                    )
                )
            state_values = [
                _number(getattr(sample, "pitch_deg", None), language) + "°"
                if getattr(sample, "pitch_deg", None) is not None
                else tr(language, "unavailable"),
                _number(getattr(sample, "bank_deg", None), language) + "°"
                if getattr(sample, "bank_deg", None) is not None
                else tr(language, "unavailable"),
                _number(getattr(sample, "heading_deg", None), language) + "°"
                if getattr(sample, "heading_deg", None) is not None
                else tr(language, "unavailable"),
                wind,
                _contact_configuration(sample, language),
                (
                    _number(float(sample.load_factor_g), language) + " g"
                    if getattr(sample, "load_factor_g", None) is not None
                    else tr(language, "unavailable")
                ),
                (
                    _number(float(sample.fuel_gallons), language) + " gal"
                    if getattr(sample, "fuel_gallons", None) is not None
                    else tr(language, "unavailable")
                ),
            ]
            state_rows.append([
                _paragraph(elapsed, styles["small"]),
                *[
                    _paragraph(value, styles["small"])
                    for value in state_values
                ],
            ])
    motion_table = Table(
        motion_rows,
        colWidths=[18 * mm, 20 * mm, 20 * mm, 25 * mm, 18 * mm, 18 * mm, 22 * mm],
        repeatRows=1,
        hAlign="LEFT",
    )
    state_table = Table(
        state_rows,
        colWidths=[18 * mm, 14 * mm, 14 * mm, 17 * mm, 25 * mm, 44 * mm, 18 * mm, 18 * mm],
        repeatRows=1,
        hAlign="LEFT",
    )
    for table in (motion_table, state_table):
        table.setStyle(TableStyle([
            ("BACKGROUND", (0, 0), (-1, 0), _COLOR_PANEL),
            ("LINEBELOW", (0, 0), (-1, 0), 0.6, _COLOR_GRID),
            ("LINEBELOW", (0, 1), (-1, -1), 0.35, _COLOR_GRID),
            ("VALIGN", (0, 0), (-1, -1), "TOP"),
            ("LEFTPADDING", (0, 0), (-1, -1), 2),
            ("RIGHTPADDING", (0, 0), (-1, -1), 2),
            ("TOPPADDING", (0, 0), (-1, -1), 3),
            ("BOTTOMPADDING", (0, 0), (-1, -1), 3),
        ]))
    return [
        _paragraph(tr(language, "contact_observations"), styles["heading"]),
        motion_table,
        state_table,
    ]

def _method_threshold(value: float, language: str) -> str:
    """Format configured algorithm thresholds without obscuring precision.

    Args:
        value (float): Threshold value in its documented unit.
        language (str): Report language used for decimal punctuation.

    Returns:
        str: Two-decimal value preserving the actual configured boundary.
    """
    result = format(float(value), ".{}f".format(METHOD_THRESHOLD_DECIMALS))
    return result.replace(".", ",") if language in ("cs", "de", "sv") else result

def _methodology_notes(
    language: str,
    styles: Dict[str, ParagraphStyle],
) -> List[Paragraph]:
    """Explain source algorithms, reference points and capture limits.

    Args:
        language (str): Supported report language.
        styles (Dict[str, ParagraphStyle]): Paragraph styles for notes.

    Returns:
        List[Paragraph]: Localized, version-derived method and uncertainty notes.
    """
    values = {
        "window": _method_threshold(MAX_DESCENT_FIT_SECONDS, language),
        "minimum": _method_threshold(MIN_DESCENT_FIT_SECONDS, language),
        "maximum_gap": _method_threshold(
            MAX_DESCENT_SAMPLE_GAP_SECONDS,
            language,
        ),
        "rate": str(RECORDER_TARGET_HZ),
        "precontact": _method_threshold(PRECONTACT_WINDOW_SECONDS, language),
        "rollout": _method_threshold(ROLLOUT_WINDOW_SECONDS, language),
    }
    keys = (
        "descent_method",
        "reference_method",
        "runway_method",
        "gap_method",
        "sampling_method",
        "contact_method",
        "landing_window_method",
    )
    return [
        _paragraph(tr(language, key).format(**values), styles["small"])
        for key in keys
    ]

def _landing_rows(
    analysis: FlightAnalysis,
    language: str,
    styles: Dict[str, ParagraphStyle],
) -> List[List[Union[Paragraph, str]]]:
    """Build localized landing-window table rows from detected evidence.

    Args:
        analysis (FlightAnalysis): Landing windows detected by flight analysis.
        language (str): Report language.
        styles (Dict[str, ParagraphStyle]): Report paragraph styles.

    Returns:
        List[List[Union[Paragraph, str]]]: Header plus one row per landing, with unavailable
            measurements clearly labeled.
    """
    headings = [
        "landing",
        "window",
        "landing_descent",
        "contacts",
        "confidence",
        "bounce",
        "previous_distance",
    ]
    rows: List[List[Union[Paragraph, str]]] = [
        [_paragraph(tr(language, key), styles["small"]) for key in headings]
    ]
    for landing in analysis.landings:
        start = _format_duration(landing.first_contact_elapsed_seconds, language)
        end = _format_duration(landing.last_contact_elapsed_seconds, language)
        descent = (
            tr(language, "unavailable")
            if landing.descent_rate_fpm is None
            else _number(landing.descent_rate_fpm, language) + " fpm"
        )
        distance = (
            tr(language, "unavailable")
            if landing.distance_from_previous_nm is None
            else _number(landing.distance_from_previous_nm, language) + " nm"
        )
        evidence = ", ".join(
            _safe_source_text(value) for value in landing.evidence
        ) or tr(language, "unavailable")
        latitude = _coordinate(landing.latitude_deg, language)
        longitude = _coordinate(landing.longitude_deg, language)
        location = (
            tr(language, "latitude") + ": " + latitude + " · "
            + tr(language, "longitude") + ": " + longitude
        )
        rollout_distance = (
            tr(language, "unavailable")
            if getattr(landing, "rollout_distance_nm", None) is None
            else _number(landing.rollout_distance_nm, language) + " nm"
        )
        rollout = (
            tr(language, "rollout_duration") + ": "
            + _format_duration(
                getattr(landing, "rollout_seconds", None),
                language,
            )
            + " · "
            + tr(language, "rollout_distance")
            + ": "
            + rollout_distance
        )
        rows.append(
            [
                _paragraph(str(landing.index), styles["center"]),
                _paragraph(start + " – " + end, styles["small"]),
                _paragraph(descent, styles["small"]),
                _paragraph(str(landing.contact_count), styles["center"]),
                _paragraph(
                    _translate_confidence(landing.confidence, language),
                    styles["small"],
                ),
                _paragraph(
                    tr(language, "yes") if landing.bounce_possible else tr(language, "no"),
                    styles["small"],
                ),
                _paragraph(distance, styles["small"]),
            ]
        )
        rows.append(
            [
                _paragraph(
                    location + " · " + tr(language, "location_caveat")
                    + " · " + rollout
                    + " · " + tr(language, "evidence") + ": " + evidence,
                    styles["small"],
                ),
                "",
                "",
                "",
                "",
                "",
                "",
            ]
        )
    return rows

def _landing_table(
    analysis: FlightAnalysis,
    language: str,
    styles: Dict[str, ParagraphStyle],
) -> Table:
    """Create the landing summary table or explicit empty-state message.

    Args:
        analysis (FlightAnalysis): Detected landing windows.
        language (str): Report language.
        styles (Dict[str, ParagraphStyle]): Report paragraph styles.

    Returns:
        Table: Landing rows, or a single message when none were detected.
    """
    if not analysis.landings:
        table = Table(
            [[_paragraph(tr(language, "no_landings"), styles["body"])]],
            colWidths=[165 * mm],
        )
        table.setStyle(
            TableStyle(
                [
                    ("BACKGROUND", (0, 0), (-1, -1), _COLOR_PANEL),
                    ("BOX", (0, 0), (-1, -1), 0.5, _COLOR_GRID),
                    ("PADDING", (0, 0), (-1, -1), 3 * mm),
                ]
            )
        )
        return table
    widths = [13 * mm, 29 * mm, 23 * mm, 18 * mm, 25 * mm, 22 * mm, 27 * mm]
    rows = _landing_rows(analysis, language, styles)
    table = Table(
        rows,
        colWidths=widths,
        repeatRows=1,
        hAlign="LEFT",
    )
    table.setStyle(
        TableStyle(
            [
                ("BACKGROUND", (0, 0), (-1, 0), _COLOR_PANEL),
                ("LINEBELOW", (0, 0), (-1, 0), 0.7, _COLOR_GRID),
                *[
                    ("SPAN", (0, row), (-1, row))
                    for row in range(2, len(rows), 2)
                ],
                ("BACKGROUND", (0, 2), (-1, 2), colors.white),
                ("LINEBELOW", (0, 1), (-1, -1), 0.35, _COLOR_GRID),
                ("VALIGN", (0, 0), (-1, -1), "TOP"),
                ("LEFTPADDING", (0, 0), (-1, -1), 2),
                ("RIGHTPADDING", (0, 0), (-1, -1), 2),
                ("TOPPADDING", (0, 0), (-1, -1), 3),
                ("BOTTOMPADDING", (0, 0), (-1, -1), 3),
            ]
        )
    )
    return table

def _quality_notes(
    analysis: FlightAnalysis,
    language: str,
    styles: Dict[str, ParagraphStyle],
) -> List[Paragraph]:
    """Build uncertainty and recorder notes without inventing missing data.

    Args:
        analysis (FlightAnalysis): Flight quality flags and warning messages.
        language (str): Report language.
        styles (Dict[str, ParagraphStyle]): Report paragraph styles.

    Returns:
        List[Paragraph]: Human-readable notes for incomplete capture, track
            gaps, and each provided warning.
    """
    notes: List[Paragraph] = []
    if not analysis.completed:
        notes.append(_paragraph(tr(language, "estimate"), styles["body"]))
    if any(sample.discontinuity_before for sample in analysis.samples):
        notes.append(_paragraph(tr(language, "discontinuity"), styles["body"]))
    for warning in analysis.warnings:
        notes.append(
            _paragraph("• " + _safe_source_text(warning), styles["body"])
        )
    if not notes:
        notes.append(_paragraph(tr(language, "unavailable"), styles["small"]))
    return notes
