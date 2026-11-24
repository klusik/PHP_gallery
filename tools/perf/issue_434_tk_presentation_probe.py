# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: tools/perf/issue_434_tk_presentation_probe.py
# Module Type: Tk Presentation Instrumentation
# Purpose: Count successful Tk presentation mutations during isolated measurements.
# Responsibilities:
#   - Observe StringVar assignments, widget configuration and state mutations.
#   - Count widget mapping and unmapping calls without retaining displayed text.
#   - Restore wrapped Tk methods while preserving any wrappers installed earlier.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Measure bounded Tk presentation mutations in one isolated child process."""

import hashlib
import tkinter as tk
import tkinter.ttk as ttk
from collections import Counter
from functools import wraps
from typing import Callable, TypeAlias

# Purpose: Bound distinct Tcl variable names retained for exact repeat detection.
# Type: int. Units: variables. Scope: one measured presentation phase.
# Consumers: PresentationProbe._last_assignments.
# Rationale: 10,000 names covers large shell fixtures while bounding child memory.
STRINGVAR_LEDGER_LIMIT = 10000

# Purpose: Retain digests rather than presentation text for repeat comparisons.
# Type: int. Units: digest bytes. Scope: one retained Tcl variable assignment.
# Consumers: PresentationProbe._record_stringvar_assignment.
# Rationale: SHA-256 makes collisions negligible without storing user-visible text.
ASSIGNMENT_DIGEST_BYTES = 32

_MISSING_ATTRIBUTE = object()
TkOption: TypeAlias = (
    str | int | float | bool | None | tk.Misc | tk.Variable | tk.Image
    | list[str] | tuple["TkOption", ...] | dict[str, "TkOption"]
    | Callable[..., str | bool | None]
)


