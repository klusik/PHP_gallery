# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/flight_simbrief.py
# Module Type: Flight Dispatch Service
# Purpose: Fetch and retain optional SimBrief dispatch evidence.
# Responsibilities:
#   - Validate one pilot identity and normalize a bounded OFP snapshot.
#   - Keep planned dispatch data separate from simulator measurements.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Optional SimBrief dispatch snapshots without gallery credentials."""

import json
import math
import re
from dataclasses import asdict, dataclass, field, fields
from datetime import datetime, timezone
from pathlib import Path
from urllib.parse import urlencode
from urllib.request import Request, urlopen
from xml.etree import ElementTree

from uploader.state_store import atomic_write_json
from uploader.models import JsonValue

# Bound an explicit interactive fetch, independently of flight acquisition.
# Type: int; Units: seconds; Scope: SimBrief transport.
# Consumers: fetch_dispatch. Rationale: 20 seconds bounds a failed request.
FETCH_TIMEOUT = 20
# Bound decoded public dispatch data before JSON allocation.
# Type: int; Units: bytes; Scope: one OFP response.
# Consumers: fetch_dispatch. Rationale: 4 MiB accommodates detailed navlogs.
OFP_MAX_BYTES = 4 * 1024 * 1024
# Bound retained route detail and corrupt local snapshots.
# Type: int; Units: fixes; Scope: one dispatch snapshot.
# Consumers: parse_dispatch. Rationale: 2000 admits long routes with a fixed cap.
MAX_FIXES = 2000
# Bound public text stored in reports and identity requests.
# Type: int; Units: characters; Scope: normalized dispatch text.
# Consumers: parse_dispatch and identity_query. Rationale: 4096 retains a long
# routing; 128 accommodates pilot names without admitting unbounded URL inputs.
MAX_OFP_TEXT = 4096
# Bound the single pilot identity used in the provider URL.
# Type: int; Units: characters; Scope: one SimBrief identity.
# Consumers: identity_query. Rationale: 128 retains normal pilot names.
MAX_IDENTITY_TEXT = 128


@dataclass
class DispatchPoint:
    """One OFP fix: degrees, optional takeoff-relative seconds, feet and fuel mass.

    Fuel retains the parent DispatchPlan units. Missing optional profile values
    stay None, including coordinates-only snapshots from earlier app versions.
    Airspeeds and wind speed use knots; wind direction is true direction-from
    in degrees. Stage retains the provider's CLB/CRZ/DSC label when supplied.
    """

    ident: str
    latitude: float
    longitude: float
    elapsed_seconds: float | None = None
    altitude_ft: float | None = None
    fuel_onboard: float | None = None
    true_airspeed_kt: float | None = None
    ground_speed_kt: float | None = None
    wind_direction_deg: float | None = None
    wind_speed_kt: float | None = None
    stage: str = ""


@dataclass
class DispatchPlan:
    """Planned OFP values; none establishes an observed onboard quantity."""

    origin: str
    destination: str
    route: str
    aircraft: str
    passengers: str
    fuel: str
    units: str
    airac: str
    fetched_utc: str
    points: list[DispatchPoint] = field(default_factory=list)
    flight_number: str = ""
    callsign: str = ""
    registration: str = ""
    origin_name: str = ""
    destination_name: str = ""
    origin_runway: str = ""
    destination_runway: str = ""
    alternate: str = ""
    duration_seconds: str = ""
    distance_nm: str = ""
    cruise_altitude_ft: str = ""
    cost_index: str = ""
    cargo: str = ""
    payload: str = ""
    zero_fuel_weight: str = ""
    takeoff_weight: str = ""
    landing_weight: str = ""
    fuel_trip: str = ""
    fuel_taxi: str = ""
    fuel_reserve: str = ""
    fuel_contingency: str = ""
    scheduled_departure_utc: str = ""
    scheduled_arrival_utc: str = ""
    scheduled_takeoff_utc: str = ""
    scheduled_landing_utc: str = ""
    block_seconds: str = ""
    taxi_out_seconds: str = ""
    taxi_in_seconds: str = ""
    transition_altitude_ft: str = ""
    arrival_transition_altitude_ft: str = ""
    cruise_tas_kt: str = ""
    distance_source: str = ""

    def save(self, directory: Path) -> None:
        """Atomically retain the exact plan attached to a single flight.

        Args:
            directory (Path): Owned flight session directory.
        Returns:
            None: Writes simbrief.json without modifying telemetry.
        """
        atomic_write_json(directory / "simbrief.json", asdict(self))


