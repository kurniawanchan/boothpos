# Evidence — 077 (2026-10-27)

**Classification**: CONFIDENTIAL (security residual risk below; do not publish outside the team).

## Automated
- Android `./gradlew :app:testDebugUnitTest`: 335 tests, 0 failures (baseline 289; +46 new). `:app:assembleDebug` succeeds (APK built).
- Server new tests (safe flags): `PosSetupBrandingTest` 4, `TabNoteTest` 12, `PinRosterPhotoTest` 3 — all pass. Regression groups: tab suites 42 pass, PIN suites 28 pass, settings/features suites 15 pass.
- Full backend suite (safe flags, 2030 s): 2123 passed, 1 skipped, 1 failed — the failure is `ShiftReportParityTest` (pre-existing since 065, not touched by 077). No new failures.
- `docs/openapi-pos-mvp.yaml` parses; adds `/pos-setup/branding`, `PUT /tabs/{id}/note`, `Tab.notes`, roster `photo_url`.

## NOT verified (no device — adb absent)
quickstart steps 1–10 DEVICE parts: PIN logo, mode flip on a real tablet, mid-session dialog, header on every screen, two-column PIN, photo avatars, divider drag/persistence, note round-trip, price wrapping. All Compose UI in this feature is compile- and unit-tested only. T046 stays open.
- Step 9 (does the server receipt print order notes?) — not checked.
- The mode-flip symptom on a real tablet is still an inference from code (research R1).
- Web Sales detail shows `order.notes` per `TransactionItemsModal.vue:257` — read from code, not exercised in a browser.

## Deviations from the plan
- Mode dialog is state-driven (`PosStore.modeChanged`), not a `Sheet` (sheets are dismissible).
- Mode is also checked every 3rd heartbeat (~3 min) and on pull-to-refresh, in addition to error-driven checks (plan said no new polling; this reuses the existing heartbeat cadence, one `features` read per 3 beats).
- `GET /tabs` (summary) intentionally does not carry `notes`; detail does.
- Legacy single terminal config is read as the LIVE slot (unsuffixed keys); a tablet originally set up under DEMO self-heals via `validateSavedTerminal` and asks for setup again.
- PIN-screen probe now also reads `pos-setup/branding` every 10 s (to notice a mode flip while sitting on the PIN screen).

## Security recheck (T048) — against specs/073-.../security-findings.md
- `GET /pos-setup/branding`: exposes only `system_mode`, `store_name`, `store_logo_url` — values already visible to every logged-in role. Throttled 30/min. Not exempt from the activation lock (423). Low residual risk (information disclosure of store identity).
- Roster `photo_url`: **widens finding 3** (token-less roster exposure), which the 073 review recommended *narrowing*. A caller who can reach the server and knows an outlet id can now also fetch staff photo URLs (public-disk URLs). Mitigations: throttle 30/min, owner/admin already excluded from roster, photos optional. **Residual risk: MEDIUM, owner decision needed** — either accept (LAN-only install) or gate the roster behind the pairing secret the 073 review proposed.
- `PUT /tabs/{order}/note`: authenticated, outlet/mode-scoped through existing global scopes, role-gated (`floor` menu), 1000-char cap, audit log in-transaction without note content (tested). No new exposure found.
- No credential or PII was added to logs, tests or fixtures (test data uses fictional text only).