class PresentationProbe:
    """Wrap Tk presentation APIs and count successful mutations by category."""

    def __init__(self) -> None:
        """Create an inactive probe with no installed Tk wrappers.

        Returns:
            None: The probe is ready for installation in its child process.
        """
        self._installed: list[tuple[type[object], str, object, bool]] = []
        self._counts: Counter[str] = Counter()
        self._last_assignments: dict[str, bytes] = {}
        self._measuring = False
        self._installed_once = False

    def install(self) -> None:
        """Install wrappers for supported Tk presentation mutation methods.

        Returns:
            None: Calls during the inactive phase are ignored by the counters.
        """
        if self._installed_once:
            return
        self._installed_once = True
        self._wrap_method(
            tk.StringVar, "set", "stringvar_set_calls",
            self._stringvar_mutation,
        )
        self._wrap_method(
            tk.Misc, "configure", "widget_configure_calls",
            self._configure_mutation,
        )
        self._wrap_method(
            ttk.Widget, "state", "widget_state_mutations",
            self._state_mutation,
        )
        for owner, method_names, counter in (
            (tk.Pack, ("pack", "pack_configure"), "widget_map_calls"),
            (tk.Pack, ("pack_forget", "forget"), "widget_unmap_calls"),
            (tk.Grid, ("grid", "grid_configure"), "widget_map_calls"),
            (tk.Grid, ("grid_forget", "grid_remove"), "widget_unmap_calls"),
            (tk.Place, ("place", "place_configure"), "widget_map_calls"),
            (tk.Place, ("place_forget",), "widget_unmap_calls"),
        ):
            for method_name in method_names:
                self._wrap_method(
                    owner, method_name, counter,
                )

    def begin_measurement(self) -> None:
        """Reset counters and begin recording successful presentation changes.

        Returns:
            None: The measured phase starts with an empty bounded ledger.
        """
        self._counts.clear()
        self._last_assignments.clear()
        self._measuring = True

    def end_measurement(self) -> None:
        """Stop recording while retaining the completed phase measurements.

        Returns:
            None: Existing counters remain readable until the next begin call.
        """
        self._measuring = False

    def metrics(self) -> dict[str, int]:
        """Return presentation mutation counts without exposing assigned text.

        Returns:
            dict[str, int]: Counts for successful mutation categories and repeat
                StringVar assignments observed for a stable Tcl variable name.
        """
        keys = (
            "stringvar_set_calls",
            "stringvar_repeated_observed_assignments",
            "stringvar_ledger_overflow",
            "widget_configure_calls",
            "widget_state_mutations",
            "widget_map_calls",
            "widget_unmap_calls",
        )
        return {key: int(self._counts[key]) for key in keys}

    def restore(self) -> None:
        """Restore the exact attributes that existed before probe installation.

        Returns:
            None: Earlier wrappers remain installed and this probe is inactive.
        """
        self._measuring = False
        for owner, name, previous, was_local in reversed(self._installed):
            if was_local:
                setattr(owner, name, previous)
            else:
                delattr(owner, name)
        self._installed.clear()
        self._installed_once = False

    def _wrap_method(
        self,
        owner: type[object],
        name: str,
        counter: str,
        mutation_check: Callable[[tuple[TkOption, ...], dict[str, TkOption]], bool] | None = None,
    ) -> None:
        """Wrap one class method and retain its prior class attribute.

        Args:
            owner (type[object]): Tk class that owns the method to observe.
            name (str): Method attribute name to wrap.
            counter (str): Metric key incremented after a successful mutation.
            mutation_check (Callable[[tuple[TkOption, ...], dict[str, TkOption]], bool] | None):
                Predicate for setters among queries, or None for mapping methods.

        Returns:
            None: The owner now dispatches through the counting wrapper.
        """
        local_previous = owner.__dict__.get(name, _MISSING_ATTRIBUTE)
        previous = getattr(owner, name, None)
        if previous is None:
            return

        @wraps(previous)
        def observed(*args: TkOption, **kwargs: TkOption) -> TkOption:
            """Count a successful setter call and preserve its original result.

            Args:
                args (TkOption): Tk receiver followed by actual option values.
                kwargs (TkOption): Named Tk options forwarded unchanged.

            Returns:
                TkOption: Original mutation or query result.
            """
            result = previous(*args, **kwargs)
            if self._measuring and (mutation_check is None or mutation_check(args[1:], kwargs)):
                self._counts[counter] += 1
                if counter == "stringvar_set_calls":
                    variable_name = getattr(args[0], "_name", None)
                    if isinstance(variable_name, str):
                        assigned_value = args[1] if len(args) > 1 else kwargs.get("value", "")
                        self._record_stringvar_assignment(
                            variable_name, assigned_value,
                        )
            return result

        self._installed.append((
            owner, name, local_previous,
            local_previous is not _MISSING_ATTRIBUTE,
        ))
        setattr(owner, name, observed)

    def _record_stringvar_assignment(
        self, variable_name: str, assigned_value: TkOption,
    ) -> None:
        """Count a repeated value for one stable Tcl variable name.

        Args:
            variable_name (str): Python-side Tcl variable identity from StringVar.
            assigned_value (TkOption): Assignment passed to StringVar.set, hashed in memory.

        Returns:
            None: Only the digest and stable variable name remain in the ledger.
        """
        if variable_name not in self._last_assignments:
            if len(self._last_assignments) >= STRINGVAR_LEDGER_LIMIT:
                self._counts["stringvar_ledger_overflow"] += 1
                return
        value_digest = hashlib.sha256(
            str(assigned_value).encode("utf-8", "surrogatepass"),
        ).digest()[:ASSIGNMENT_DIGEST_BYTES]
        if self._last_assignments.get(variable_name) == value_digest:
            self._counts["stringvar_repeated_observed_assignments"] += 1
        self._last_assignments[variable_name] = value_digest

    @staticmethod
    def _stringvar_mutation(
        args: tuple[TkOption, ...], kwargs: dict[str, TkOption],
    ) -> bool:
        """Recognize StringVar.set calls that contain an assignment value.

        Args:
            args (tuple[TkOption, ...]): Explicit method arguments excluding self.
            kwargs (dict[str, TkOption]): Keyword arguments passed to set.

        Returns:
            bool: True when a value argument was supplied.
        """
        return bool(args) or "value" in kwargs

    @staticmethod
    def _configure_mutation(
        args: tuple[TkOption, ...], kwargs: dict[str, TkOption],
    ) -> bool:
        """Recognize widget configuration mappings and keyword mutations.

        Args:
            args (tuple[TkOption, ...]): Explicit configure arguments excluding self.
            kwargs (dict[str, TkOption]): Widget option assignments.

        Returns:
            bool: True for an option mapping or keyword assignment, not a query.
        """
        return bool(kwargs) or bool(args and isinstance(args[0], dict))

    @staticmethod
    def _state_mutation(
        args: tuple[TkOption, ...], kwargs: dict[str, TkOption],
    ) -> bool:
        """Recognize state setter calls while excluding state queries.

        Args:
            args (tuple[TkOption, ...]): Explicit state arguments excluding self.
            kwargs (dict[str, TkOption]): Named state specification, if supplied.

        Returns:
            bool: True when a non-None state specification was supplied.
        """
        return bool(args and args[0] is not None) or kwargs.get("statespec") is not None

