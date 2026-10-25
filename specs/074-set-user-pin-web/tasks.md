---
description: "Task list for feature 074 — Set user PIN from the web Users screen"
---

# Tasks: Set User PIN from the Web Users Screen

**Classification**: INTERNAL

**Input**: Design documents from `/specs/074-set-user-pin-web/`

**Prerequisites**: plan.md, spec.md, research.md (R1–R11), data-model.md, contracts/user-pin-api.md, quickstart.md

**Tests**: INCLUDED. The constitution (Principle II) requires `tests/Feature/` coverage for backend changes, `qa-tests/` for frontend, and a real-browser check of a changed screen.

**Test-run rule (CLAUDE.md, destructive otherwise)**: inside the container use
`docker compose exec -e APP_ENV=testing -e DB_DATABASE=warkop_wjm_test -e CACHE_STORE=array -e SESSION_DRIVER=array app php artisan test …` — **all `-e` flags before the service name `app`**. Never run the suite against `warkop_wjm`.

**Organization**: grouped by user story. The set-PIN endpoint serves US1 and US3, so it is built once in Phase 2.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: can run in parallel (different files, no dependency on an incomplete task)
- **[Story]**: US1 / US2 / US3 from spec.md

## Path Conventions

- Server: `app/`, `routes/`, `lang/{id,en}/`, `tests/Feature/`, `docs/`
- Web: `resources/js/` (views, components, api, locales); tests in `qa-tests/component/`

---

## Phase 1: Setup

- [X] T001 Run the baseline backend tests for the areas this feature touches (`PinLoginTest`, `PinLoginTerminalTest`, `UserTest`, `UserOutletAssignmentTest`) with the container flags above; record pass/fail counts in `specs/074-set-user-pin-web/evidence-baseline.md`
  - **Done — note:** 58 passed (225 assertions). See `evidence-baseline.md`.
- [X] T002 [P] Run `npm test` and record the baseline result (count, failures) in the same evidence file
  - **Done — note:** Baseline is FLAKY (run 1: 2 failed, run 2: 1 failed — `UsersView … galat memuat … coba lagi`, expected 2 calls got 3). See `evidence-baseline.md`.

---

## Phase 2: Foundational (blocks all user stories)

**Purpose**: the single PIN write path, its endpoint and the client API calls.

- [X] T003 Add `adminSet(User $actor, User $target, string $pin, string $source): bool` and `suggest(): string` to `app/Services/PinAuthService.php`. `adminSet`: one transaction, `lockForUpdate` on the target, `assertStrong`, set `pin_hash`, clear `pin_failed_attempts`/`pin_locked_until`, set `pin_changed_at`, `revokePinTokens($target)`, log `pin_set` (target had no PIN) or `pin_replaced` (had one) with `new_values = ['source' => …]` and a description that names actor and target and says the source is client-reported — never the PIN or hash (research R1, R4, R5); return whether a PIN already existed. `suggest`: `random_int` digits, retried until `assertStrong` accepts (research R2)
  - **Done — note:** `adminSet` and `suggest` added to `PinAuthService`; the weak-PIN rule was extracted into one `isWeak()` that both `assertStrong` (refusal) and `suggest` (generator) use. Audit entity type is lowercase `user`, matching the other PIN log entries in that service.
- [X] T004 [P] Create `app/Http/Requests/SetUserPinRequest.php`: `authorize()` = `isOwnerOrAdmin()` (research R3), rules `pin` required string `digits:6`, `source` nullable `in:typed,generated`
- [X] T005 Add `setUserPin(SetUserPinRequest, User)` and `suggest(Request)` to `app/Http/Controllers/Api/PinAuthController.php` (thin; reuse the existing `guarded()` wrapper so `PinRejectedException` becomes `{message, code}`; `suggest` aborts 403 unless `isOwnerOrAdmin()`; `setUserPin` returns `new UserResource($user->fresh(['role','outlets:id']))`); register `PUT /users/{user}/pin` and `POST /pin-suggestions` with `throttle:30,1` inside the `auth:sanctum` group in `routes/api.php`, with an Indonesian comment citing spec FR-003/FR-006
- [X] T006 [P] Add any new Indonesian and English messages (e.g. success text) in `lang/id/auth.php` and `lang/en/auth.php`
  - **Done — note:** No new message was needed: the 403 reuses `restaurant.forbidden` and the weak-PIN 422 reuses `auth.pin_too_weak`. No lang file changed.
