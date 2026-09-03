# Implementation Plan: Ganti Bahasa Antarmuka (Indonesia/English)

**Branch**: `002-language-toggle` | **Date**: 2026-09-02 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/002-language-toggle/spec.md`

**Note**: This template is filled in by the `/speckit-plan` command. See `.specify/templates/plan-template.md` for the execution workflow.

## Summary

Pengguna yang sudah login bisa mengganti bahasa antarmuka BoothPOS antara
Bahasa Indonesia dan English lewat kontrol yang tersedia dari mana pun di
aplikasi; pilihan itu tersimpan sebagai preferensi permanen akunnya
(`users.language`) dan langsung berlaku ke SELURUH layar setelah login,
termasuk pesan galat server (422/409). Layar login sendiri dikecualikan
total dari toggle ini — selalu Bahasa Indonesia. Struk transaksi juga
dikecualikan — selalu Bahasa Indonesia karena itu dokumen yang dibaca
pelanggan, bukan operator. Pendekatan teknis: `vue-i18n` (Composition
API) di frontend untuk katalog string statis + reaktivitas ganti-locale
instan, dan infrastruktur lokalisasi bawaan Laravel (`lang/id/`,
`lang/en/`, `App::setLocale()` lewat middleware baru `SetLocaleFromUser`
yang membaca `users.language`) di backend untuk pesan validasi/bisnis.
Karena proyek ini TIDAK memiliki infrastruktur i18n sama sekali sebelum
fitur ini (lihat `research.md`), pekerjaan intinya adalah memigrasikan
seluruh string Indonesia literal yang sudah ada — di puluhan komponen
Vue dan belasan `FormRequest`/`Policy`/`Controller` — menjadi kunci
terjemahan, bukan sekadar menambah satu toggle kecil.

## Technical Context

**Language/Version**: PHP 8.4 (Laravel 13.x, existing) + JavaScript/Vue 3 (existing, Composition API)

**Primary Dependencies**: Laravel's built-in localization (`lang/`, `App::setLocale()`, `__()`) — no new backend package required. Frontend: **`vue-i18n` v9+ (new dependency, Vue 3 / Composition API build)**.

**Storage**: MySQL 8 (existing) — one additive column `users.language ENUM('id','en') NOT NULL DEFAULT 'en'`, no new tables.

**Testing**: `php artisan test` (PHPUnit, against real MySQL per Constitution Principle II) for backend locale middleware + endpoint; `qa-tests/` (Vitest + Testing Library) for the language switcher component and i18n string resolution; a real-browser pass per `quickstart.md` for the full-app visual/behavioral verification that automated tests cannot fully cover (SC-001's "<1 second, no reload" reactivity, and the "does every screen actually show translated text" question, which is a coverage question no single unit test can answer).

**Target Platform**: unchanged — single local machine per store, Laravel API + Vue SPA over `localhost`.

**Project Type**: Web application (existing Laravel API + Vue SPA monorepo, no new project/repo).

**Performance Goals**: Locale switch reflected across all mounted components in under 1 second with zero full-page reload (SC-001) — achievable natively via `vue-i18n`'s reactive `locale` ref, no custom perf work needed.

**Constraints**: Zero data loss in open forms when locale changes mid-edit (FR-011); receipt content (FR-009) and user-entered data (FR-012) MUST NOT pass through the translation layer at all, by construction (not by a runtime check) — see research.md Decision 5.

**Scale/Scope**: Full-app scope per FR-008 — every existing screen previously written in hardcoded Indonesian (dashboard, POS, all master data screens, reports, settings, activity log, the `001-user-store-settings` user/role screens) needs its static strings migrated into the two locale catalogs. Server-side, every literal-Indonesian message reachable by an authenticated request (FormRequest validation messages, Policy denial messages, controller-built business messages) needs the same treatment. Exact enumeration of files/strings belongs to `/speckit-tasks`, not this plan.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Principle | Status | Notes |
|---|---|---|
| I. Code Quality (DRY/SOLID) | **PASS** | One source of truth per side for locale strings (`lang/{id,en}/*.php`, `resources/js/locales/{id,en}.json`); one single middleware decides backend locale (no per-controller duplication); one single `t()`/`useI18n()` convention on the frontend, no ad hoc per-component translation logic. |
| II. Testing Standards | **PASS, with a scale caveat** | Every migrated screen needs at least one test/assertion that its translated strings render correctly in both locales, plus the mandatory real-browser pass (`quickstart.md`) — given the full-app scope (FR-008), this is a LARGE testing surface, tracked explicitly as scope in `research.md`/`tasks.md`, not skipped. |
| III. User Experience Consistency | **FAIL → justified exception, see Complexity Tracking** | The principle's literal text ("All UI copy ... written in Indonesian ... MUST NOT introduce English inconsistently") directly conflicts with this feature's explicit purpose (English as a first-class, even default, end-user display language). This is not an oversight — it is the feature's whole point, confirmed via the spec's clarification Q1 (full scope). See Complexity Tracking below for the justification and the recommended constitution amendment. |
| IV. Security | **PASS** | `PUT /auth/language` only ever mutates the caller's own record (no `{user}` route parameter, no cross-account write surface); accepted values are allow-listed via `Rule::in(['id','en'])`, not passed unvalidated into any file-path or locale-loading mechanism (preventing locale-string directory traversal). No client-supplied money/stock value is involved — out of scope for this feature entirely. |
| V. Performance & Optimization | **PASS** | `vue-i18n` is a cross-cutting dependency used by literally every screen, so it correctly belongs in the main bundle (not dynamically imported) — the "dynamic import for single-feature deps" rule applies to feature-specific libraries (e.g. `html2canvas`/`jsPDF` for receipts), not to an app-wide cross-cutting concern like this one. No new N+1 query risk — the locale read is a single scalar column already loaded on the authenticated `User` model via Sanctum, not an extra query. |

**Gate decision**: Proceed to Phase 0/1. Principle III's conflict is real
and is not silently waived — it is recorded below and a formal
constitution amendment is recommended before this feature merges to
`main` (see Complexity Tracking).

## Project Structure

### Documentation (this feature)

```text
specs/002-language-toggle/
├── plan.md              # This file (/speckit-plan command output)
├── research.md          # Phase 0 output (/speckit-plan command)
├── data-model.md        # Phase 1 output (/speckit-plan command)
├── quickstart.md        # Phase 1 output (/speckit-plan command)
├── contracts/
│   └── api.md           # Phase 1 output (/speckit-plan command)
└── tasks.md             # Phase 2 output (/speckit-tasks command - NOT created by /speckit-plan)
```

### Source Code (repository root)

Existing structure (Option 2: web application, Laravel backend + Vue
frontend in one repo) — no new top-level directories, only additions
inside the existing tree:

```text
app/
├── Http/
│   ├── Controllers/Api/AuthController.php      # MODIFIED: add PUT /auth/language handler, add `language` to /auth/me response
│   ├── Requests/UpdateLanguageRequest.php       # NEW: validates {language: id|en}
│   └── Middleware/SetLocaleFromUser.php         # NEW: App::setLocale() from $request->user()->language, registered on auth:sanctum group only
├── Http/Resources/UserResource.php              # MODIFIED: expose `language`
└── Models/User.php                              # MODIFIED: `language` fillable + cast

database/migrations/
└── 2026_10_10_000001_add_language_to_users_table.php   # NEW

lang/
├── id/                                          # NEW — every literal Indonesian message migrated here, key-by-key
│   ├── auth.php
│   ├── validation.php
│   └── ... (one file per domain, mirroring existing Controller/Policy/Request groupings)
└── en/                                          # NEW — English counterpart, same key structure

routes/api.php                                   # MODIFIED: register SetLocaleFromUser on auth:sanctum group; add PUT /auth/language route

resources/js/
├── locales/
│   ├── id.json                                  # NEW
│   └── en.json                                  # NEW
├── i18n.js                                      # NEW: vue-i18n instance setup (Composition API mode), registered in main.js
├── main.js                                      # MODIFIED: install i18n plugin
├── stores/auth.js                                # MODIFIED: read `language` from /auth/me, drive vue-i18n's `locale` ref, expose `setLanguage()` calling PUT /auth/language
├── components/layout/AppSidebar.vue              # MODIFIED (and every other screen component): hardcoded Indonesian strings → t('key') calls
└── components/receipt/ReceiptModal.vue           # EXPLICITLY NOT MODIFIED for translation (labels stay hardcoded Indonesian, per FR-009/research.md Decision 5) — only a code comment added documenting why

tests/Feature/
└── LanguagePreferenceTest.php                    # NEW: PUT /auth/language behavior, self-lockout-free (any role can change own), locale-dependent error messages, receipt exemption

qa-tests/
└── component/LanguageSwitcher.test.js            # NEW: switching locale re-renders visible text, persists via store, form values survive a mid-edit switch
```

**Structure Decision**: Extends the existing single-repo Laravel+Vue
structure used by `001-user-store-settings` — no new project, no new
repo, no restructuring. The only structurally new concept is the `lang/`
directory (didn't exist before this feature) and `resources/js/locales/`
+ `resources/js/i18n.js` on the frontend.

## Complexity Tracking

> **Fill ONLY if Constitution Check has violations that must be justified**

| Violation | Why Needed | Simpler Alternative Rejected Because |
|-----------|------------|---------------------------------------|
| Principle III ("All UI copy ... written in Indonesian ... MUST NOT introduce English inconsistently") is directly contradicted by this feature's core purpose. | This is an explicit, deliberate product requirement confirmed through the spec's clarification process (Q1: full-app scope, English as the default for new/existing accounts). The feature cannot be built at all without violating the letter of this principle — there is no version of "let users toggle to English" that keeps 100% of UI copy in Indonesian. | Not building the feature is not a "simpler alternative" available to this plan — the feature itself was explicitly requested and specified. The one available mitigation is scoping: Principle III's underlying *intent* (a consistent, predictable interaction pattern per Rationale — "reduce cognitive load of an already time-pressured job") is preserved by making the toggle deliberate and per-account (never a jarring, unpredictable mixed-language screen) rather than by refusing English altogether. **Recommended follow-up**: run `/speckit-constitution` to amend Principle III (MINOR bump) clarifying that the Indonesian-only rule governs the codebase's own source-of-truth strings, comments, and commit messages (unchanged, still absolute), while end-user-facing UI text is now bilingual by explicit user preference — this plan does not perform that amendment itself, since amending the constitution is a separate, deliberate governance action, not a side effect of one feature's plan. |