def identity_query(identity: str) -> dict[str, str]:
    """Classify one pilot ID or username without accepting URL fragments.

    Args:
        identity (str): User-entered decimal pilot ID or SimBrief username.
    Returns:
        dict[str, str]: userid or username plus the JSON format selector.
    Raises:
        ValueError: The identity is empty or contains unsupported characters.
    """
    identity = identity.strip()
    if len(identity) > MAX_IDENTITY_TEXT or not re.fullmatch(r"[A-Za-z0-9_.@-]+", identity):
        raise ValueError("Enter a SimBrief pilot ID or username.")
    return {"userid" if identity.isascii() and identity.isdecimal() else "username": identity, "json": "1"}


def parse_dispatch(payload: dict[str, JsonValue]) -> DispatchPlan:
    """Normalize provider airport/navlog coordinates and planned quantities.

    Args:
        payload (dict[str, JsonValue]): SimBrief JSON object containing origin/destination
            airport names/runways/coordinates, general flight identity,
            route/distance/cruise altitude, weights passenger/cargo/payload,
            takeoff/landing/zero-fuel weights, fuel ramp/trip/taxi/reserve,
            times estimated enroute/scheduled out/in, alternate airport objects
            or lists, aircraft registration,
            params units/AIRAC and navlog.fix ident/pos_lat/pos_long rows.
            Optional times sched_off/sched_on/est_block/taxi_out/taxi_in,
            airport trans_alt and general cruise_tas preserve schedule/reference
            evidence. Navlog rows may also retain true_airspeed, groundspeed,
            wind_dir/wind_spd and stage, all independently optional.
    Returns:
        DispatchPlan: Valid airport identity and bounded provider-resolved fixes.
    Raises:
        ValueError: No identifiable origin and destination OFP is available.
    """
    def section(name: str) -> dict[str, JsonValue]:
        """Read a provider object while tolerating omitted optional sections.

        Args:
            name (str): Top-level provider section name.
        Returns:
            dict[str, JsonValue]: Provider section fields including airport
                icao_code/pos_lat/pos_long, general route/units, weights
                pax_count, fuel plan_ramp, params airac or navlog fix rows.
        """
        value = payload.get(name)
        return value if isinstance(value, dict) else {}

    def text(section_name: str, *keys: str) -> str:
        """Read the first available provider scalar without inventing a value.

        Args:
            section_name (str): Provider object containing the requested fields.
            *keys (str): Ordered known scalar field alternatives within it.
        Returns:
            str: Original scalar text, preserving zero and leading zeroes;
                empty text for missing, boolean or structured values.
        """
        values = section(section_name)
        for key in keys:
            value = values.get(key)
            if not isinstance(value, bool) and isinstance(value, (str, int, float)):
                if str(value).strip():
                    return str(value).strip()
        return ""

    def utc_time(key: str) -> str:
        """Normalize one provider Unix timestamp into explicit UTC text.

        Args:
            key (str): Scheduled out/in timestamp field in the times section.
        Returns:
            str: ISO UTC time, or empty text for absent/invalid timestamps.
        """
        try:
            stamp = float(text("times", key))
            if not math.isfinite(stamp) or stamp <= 0:
                return ""
            return datetime.fromtimestamp(stamp, timezone.utc).isoformat(timespec="seconds")
        except (ValueError, OSError, OverflowError):
            return ""

    def numeric(value: JsonValue) -> float | None:
        """Read finite nonnegative provider numbers, preserving missing values.

        Args:
            value (JsonValue): Provider scalar or structured field to inspect.
        Returns:
            float | None: Nonnegative numeric value, or None for unknown data.
        """
        if isinstance(value, bool):
            return None
        try:
            result = float(value)
            return result if math.isfinite(result) and result >= 0 else None
        except (ValueError, TypeError):
            return None

    def elapsed(value: JsonValue) -> float | None:
        """Read navlog cumulative seconds or an archived clock duration.

        Args:
            value (JsonValue): Cumulative takeoff-relative seconds or HH:MM:SS.
        Returns:
            float | None: Seconds since planned takeoff, or None if unavailable.
        """
        if isinstance(value, str) and ":" in value:
            parts = value.split(":")
            if len(parts) != 3 or any(not part.isdecimal() for part in parts):
                return None
            hours, minutes, seconds = (int(part) for part in parts)
            if minutes >= 60 or seconds >= 60:
                return None
            return float(hours * 3600 + minutes * 60 + seconds)
        return numeric(value)

    origin, destination = section("origin"), section("destination")
    if not origin.get("icao_code") or not destination.get("icao_code"):
        raise ValueError("SimBrief has no valid OFP for this identity.")
    rows = section("navlog").get("fix", [])
    if isinstance(rows, dict):
        rows = [rows]
    if not isinstance(rows, list) or len(rows) > MAX_FIXES:
        raise ValueError("SimBrief route exceeds the supported fix limit.")
    points = []
    for row in [origin, *rows, destination]:
        if not isinstance(row, dict):
            continue
        try:
            lat, lon = float(row["pos_lat"]), float(row["pos_long"])
        except (KeyError, TypeError, ValueError):
            continue
        if math.isfinite(lat) and math.isfinite(lon) and -90 <= lat <= 90 and -180 <= lon <= 180:
            time = elapsed(row.get("time_total"))
            fuel = numeric(row.get("fuel_plan_onboard"))
            altitude = numeric(row.get("altitude_feet"))
            if row is origin:
                time, fuel = 0.0, numeric(section("fuel").get("plan_takeoff"))
            elif row is destination:
                time = elapsed(section("times").get("est_time_enroute"))
                fuel = numeric(section("fuel").get("plan_landing"))
            points.append(DispatchPoint(
                str(row.get("ident", row.get("icao_code", ""))), lat, lon,
                time, altitude, fuel,
                true_airspeed_kt=numeric(row.get("true_airspeed")),
                ground_speed_kt=numeric(row.get("groundspeed")),
                wind_direction_deg=numeric(row.get("wind_dir")),
                wind_speed_kt=numeric(row.get("wind_spd")),
                stage=(row["stage"][:MAX_OFP_TEXT] if isinstance(row.get("stage"), str) else ""),
            ))
    general = section("general")
    airline = text("general", "iata_airline", "icao_airline", "airline").upper()
    number = text("general", "flight_number", "fltnum", "flight").upper()
    flight_number = airline + number if number.isascii() and number.isdecimal() else number
    alternate = text("alternate", "icao_code", "icao")
    alternate_rows = payload.get("alternate")
    if isinstance(alternate_rows, dict):
        alternate_rows = list(alternate_rows.values())
    if not alternate and isinstance(alternate_rows, list):
        for row in alternate_rows:
            if not isinstance(row, dict):
                continue
            candidate = row.get("icao_code") or row.get("icao")
            if isinstance(candidate, str) and candidate.strip():
                alternate = candidate.strip()
                break
    alternate = alternate or text("general", "alternate", "altn")
    plan = DispatchPlan(
        str(origin["icao_code"]), str(destination["icao_code"]),
        str(general.get("route", "")), str(section("aircraft").get("icaocode", "")),
        str(section("weights").get("pax_count", "")),
        str(section("fuel").get("plan_ramp", "")),
        str(section("params").get("units", general.get("units", ""))),
        str(section("params").get("airac", "")),
        datetime.now(timezone.utc).isoformat(timespec="seconds"), points,
        flight_number=flight_number,
        callsign=text("general", "callsign", "atc_callsign"),
        registration=text("aircraft", "reg", "registration"),
        origin_name=text("origin", "name"),
        destination_name=text("destination", "name"),
        origin_runway=text("origin", "plan_rwy", "runway"),
        destination_runway=text("destination", "plan_rwy", "runway"),
        alternate=alternate,
        duration_seconds=text("times", "est_time_enroute", "ete"),
        distance_nm=text("general", "route_distance", "air_distance"),
        cruise_altitude_ft=text("general", "initial_altitude", "cruise_altitude"),
        cost_index=text("general", "costindex", "cost_index"),
        cargo=text("weights", "cargo"),
        payload=text("weights", "payload"),
        zero_fuel_weight=text("weights", "est_zfw", "zfw"),
        takeoff_weight=text("weights", "est_tow", "tow"),
        landing_weight=text("weights", "est_ldw", "ldw"),
        fuel_trip=text("fuel", "enroute_burn", "plan_trip"),
        fuel_taxi=text("fuel", "taxi", "plan_taxi"),
        fuel_reserve=text("fuel", "reserve", "plan_reserve"),
        fuel_contingency=text("fuel", "contingency", "plan_contingency"),
        scheduled_departure_utc=utc_time("sched_out"),
        scheduled_arrival_utc=utc_time("sched_in"),
        scheduled_takeoff_utc=utc_time("sched_off"),
        scheduled_landing_utc=utc_time("sched_on"),
        block_seconds=text("times", "est_block"),
        taxi_out_seconds=text("times", "taxi_out"),
        taxi_in_seconds=text("times", "taxi_in"),
        transition_altitude_ft=text("origin", "trans_alt"),
        arrival_transition_altitude_ft=text("destination", "trans_alt"),
        cruise_tas_kt=text("general", "cruise_tas"),
        distance_source=("route" if text("general", "route_distance") else "air"),
    )
    for name in (item.name for item in fields(plan) if item.name != "points"):
        value = getattr(plan, name)
        if len(value) > MAX_OFP_TEXT:
            raise ValueError("SimBrief text exceeds the supported limit.")
        if value == "None":
            setattr(plan, name, "")
    return plan


