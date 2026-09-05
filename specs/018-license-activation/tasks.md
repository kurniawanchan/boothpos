# Tasks: License Activation Gate

**Input**: Design documents from `/specs/018-license-activation/`

**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/api.md, quickstart.md

**Tests**: Included where automatable — Constitution Principle II requires backend changes be accompanied by `tests/Feature/` tests. Two guarantees (cross-machine binding, fully-offline validation) have no automated-test equivalent per plan.md's Technical Context and are verified manually via `quickstart.md` instead, mirroring feature 016's precedent.

**Organization**: Tasks are grouped by user story (spec.md P1/P1/P2).

## Phase 1: Setup

- [x] T001 Create `lang/id/license.php` and `lang/en/license.php` with an empty `return [];` shape, ready for keys added by later tasks (existing per-feature lang-file convention)
- [x] T002 [P] Generate a dev/test Ed25519 keypair per `quickstart.md` Section 0 (`sodium_crypto_sign_keypair()`) — record the public key for T005 and keep the secret key only in local shell env, never committed

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: The core signing/fingerprinting/activation-check machinery every user story depends on.

**⚠️ CRITICAL**: No user story can be implemented until this phase is complete.

- [x] T003 Create migration `database/migrations/2026_10_17_000001_create_license_activations_table.php` per data-model.md (`license_id`, `issued_to`, `machine_fingerprint_hash`, `activated_at`, no soft delete, no `HasDataMode` — research.md R7)
- [x] T004 [P] Create `app/Models/LicenseActivation.php` (fillable per data-model.md, `activated_at` cast)
- [x] T005 [P] Create `app/Support/LicenseSigning.php` — the public key constant (from T002) plus `verify(string $payloadB64, string $signatureB64): bool` using `sodium_crypto_sign_verify_detached()`, and a `decodePayload()` helper (research.md R1/R2)
- [x] T006 [P] Create `app/Support/MachineFingerprint.php` — `current(): string` returning a sha256 hash of `/etc/machine-id` (with `/var/lib/dbus/machine-id` fallback) on Linux, or `IOPlatformUUID` via `ioreg -rd1 -c IOPlatformExpertDevice` on macOS (research.md R3); throws/returns a clearly-invalid sentinel on an unsupported OS rather than silently succeeding
- [x] T007 Create `app/Services/LicenseActivationService.php` with `isActivated(): bool` (fail-closed per FR-006/research.md R7 — true only if a `license_activations` row exists AND `Hash::check(MachineFingerprint::current(), $row->machine_fingerprint_hash)` succeeds; any exception or missing row resolves false), `verifyAndDecode(string $licenseKey): array` (parses `base64(json).base64(sig)`, verifies via `LicenseSigning`, throws on any failure), and `activate(string $licenseKey): LicenseActivation` (verifies, then upserts the single row bound to the current fingerprint — data-model.md's "always (re)binds" design) — depends on T004-T006
- [x] T008 Create `app/Http/Middleware/EnsureInstallationIsActivated.php` — returns `423 Locked` (`{"message": "...", "activated": false}`) for every request unless `LicenseActivationService::isActivated()` is true or the request targets `/license/status`/`/license/activate` (research.md R4) — depends on T007
- [x] T009 Register the middleware globally on the `api` middleware group in `bootstrap/app.php`
- [x] T010 Create `app/Http/Requests/ActivateLicenseRequest.php` (`license_key` required string)
- [x] T011 Create `app/Http/Controllers/Api/LicenseController.php` with `status()` (returns `{"activated": bool}`) and `activate()` (delegates to `LicenseActivationService::activate()`, catches verification failure into the generic `422` per contracts/api.md — never a more specific reason) — depends on T007, T010
- [x] T012 Register `GET /license/status` and `POST /license/activate` in `routes/api.php`, outside the `auth:sanctum` group (these must be reachable while locked and unauthenticated)
- [x] T013 Add `license_key_invalid`/`installation_locked` (and any other needed) keys to `lang/id/license.php`/`lang/en/license.php` (T001)
- [x] T014 Write `tests/Feature/LicenseActivationTest.php` covering: a valid key activates and `GET /license/status` reflects it; an invalid/malformed key is rejected `422` with no state change; every other route (including `/auth/login`) returns `423` while unactivated; a corrupted/missing `license_activations` row resolves to locked, never activated (fail-closed, FR-006)

**Checkpoint**: The activation mechanism works end-to-end via the API — ready for frontend + enforcement + vendor-tooling work.

---

## Phase 3: User Story 1 - Enter a license key to unlock the installation (Priority: P1) 🎯 MVP

**Goal**: An operator on the activation page can submit a valid key and immediately reach the normal application.

**Independent Test**: `quickstart.md` Section 2.

- [x] T015 [P] [US1] Create `resources/js/api/license.js` (`getLicenseStatus()`, `activateLicense(key)`)
- [x] T016 [P] [US1] Create `resources/js/stores/license.js` (Pinia) — a cached `activated`/`ready` flag, `restore()` (calls `getLicenseStatus()` once, mirrors `useAuthStore`'s existing shape) and `refresh()` (re-checks, called after a successful activation)
- [x] T017 [US1] Create `resources/js/views/LicenseLockView.vue` — the activation page: a key input, submit button, and a clear (but generic per FR-003) error message on rejection — depends on T015-T016
- [x] T018 [US1] Add the `/activate` route to `resources/js/router/index.js` (`meta: { public: true }`-equivalent, exempt from the license check itself), and call the license store's `restore()` in `main.js`'s boot sequence alongside the existing `auth.restore()` call
- [x] T019 [US1] Manually verify `quickstart.md` Section 2 (generate a key, submit it on the activation page, confirm the app unlocks; submit garbage, confirm generic rejection) in a real running browser + API

**Checkpoint**: A fresh installation can be unlocked end-to-end via the real UI.

---

## Phase 4: User Story 2 - An unlicensed installation cannot be used for anything (Priority: P1)

**Goal**: Every URL — including `/login` and direct API hits — shows the lock page/gets `423` while unactivated; nothing bypasses it.

**Independent Test**: `quickstart.md` Section 1.

- [x] T020 [US2] Extend the `beforeEach` guard in `resources/js/router/index.js` with a license-status check placed *ahead of* the existing `auth.isAuthenticated` check — if the license store's cached `activated` is false, redirect every route (including `/login`) to `/activate`, mirroring exactly how `to.meta.public` already short-circuits before the auth check (research.md R4) — depends on T016, T018
- [x] T021 [US2] Manually verify `quickstart.md` Section 1 — visit `/`, `/login`, and a deep link directly on an unactivated installation (confirm the lock page every time), and `curl` `/api/v1/auth/login` directly (confirm `423`, not a normal login attempt)

**Checkpoint**: Stories 1 and 2 together deliver the MVP — an installation can be unlocked, and nothing works until it is.

---

## Phase 5: User Story 3 - Vendor manually sends a license key after payment (Priority: P2)

**Goal**: The vendor can generate and email a signed key to a specific paying client from the command line.

**Independent Test**: `quickstart.md`'s license-generation step + `contracts/api.md`'s Vendor CLI section.

- [x] T022 [P] [US3] Create `app/Mail/LicenseKeyMail.php` + `resources/views/emails/license-key.blade.php`, mirroring `app/Mail/CompanyActivationMail.php`'s structure (accepts the recipient's issued-to name + the generated key string)
- [x] T023 [US3] Create `app/Console/Commands/GenerateLicense.php` (`php artisan license:generate {email} {issued_to}`) — reads `LICENSE_SIGNING_PRIVATE_KEY` from env (fails loudly with a clear error if absent — research.md R5's only real safeguard), builds+signs the payload (research.md R2), sends `LicenseKeyMail` (logging to `storage/logs/laravel.log` if `mail.default === 'log'`, same convention as every other transactional email in this app), and always prints the generated key to the console regardless of email outcome — depends on T005, T022
- [x] T024 [US3] Manually verify: run `license:generate` with `LICENSE_SIGNING_PRIVATE_KEY` set against a test email address, confirm the email is dispatched (or logged if mail is unconfigured), and confirm the printed key successfully activates a fresh installation (User Story 1's flow)

**Checkpoint**: All three user stories independently verified.

---

## Phase 6: Polish & Cross-Cutting Concerns

- [x] T025 Add the `/etc/machine-id:/etc/machine-id:ro` bind mount to `docker-compose.store.yml`'s `app` service (research.md R6 — a small, backward-compatible addition to the feature 016 artifact, not a redesign)
- [x] T026 Update `docs/openapi-pos-mvp.yaml` with `GET /license/status` and `POST /license/activate` (Constitution's Documentation & Change Discipline rule)
- [x] T027 Run the full `php artisan test` suite, confirming no regressions across the whole app
- [x] T028 Full end-to-end run-through of all five `quickstart.md` sections in one sitting — including the two manual-only guarantees (Section 3 cross-machine binding, Section 4 fully-offline validation, Section 5 Docker upgrade survival) — then update `spec.md`'s Status line to reflect completion

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: No dependencies.
- **Foundational (Phase 2)**: Depends on Setup — BLOCKS all user stories (the signing/fingerprint/activation-check machinery every story needs).
- **User Story 1 (Phase 3)**: Depends on Foundational. Delivers the "can be unlocked" half of the MVP.
- **User Story 2 (Phase 4)**: Depends on Foundational AND User Story 1 (T020/T021 build on the license store T016 and the `/activate` route T018 that US1 creates) — not independent of US1 despite both being P1, since "enforce the lock everywhere" needs an `/activate` destination to redirect to.
- **User Story 3 (Phase 5)**: Depends on Foundational only (specifically `LicenseSigning`, T005) — independently workable in parallel with User Stories 1–2 once Phase 2 is done, since generating/sending a key doesn't require the frontend lock page to exist.
- **Polish (Phase 6)**: Depends on all three user stories being complete.

### Parallel Opportunities

- T004/T005/T006 (model, signing helper, fingerprint helper) can run in parallel — different files, no interdependency.
- T015/T016 (frontend API client, Pinia store) can run in parallel before T017 needs both.
- User Story 3 (Phase 5) can be staffed entirely in parallel with User Stories 1–2 (Phases 3–4) once Phase 2 (Foundational) is done, since it has no dependency on either.

---

## Implementation Strategy

### MVP First (User Stories 1 + 2 together)

1. Complete Phase 1 (Setup) + Phase 2 (Foundational).
2. Complete Phase 3 (US1) — an installation can be unlocked.
3. Complete Phase 4 (US2) — nothing works until it is. **This is the MVP**: US1 without US2 would leave the lock merely cosmetic (bypassable by navigating around the activation page), so the smallest useful increment is both P1 stories together, exactly as in feature 017's US1+US2 pairing.
4. **STOP and VALIDATE** via `quickstart.md` Sections 1–2.

### Incremental Delivery

1. Setup + Foundational → the activation mechanism works via the API alone.
2. US1 + US2 together → MVP: an installation can be unlocked, and is otherwise fully locked.
3. US3 → vendor tooling to actually issue keys, independently addable at any point after Foundational.
4. Polish → Docker path updated, OpenAPI spec updated, full regression pass, full quickstart sign-off (including the two manual-only, no-automated-equivalent guarantees).
