# Chromium sandbox capability for disposable OCI CI

This test-only seccomp profile is derived from the upstream Moby/Docker
`moby/profiles/seccomp/default.json` with source Git blob SHA
`6e5e47e242892aaa290f916ba1045f9213ef0b84` (retrieved 2026-10-15).

The Docker default denies Chromium's unprivileged namespace creation.
The experiment retains the upstream default-deny syscall policy and all other
syscall restrictions, but permits `clone` flags and `unshare`/`setns` needed
for Chromium's own Linux namespace sandbox. `clone3` continues to return
ENOSYS for the legacy clone fallback. This is enabled **only** in owned test
containers executing required Chromium fixtures and the real DB browser
workflow, not for other test containers or CI. No SYS_ADMIN capability or
`--no-sandbox` is used. The host remains a disposable GitHub-hosted runner.

Compared with ordinary Docker this is still a deliberate security relaxation
for browser-bearing test containers; test-only content must remain isolated.
It is not an assertion that all kernels or AppArmor policies will permit
nested Chromium namespaces. Verify on hosted runner and retain any failures.