def fetch_dispatch(identity: str) -> DispatchPlan:
    """Fetch the latest explicit OFP using TLS and bounded transport.

    Args:
        identity (str): Pilot ID or username; never a Gallery credential.
    Returns:
        DispatchPlan: Validated optional dispatch snapshot.
    Raises:
        ValueError: Response exceeds bounds or contains no valid OFP.
        OSError: The provider cannot be reached.
    """
    url = "https://www.simbrief.com/api/xml.fetcher.php?" + urlencode(identity_query(identity))
    request = Request(url, headers={"User-Agent": "PHPGalleryUploader/0.6.0 (+https://github.com/klusik/PHP_gallery)"})
    with urlopen(request, timeout=FETCH_TIMEOUT) as response:
        raw = response.read(OFP_MAX_BYTES + 1)
    if len(raw) > OFP_MAX_BYTES:
        raise ValueError("SimBrief OFP is too large.")
    payload = json.loads(raw)
    if not isinstance(payload, dict):
        raise ValueError("SimBrief returned no OFP object.")
    return parse_dispatch(payload)


def load_dispatch(directory: Path) -> DispatchPlan | None:
    """Recover an optional session snapshot, preserving legacy recordings.

    Args:
        directory (Path): Owned flight session directory.
    Returns:
        DispatchPlan | None: Saved plan, or None for absent/invalid legacy data.
    """
    path = directory / "simbrief.json"
    try:
        if path.is_symlink() or path.stat().st_size > OFP_MAX_BYTES:
            return None
        data = json.loads(path.read_text(encoding="utf-8"))
        points = data.pop("points")
        if not isinstance(points, list) or len(points) > MAX_FIXES + 2:
            return None
        plan = DispatchPlan(**data, points=[DispatchPoint(**point) for point in points])
        if not all(isinstance(value, str) and len(value) <= MAX_OFP_TEXT for value in data.values()):
            return None
        if not all(math.isfinite(p.latitude) and math.isfinite(p.longitude)
                   and -90 <= p.latitude <= 90 and -180 <= p.longitude <= 180
                   and all(value is None or (not isinstance(value, bool)
                       and isinstance(value, (float, int)) and math.isfinite(value)
                       and value >= 0) for value in
                       (p.elapsed_seconds, p.altitude_ft, p.fuel_onboard,
                        p.true_airspeed_kt, p.ground_speed_kt,
                        p.wind_direction_deg, p.wind_speed_kt))
                   and isinstance(p.ident, str) and len(p.ident) <= MAX_OFP_TEXT
                   and isinstance(p.stage, str) and len(p.stage) <= MAX_OFP_TEXT
                   for p in plan.points):
            return None
        return plan
    except (OSError, ValueError, TypeError, KeyError, AttributeError):
        return None


