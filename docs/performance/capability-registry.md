<!--
Project: PHP Gallery
Repository: https://github.com/klusik/PHP_gallery
File: docs/performance/capability-registry.md
Module Type: Performance reference
Purpose: Describe structural capability metadata reuse without caching policy state.
Responsibilities: Record localization boundaries, live state and verification scope.
Author: Rudolf Klusal
License: MIT License (see LICENSE file in repository)
-->

# Capability registry metadata reuse

`feature_capability_definitions()` owns one literal registry. Its default
localized mode continues to translate labels and descriptions on every call.
`feature_capability_definition()` and the legacy registry wrapper retain this
default, so Admin language changes and translation missing-key behavior remain
live. Group labels retain their existing translation owner too.

Policy-only existence, default, configured and effective lookups explicitly
request structural mode. This mode returns fixed fallback text without invoking
the translator and retains the completed normalized metadata array within the
request. The builder contains literal declarations, fixed metadata defaults and
a fixed presentation-metadata merge. It reads no configured settings, effective
capability state, user identity, request language or authorization. Returned
arrays use PHP value semantics; mutating a caller's copy does not modify the
retained canonical declarations.

Effective evaluation reuses its already resolved definition for the canonical
source read. Every invocation still reads the registered live source and checks
each dependency. Native flags, app settings, environment fallback and lazy domain
adapters keep their existing owners and refusal behavior. Unknown keys remain
fail-closed, legacy unknown extension keys retain their documented compatibility
behavior, and recursive dependency evaluation retains its cycle guard. There is
no configured/effective/authentication cache and no policy decision memoization.

The performance target is repeated full translated-registry construction during
Smart card GPS, voting and tag-browsing checks. Structural reuse avoids repeating
the registry's translation calls without moving policy into card renderers or
dropping controls. Measured latency savings remain unqualified until the parent
task records paired requests; static review is not runtime evidence.

`feature_policy_core_test.php` extends the existing unknown/dependency/registry
contracts with translator-call accounting, mutable localized labels, legacy
localized defaults, structural-array isolation and same-request dependency
changes. `feature_policy_adapters_test.php` checks immediate domain-setting
changes after structural metadata is retained and verifies these policy reads
do not translate labels. No local tests, database operations or generators were
executed for this change; hosted central qualification remains authoritative.

No TeX manual impact: this reuses immutable declarations internally while
preserving supported policy, administration, localization, access and deployment
behavior.
