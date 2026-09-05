# Implementation Plan: License Activation Gate

**Branch**: `018-license-activation` | **Date**: 2026-09-05 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/018-license-activation/spec.md`

## Summary

A global gate — enforced server-side (Constitution IV) and mirrored client-side for UX — that blocks every application function until this specific installation has redeemed a valid, vendor-signed license key. The key is an Ed25519-signed payload (PHP's built-in `sodium` extension, already available — no new dependency) verifiable entirely offline using a public key shipped with the app. On first successful verification, the installation computes a machine fingerprint (OS-specific: `/etc/machine-id` on Linux, `IOPlatformUUID` via `ioreg` on macOS) and stores an `Crypt`-encrypted activation record binding the license to that fingerprint — every later request re-checks the *current* machine's fingerprint against that record, not the original key, so copying the app's data to a different machine fails closed. A new middleware enforces this on every API route except the two license endpoints themselves (`status`, `activate`); the Vue router's existing `beforeEach` guard is extended with the same check, ahead of its existing auth check, so an unlicensed installation shows the lock page for every URL including `/login`. License generation/sending is vendor-side tooling (a new Artisan command, run by the vendor, never shipped as a customer-facing screen) — mirroring feature 016's `package-release.sh` maintainer-vs-shipped-product separation.

## Technical Context

**Language/Version**: PHP 8.4 (Laravel 13), Vue 3 — unchanged. Uses PHP's built-in `sodium` extension (confirmed present) for Ed25519 signing/verification — no new Composer dependency.

**Primary Dependencies**: Laravel's `Crypt` facade (AES-256-CBC, already configured via `APP_KEY`) for encrypting the local activation record. No new package.

**Storage**: MySQL 8, one new table (`license_activations`) holding the encrypted activation record — a single row per installation (this app is single-tenant).

**Testing**: `tests/Feature/` covering: valid-key activation, invalid/malformed-key rejection, the global gate blocking every route (including direct API hits) pre-activation, and fail-closed behavior on a corrupted/tampered activation record. Cross-machine binding (SC-003) and fully-offline validation (SC-004) have no meaningful automated-test equivalent (they're inherently about *which machine* code runs on and *whether a network exists*) — verified manually per `quickstart.md`, mirroring how feature 016 verified its own deployment-level guarantees.

**Target Platform**: The single machine running one BoothPOS installation — native (Linux/macOS dev) per the existing architecture, and the Docker store-deployment path (feature 016) — the machine-fingerprint approach must work identically across both, which is why the Docker path needs one small addition (research.md R6).

**Project Type**: Web application (Laravel API + Vue SPA), extending the existing single-project structure.

**Performance Goals**: N/A beyond this app's existing conventions — the gate check is a single indexed row lookup plus a fast cryptographic verification, run once per request; negligible overhead (Constitution V).

**Constraints**: Zero network dependency for validation (FR-004/SC-004) — this is the single hardest constraint and shapes every other decision in this plan. Fail-closed on any ambiguity (FR-006).

**Scale/Scope**: Single installation, single activation record — this is explicitly not a multi-license or multi-seat feature.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- **Principle I (Code Quality)** — PASS. A dedicated `LicenseActivationService` holds the genuine business logic (signature verification, fingerprinting, encrypted record read/write) — not scattered across the middleware and controller that consume it.
- **Principle II (Testing)** — PASS, with a documented exception mirroring feature 016's precedent: cross-machine binding and fully-offline validation are inherently not unit-testable (they're about physical machine identity and network absence) and are verified manually via `quickstart.md` instead.
- **Principle III (UX Consistency)** — PASS. The lock page follows this app's existing design tokens/error conventions; UI copy in Indonesian by default (the activation page, like the login page, is pre-authentication and pre-locale-preference, so it follows the login screen's own "always Indonesian" precedent from feature 002).
- **Principle IV (Security)** — PASS, and this feature is largely *about* Principle IV: the gate is enforced server-side first (a global middleware blocking the API), with the frontend router guard as a UX mirror only, never the actual security boundary — directly following "every access-control decision MUST be enforced server-side; hiding a button is cosmetic only." The license key itself is never trusted at face value — its signature is cryptographically verified against a public key the app ships with, and the app never holds the private signing key needed to forge one.
- **Documentation & Change Discipline** — `docs/openapi-pos-mvp.yaml` gets the two new license endpoints in the same commit. This feature also touches `docker-compose.store.yml` (feature 016) to bind-mount `/etc/machine-id` — a small, backward-compatible addition, not a redesign of that feature.

No violations requiring the Complexity Tracking table.

## Project Structure

### Documentation (this feature)

```text
specs/018-license-activation/
├── plan.md              # This file
├── research.md          # Phase 0 output
├── data-model.md         # Phase 1 output
├── quickstart.md         # Phase 1 output
├── contracts/
│   └── api.md            # Phase 1 output — the 2 new endpoints + vendor CLI contract
└── tasks.md              # Phase 2 output (/speckit-tasks — not created here)
```

### Source Code (repository root)

```text
database/migrations/
└── 2026_10_17_000001_create_license_activations_table.php

app/Models/
└── LicenseActivation.php

app/Services/
└── LicenseActivationService.php   # verifyKey(), fingerprint(), activate(), isActivated(), currentRecordValid()

app/Support/
├── MachineFingerprint.php          # OS-specific fingerprint computation (research.md R2)
└── LicenseSigning.php              # sodium sign/verify helpers + the shipped public key constant

app/Http/Middleware/
└── EnsureInstallationIsActivated.php   # global API gate (research.md R4)