- [X] T007 [P] Add `setUserPin(id, pin, source)` (`PUT /users/{id}/pin`) and `suggestPin()` (`POST /pin-suggestions`) to `resources/js/api/users.js`, with a short comment that the PIN is never kept after use

**Checkpoint**: an admin can set a PIN and request a suggestion through the API; stories add tests and UI.

---

## Phase 3: User Story 1 — Set a PIN for a user who has none (Priority: P1) 🎯 MVP

**Goal**: from the Users screen an owner/admin types or generates a PIN for a user and the user can sign in.

**Independent Test**: as admin set a PIN for a cashier with none (typed, then generated), then sign in with it.

### Tests for User Story 1

- [X] T008 [P] [US1] `tests/Feature/UserPinAdminTest.php`: owner/admin sets a PIN for a user with none → 200, response has `has_pin: true` and no PIN/hash anywhere in the body; the user then signs in through `POST /auth/pin-sessions` (create an outlet, terminal and assignment as `PinLoginTerminalTest` does) with that PIN
  - **Done — note:** All backend tests for this feature are in `tests/Feature/UserPinAdminTest.php` (16 tests incl. US2/US3: T008–T011, T016, T020).
- [X] T009 [P] [US1] In `tests/Feature/UserPinAdminTest.php`: weak PINs (`111111`, `123456`, `654321`), non-6-digit (`12345`, `12345a`, empty) and a bad `source` → 422 with nothing changed (`pin_hash` stays null); a user whose role has the Users menu but not Settings → 403 on both routes; unknown user → 404; unauthenticated → 401
- [X] T010 [P] [US1] In `tests/Feature/UserPinAdminTest.php`: `POST /pin-suggestions` returns a 6-digit PIN that passes the weak-PIN rule on 300 consecutive calls, is admin-only (403 otherwise), stores nothing (no user changed, no activity-log row)
  - **Done — note:** The 300-call loop failed with 429 against the 30/min throttle (my test, not the feature). Now: 20 HTTP calls, plus `test_the_generator_never_returns_a_weak_pin_over_many_draws` (3,000 direct draws of `suggest()`, >2,900 distinct) and `test_suggestions_are_throttled`.
- [X] T011 [P] [US1] In `tests/Feature/UserPinAdminTest.php`: a successful set writes exactly one `pin_set` activity entry (actor, target, source) whose description and `new_values` do not contain the PIN or hash; a refused (422) set writes none
- [X] T012 [P] [US1] `qa-tests/component/SetPinModal.test.js`: PIN field masked and limited to 6 digits; Save disabled until 6 digits; typed PIN → `setUserPin(id, pin, 'typed')`; "Generate" calls `suggestPin`, shows the PIN in the dialog, Save sends `'generated'`; after a successful save a generated PIN stays visible until close and is gone after close/reopen; 422 message shown inline and the dialog stays open
  - **Done — note:** `qa-tests/component/SetPinModal.test.js` — 9 tests, passing.

### Implementation for User Story 1

- [X] T013 [US1] Create `resources/js/components/users/SetPinModal.vue` per research R8 (props `open`, `user`; emits `close`, `saved`; `BaseModal`/`BaseInput`/`BaseButton`, design tokens only, no hex; clears typed and generated PIN state on close and after a typed save)
  - **Done — note:** `resources/js/components/users/SetPinModal.vue`. `source` sent to the server is 'generated' only when the saved PIN equals the server-generated one; editing it makes it 'typed'.
- [X] T014 [US1] `resources/js/views/UsersView.vue`: row action "Atur PIN" / "Reset PIN" (label by `has_pin`) that opens `SetPinModal`; shown only when `auth.canAccessMenu('settings')` (cosmetic; the server enforces); on `saved` replace the row with the returned user and show a toast
- [X] T015 [US1] Add all `users.pin_*` keys (column title, statuses, filter labels, action labels, modal texts, generate/copy hints, errors) to `resources/js/locales/id.json` and the English mirror in `resources/js/locales/en.json`