def import_dispatch(path: Path) -> DispatchPlan:
    """Read archived SimBrief machine-readable JSON or XML without network use.

    Args:
        path (Path): Explicitly selected exported OFP JSON or XML file.
    Returns:
        DispatchPlan: Normalized plan suitable for a historical session.
    Raises:
        ValueError: The file is oversized, malformed or has no valid OFP.
        OSError: The selected file cannot be read.
    """
    with path.open("rb") as stream:
        raw = stream.read(OFP_MAX_BYTES + 1)
    if len(raw) > OFP_MAX_BYTES:
        raise ValueError("SimBrief OFP is too large.")
    if path.suffix.lower() == ".json":
        payload = json.loads(raw)
    else:
        # Exported OFPs contain plain values; reject DTD/entity input entirely.
        if b"<!DOCTYPE" in raw.upper() or b"<!ENTITY" in raw.upper():
            raise ValueError("OFP XML cannot contain document type declarations.")
        try:
            root = ElementTree.fromstring(raw)
        except ElementTree.ParseError as exc:
            raise ValueError("Invalid SimBrief XML.") from exc
        payload = {}
        for name in (
            "origin", "destination", "alternate", "general", "aircraft",
            "weights", "fuel", "params", "times",
        ):
            node = root.find(name)
            payload[name] = {child.tag: child.text or "" for child in node} if node is not None else {}
        payload["navlog"] = {"fix": [
            {child.tag: child.text or "" for child in node}
            for node in root.findall("navlog/fix")
        ]}
    if not isinstance(payload, dict):
        raise ValueError("SimBrief returned no OFP object.")
    return parse_dispatch(payload)