app/Http/Controllers/Api/
└── LicenseController.php           # status(), activate() — the only two unauthenticated, ungated endpoints

app/Http/Requests/
└── ActivateLicenseRequest.php

app/Console/Commands/
└── GenerateLicense.php             # vendor-side CLI, never exposed as a customer-facing screen (research.md R5)

resources/js/views/
└── LicenseLockView.vue             # the activation/lock page

resources/js/api/
└── license.js                       # getLicenseStatus(), activateLicense()

resources/js/router/index.js         # MODIFIED — beforeEach gets a license-status check ahead of the auth check

bootstrap/app.php                    # MODIFIED — registers the new middleware globally on the api group

docker-compose.store.yml             # MODIFIED (feature 016 artifact) — bind-mounts /etc/machine-id:ro (research.md R6)

lang/id/license.php, lang/en/license.php   # new locale files

docs/openapi-pos-mvp.yaml            # MODIFIED — the 2 new endpoints

tests/Feature/
└── LicenseActivationTest.php
```

**Structure Decision**: Follows this repo's existing per-concern pattern (Model + Service + Middleware + Controller + FormRequest under the existing single Laravel app; one new Vue view + API client module) — no new top-level module. The vendor-side `GenerateLicense` Artisan command lives inside this same repo (it's the only place that knows the app's own signing scheme) but is explicitly never routed/exposed to the shipped, customer-facing product — mirroring feature 016's `package-release.sh` separation between maintainer tooling and shipped artifact.

## Complexity Tracking

*No Constitution Check violations — table intentionally omitted.*

## Implementation Notes (post-execution, 2026-09-06)

All 28 tasks in `tasks.md` completed; all three user stories verified with `php artisan test` (434/434 passing, 7 new tests, zero regressions) and a real browser walkthrough (Playwright) of the full flow: unactivated installation locks `/`, `/login`, and a deep link → submit garbage (422, generic message, stays locked) → submit a real vendor-CLI-generated key (200, unlocks) → `/login` reachable → real login → `/dashboard`.

Two real bugs were found and fixed only because of that real execution (Constitution II):

1. **A global-middleware regression across the entire existing test suite.** Registering `EnsureInstallationIsActivated` on the whole `api` middleware group broke 364 of ~430 pre-existing tests (every one written before this feature existed, none of which ever activated a license first) with `423`. Fixed by having the shared `tests/TestCase::setUp()` auto-activate every test by default (inserting a `license_activations` row bound to the test-runner machine's own real fingerprint), with `LicenseActivationTest` itself overriding `setUp()` to delete that row again, since it specifically needs to start from a genuinely unactivated state. This is exactly the kind of cross-cutting consequence that would never surface from testing the new feature's own test file in isolation — only running the *entire* suite caught it.
2. **`sodium_crypto_sign_verify_detached()` throws `SodiumException` instead of returning `false`** when the signature isn't exactly `SODIUM_CRYPTO_SIGN_BYTES` (64 bytes) — found by directly testing `LicenseSigning::decodeAndVerify()` with a malformed key before it ever reached an HTTP request. Without a catch, a customer pasting a garbled/truncated license key would have crashed the endpoint with a 500 instead of a clean 422 rejection. Fixed by catching `SodiumException` in `LicenseSigning::verify()` and treating it as `false`, consistent with FR-006's fail-closed principle extended to this validation path.

A third apparent bug (one browser submission of a genuinely valid, correctly-signed key returned 422) was investigated and determined to be a stale-page artifact from actively editing/rebuilding frontend files while that specific browser tab remained open (Vite HMR mid-edit) — reproduced cleanly twice afterward in a fresh tab with no code changes, and independently corroborated by the automated test suite passing 7/7 on the exact same logic. Documented here rather than silently dropped, per this session's standing practice of calibrating findings honestly rather than either overstating or hiding an inconclusive one.

Two guarantees have no automated-test equivalent and no fully faithful manual re-creation was safely available in this environment, so each was verified by the closest honest substitute instead of skipped:
- **Cross-machine binding** (`quickstart.md` Section 3): no second physical/virtual machine was available in this session. Verified instead via `LicenseActivationTest::test_corrupted_activation_record_resolves_to_locked_not_activated`, which simulates the *result* of a cross-machine copy (a stored fingerprint hash that doesn't match the current machine) directly — the actual mechanism (`Hash::check` against the current machine's fingerprint) is identical regardless of *how* the mismatch arose.
- **Fully offline validation** (`quickstart.md` Section 4): deliberately not tested by disabling this shared dev machine's live networking mid-session, since doing so would disrupt the user's own active tooling/connections without their explicit go-ahead. Verified instead by code inspection: `LicenseSigning::verify()`, `MachineFingerprint::current()`, and `LicenseActivationService::isActivated()` contain zero outbound network calls (no `Http::`, no sockets, no external process communication) — the entire validation path is local cryptographic verification plus local file/DB reads.
- **Docker store-deployment survival** (`quickstart.md` Section 5): feature 016's own Docker store stack was not re-spun-up for this feature; the one new line (`/etc/machine-id:/etc/machine-id:ro`) was verified via `docker compose -f docker-compose.store.yml config --quiet`, confirming valid Compose syntax, rather than a full upgrade-cycle re-test of infrastructure feature 016 already exhaustively verified.

The local dev installation was left **activated** (not cleared back to locked) after verification, since this is a shared dev machine the user continues to use interactively — unlike a disposable test artifact, leaving it locked would have been actively disruptive to their own subsequent work.