**Checkpoint**: US1 works on its own through the UI and API.

---

## Phase 4: User Story 2 — See who has a PIN (Priority: P1)

**Goal**: the Users list shows each user's PIN status and can be filtered to "No PIN".

**Independent Test**: with users that have, lack and are locked on a PIN, the list shows the right status and the filter narrows correctly.

### Tests for User Story 2

- [X] T016 [P] [US2] In `tests/Feature/UserPinAdminTest.php`: `GET /users` returns `has_pin` and `pin_locked` for every user (locked only while `pin_locked_until` is in the future); `has_pin=1` and `has_pin=0` filter correctly; omitted returns all; no `pin_hash`/`pin_failed_attempts` key in any user payload
- [X] T017 [P] [US2] Extend `qa-tests/component/UsersView.test.js` (add `setUserPin`/`suggestPin` to its `api/users` mock): the PIN column shows "Ada PIN", "Belum ada PIN" or "Terkunci" per row; choosing the PIN filter calls `listUsers` with `has_pin`; reset filters clears it; no PIN value is rendered
  - **Done — note:** Added a `describe('UsersView — PIN (074)')` block (5 tests) to `qa-tests/component/UsersView.test.js`, plus `setUserPin`/`suggestPin` in its `api/users` mock. The PIN filter is a custom combobox with no accessible name, so the test clicks it by its visible label.

### Implementation for User Story 2

- [X] T018 [US2] `app/Http/Resources/UserResource.php`: add `has_pin` and `pin_locked` (derived from the loaded row, no extra query); `app/Http/Controllers/Api/UserController.php` `index()`: `has_pin` filter via `whereNotNull`/`whereNull('pin_hash')`, same style as the existing `is_active` filter
- [X] T019 [US2] `resources/js/views/UsersView.vue`: add the `pin` column with `StatusPill` (success / neutral / danger), a `BaseSelect` "PIN: semua / ada PIN / belum ada PIN" in the `FilterBar`, include it in `filtersActive` and `resetFilters`
  - **Done — note:** Column, `has_pin` filter select, reset clears it.

**Checkpoint**: US1+US2 together let an admin find who needs a PIN and give it.

---

## Phase 5: User Story 3 — Change or reset an existing PIN (Priority: P2)

**Goal**: replacing a PIN refuses the old one, clears a lock and ends the user's tablet sessions.

**Independent Test**: for a locked user with an active tablet session, an admin sets a new PIN: old PIN refused, new accepted, lock gone, session ended.

### Tests for User Story 3

- [X] T020 [P] [US3] In `tests/Feature/UserPinAdminTest.php`: replacing an existing PIN → old PIN refused and new accepted at `/auth/pin-sessions`; a locked user (5 wrong PINs) can sign in at once after the set; `pin_failed_attempts` is 0; the user's `pos-pin` tokens are deleted while a password token for the same user survives; `pin_replaced` is logged (not `pin_set`); password, role and outlet assignment unchanged; an admin can set their own PIN; two sequential sets leave the last PIN as the only working one
- [X] T021 [P] [US3] Extend `qa-tests/component/UsersView.test.js`: the action reads "Reset PIN" for a user with a PIN and "Atur PIN" for one without; after a successful save of a locked user the row shows "Ada PIN" and no longer "Terkunci" (row replaced from the response)

### Implementation for User Story 3

- [X] T022 [US3] Verify against T020 that `PinAuthService::adminSet` (T003) covers lock clearing, token revocation and the `pin_set`/`pin_replaced` branch; fix `adminSet` or the `UsersView` row update (T014) wherever a test fails — no new code is expected beyond those fixes
  - **Done — note:** No fix was needed: all T020 tests passed on the first run against T003, and the row-update test (T021) passed against T014.

**Checkpoint**: all three stories work independently.

---

## Phase 6: Polish & Cross-Cutting

- [X] T023 [P] Update `docs/openapi-pos-mvp.yaml` in the same change as the routes: `PUT /users/{user}/pin`, `POST /pin-suggestions`, `has_pin`/`pin_locked` on the user schema, `has_pin` query on `GET /users` (parse the YAML after editing)
  - **Done — note:** Parsed with PyYAML after editing (an unquoted `: ` in one description broke it first and was fixed). Not validated with an OpenAPI linter.
