# Windows companion instructions

Read [root AGENTS](../AGENTS.md), [winapp/README.md](README.md) and applicable
[checked authoring examples](../docs/AGENT_AUTHORING.md) before editing. CI and
packaging changes also require [.github rules](../.github/AGENTS.md) and
[scripts rules](../scripts/AGENTS.md); fixtures require [tests rules](../tests/AGENTS.md).

Never use Python `from __future__ import annotations`. Write factual docstrings
and native types with every new/changed named function, method or class; describe
all parameters, concrete structures and return variants. Preserve comments and
complete native headers. Extend the existing owner instead of duplicating policy.

For callbacks without explicit parameters, omit `Args:` and document `None`
only under `Returns:`. List only parameters present in the native signature.

Write Python parameters as Google-style `Args:` entries, for example
`enabled (bool): Whether this watcher admits UTC synchronization.` Write the
result under `Returns:`, including `None` for a procedure. Use the checked
Python example in [authoring](../docs/AGENT_AUTHORING.md#python-native-annotations-and-typed-google-style-docstring)
when completing a declaration; parameter names, types and descriptions must
match its native signature, including optional and variadic arguments.
Describe callbacks with their `Callable` signatures, host objects with concrete
Pillow/Tk/ctypes types, and JSON inputs with their actual elements/fields.

Before publishing Python source built through an API or escaped string,
inspect the returned blob at every replaced declaration boundary. Keep physical
line breaks as source lines; retain literal escapes only inside Python strings.

When a fixture bypasses a worker constructor, initialize its complete current
lifecycle state and preserve the behavioral assertions for existing consumers.
Give widget-constructor spies a distinct instance per call and model the invoking
toplevel's numeric geometry when exercising shared dialog placement. Assert the
parent container's row separately from its child controls' rows after nesting.
For ReportLab documents, set forwarded canvas options on their document owner;
verify actual PDF catalog metadata and stable output hashes after manifest loss.
Keep binary assertion messages concise while retaining the exact conditions.

Scope simulated Windows platform attributes to the tested transport module's
namespace so host pathlib/profile lookup and diagnostic redaction retain the
runner's actual platform during portable native fixtures.

Supply every required sample-builder field in each replay phase, including
explicit zero vertical speed for fixed-altitude airborne and ground rows.
Review all related builder calls when adding vectors; keep the helper's required
arguments and behavioral assertions strict.

PHP Gallery remains authoritative for server authentication, validation, storage
and access policy; preserve the companion's documented compatibility entrypoints.
Keep permanent Markdown and affected sections in all four TeX sources synchronized.
Hosted central CI owns native WinApp coverage; do not substitute routine local loops.

Preserve the independent WinApp version unless explicitly asked to change it.
Rebuild the installer only when shipped app code/assets/dependencies/runtime
binaries or installer/build behavior changed since the prior installer. CMS-only
markers, docs and test fixes leave winapp/dist untouched. Installer generation,
qualification and publication are separate; release rules are in RELEASE.md and
docs/AGENTS.md. Do not create deploy bundles or publish without explicit authority.

When editing Google-style parameter docs, preserve the `Args:` heading for
all remaining parameters after removing implicit `self`/`cls`. Document each
variadic argument with its native element type, and retain concrete SDK argument
order in the prose. Keep continuation prose free of parameter-like `name:`
fragments so declaration scanners can distinguish it from parameter entries.