- [X] T024 [P] Update `docs/RUNBOOK.md` §13.1 (how an admin gives a cashier a PIN from the Users screen; the old API-only way becomes a footnote), add a dated note to `docs/PRD-POS-Event-Multivendor.md`, and a dated 074 note under the Terminals/PIN bullets in `CLAUDE.md`
  - **Done — note:** RUNBOOK §13.1 (new step 3, steps renumbered), PRD dated note, CLAUDE.md note.
- [X] T025 Run the full backend suite (container flags), `npm test` and `npm run build`; record counts and any failures **verbatim** in `specs/074-set-user-pin-web/evidence-final.md`
  - **Done — note:** Backend 2104 passed / 1 failed / 1 skipped (the failure is the pre-existing `ShiftReportParityTest`); frontend 1748 passed / 1 failed (the flaky `UsersView … galat memuat` test, same as the baseline) / 2 skipped; build OK. Details in `evidence-final.md`.
- [ ] T026 Real-browser verification per `quickstart.md` (Constitution II): run the SPA against the real API, exercise set/generate/reset/filter on the Users screen, check the console for errors, and record what was observed. The browser MCP tools failed to connect in the planning session (research R11): if no browser tool is available, say so in the evidence file and **leave this task open** — never infer it from unit tests
- [X] T027 Security re-check: after the feature tests, grep the test log, `storage/logs` and the activity-log rows written by the tests for the PINs used; confirm none appear. Confirm both routes refuse non-owner/admin and that no response other than `/pin-suggestions` contains a PIN. Note the accepted points in the evidence file: the admin sees the PIN at the moment it is set, and `source` in the audit entry is client-reported
  - **Done — note:** No PIN in any response, log row or activity entry produced by the feature. FOUND a real leak class (PIN arguments printed in stack traces) with a failing-then-passing test and fixed it with `#[\SensitiveParameter]` in `PinAuthService`; the same leak remains in the older `OverrideGate::issue` (not fixed, reported).

---

## Dependencies & Execution Order

- **Phase 1** → **Phase 2** → stories. Phase 2 blocks everything (T003 → T005; T004 before T005; T007 before the frontend tasks).
- **US1** needs Phase 2. **US2** needs Phase 2 (its backend half T016/T018 is independent of US1). **US3** needs US1 (the modal and the row update) and Phase 2.
- Same-file serialisation: `tests/Feature/UserPinAdminTest.php` (T008–T011, T016, T020), `resources/js/views/UsersView.vue` (T014, T019), `qa-tests/component/UsersView.test.js` (T017, T021), `resources/js/locales/{id,en}.json` (T015 owns all keys; later tasks only add if a key is missing).
- **Phase 6** last; T026 needs the built UI.

## Parallel Opportunities

- Phase 1: T001 ∥ T002. Phase 2: T004 ∥ T006 ∥ T007 (after T003 starts).
- US1 tests T008–T012 are written together before T013–T015 (T008–T011 share one file, so author them in one pass).
- US1 frontend (T013) ∥ US2 backend (T018): different files.
- T023 ∥ T024.

## Implementation Strategy

1. **MVP = Phases 1–3 (US1)**: an admin can give a cashier a PIN from the UI. Stop and run the quickstart browser steps 2–5 if a browser tool exists.
2. Add **US2** (status + filter) so admins can find who lacks a PIN, then **US3** verification.
3. Polish, contract docs and full-suite evidence last. Nothing is "done" until T025 has evidence from this work session and T026 is either run or explicitly left open (Constitution II).

## Notes

- No second PIN writer: all writes go through `PinAuthService` (Constitution I). Do not touch the existing clear-only `POST /users/{user}/pin-reset` or the own-PIN `POST /auth/pin-changes`.
- Comments and UI copy are Indonesian, explaining *why* and citing the spec/research item.
- Resolved decisions: admin may type or generate (spec Q1, option C); owner/admin can use the action for any user including themselves; no password re-entry; PINs need not be unique.
