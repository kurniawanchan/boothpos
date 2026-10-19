# Tasks: Android POS App Icon and Name

**Input**: `specs/060-android-app-icon-name/` (plan.md, spec.md, research.md, quickstart.md)

**Tests**: No automated test tasks. The spec and plan verify this feature by build (`assembleDebug`, existing unit suite) and by the device checklist in `quickstart.md`. No unit test can assert a launcher icon (research R7).

**Organization**: by user story. US1 = icon (P1), US2 = name (P1). The manifest references both resources, so its edit is in Foundational, after the files it points at exist.

**Paths**: `android-pos/app/src/main/` is written as `app/src/main/` below. Source icon folder: `/Users/chan/www/warkopwjm/design/AppIcons/android/`.

## Format: `[ID] [P?] [Story] Description`

---

## Phase 1: Setup (baselines)

- [X] T001 Baseline from `android-pos/`: `./gradlew --offline :app:assembleDebug` and `./gradlew --offline :app:testDebugUnitTest`. Record both results (expected: build success, 167 tests, 0 failures, per feature 059).

---

## Phase 2: Foundational (blocks both stories)

**Purpose**: the legacy icon files and the name resource, which the manifest refers to.

- [X] T002 Copy the five launcher PNGs from `/Users/chan/www/warkopwjm/design/AppIcons/android/mipmap-{mdpi,hdpi,xhdpi,xxhdpi,xxxhdpi}/ic_launcher.png` to `app/src/main/res/mipmap-{mdpi,hdpi,xhdpi,xxhdpi,xxxhdpi}/ic_launcher.png` (same density names; keep the files byte-identical).
- [X] T003 [P] Create `app/src/main/res/values/strings.xml` with `<string name="app_name">Warkop WJM POS</string>` (UTF-8 XML declaration; no other strings).
- [X] T004 Edit `app/src/main/AndroidManifest.xml` on the `<application>` element: `android:icon="@mipmap/ic_launcher"`, `android:roundIcon="@mipmap/ic_launcher"` (plan: `roundIcon` decision; pre-Android-8 devices need the PNG), `android:label="@string/app_name"`. Remove the `@android:drawable/sym_def_app_icon` placeholders and the `"Warkop POS"` literal. Depends on T002 and T003.

**Checkpoint**: the manifest references only resources that exist; `./gradlew --offline :app:assembleDebug` succeeds with the legacy icon and the new name.

---

## Phase 3: User Story 1 - Recognise the POS on the tablet by its new icon (Priority: P1) 🎯 MVP

**Goal**: launcher shows the approved Warkop icon on the adaptive mask (Android 8+) and as the legacy PNG on older launchers.

**Independent Test**: quickstart steps 2, 4, 5.

- [X] T005 [P] [US1] Copy `/Users/chan/www/warkopwjm/design/AppIcons/android/adaptive-foreground.png` to `app/src/main/res/drawable-nodpi/ic_launcher_foreground.png` (1024×1024; `drawable-nodpi` stops density rescaling, research R3).
- [X] T006 [P] [US1] Create `app/src/main/res/values/colors.xml` with `<color name="ic_launcher_background">#FFFFFF</color>` (research R4; the spec's assumption).
- [X] T007 [US1] Create `app/src/main/res/mipmap-anydpi-v26/ic_launcher.xml`: `<adaptive-icon>` with `<background android:drawable="@color/ic_launcher_background"/>` and `<foreground android:drawable="@drawable/ic_launcher_foreground"/>`. Depends on T005 and T006.
- [X] T008 [US1] Run `./gradlew --offline :app:assembleDebug` from `android-pos/`; confirm the adaptive XML and the drawable resolve (no `AAPT` errors). Record the result. Depends on T004 and T007.
- [ ] T009 [US1] Device check (manual): quickstart steps 2, 4 and 5 on the pilot tablet. The logo is not clipped on a circle and a rounded-square mask; the icon is sharp; the background is white. Record evidence (screenshot per mask). If the logo is clipped, the foreground needs padding, which is a design re-export, not a code change. Depends on T008.

**Checkpoint**: US1 independently checkable on the tablet.

---

## Phase 4: User Story 2 - See the name "Warkop WJM POS" everywhere the app is named (Priority: P1)

**Goal**: the launcher label and Settings > Apps show exactly "Warkop WJM POS".

**Independent Test**: quickstart steps 3 and 6.

- [X] T010 [US2] Verify the label resolves: `grep -n 'android:label' app/src/main/AndroidManifest.xml` shows `@string/app_name`, and `app/src/main/res/values/strings.xml` holds exactly `Warkop WJM POS` (character for character, including the space and capitalisation). Depends on T003 and T004.
- [ ] T011 [US2] Device check (manual): quickstart step 3 — the launcher label and Settings > Apps read exactly "Warkop WJM POS". Record evidence. Depends on T008.
- [ ] T012 [US2] Device check (manual): quickstart step 6 — install over the previous build without uninstalling; stored login, paired terminal and queued print jobs still work (spec SC-003). If re-pairing is requested, record it as a failure, do not work around it. Depends on T008.

**Checkpoint**: US2 independently checkable on the tablet.

---

## Final Phase: Polish & cross-cutting concerns

- [X] T013 Full Android unit suite: `./gradlew --offline :app:testDebugUnitTest` from `android-pos/`. Record the result (must match T001; no regression).
- [X] T014 Scope check (spec FR-004, FR-005, FR-006): `git status` shows changes only under `android-pos/app/src/main/` (AndroidManifest and `res/`) and `specs/060-android-app-icon-name/`. No change to `applicationId`, `versionCode`, signing, `android/`, `app/` (Laravel), `routes/`, or `docs/openapi-pos-mvp.yaml`. Record the result.
- [X] T015 [P] Confirm `design/AppIcons/` is not referenced from the build (no path to `/Users/chan/...` in any Gradle or manifest file) and that every new PNG is a byte-identical copy of its pack source (`cmp`). Record the result.
- [ ] T016 Walk through `quickstart.md` steps 1 and 7 (build passes; the web app's "Powered by BoothPOS" is unchanged). Record evidence.

---

## Dependencies & Execution Order

- **Setup (T001)** first.
- **Foundational (T002–T004)**: T002 and T003 in any order (T003 is [P]); T004 after both.
- **US1 (T005–T009)**: T005 and T006 in parallel; T007 after both; T008 after T004 and T007; T009 after T008.
- **US2 (T010–T012)**: T010 after T003 and T004; T011 and T012 after T008 (installed build). US2 does not need US1's adaptive XML to verify the label, but the device check needs an installed build from T008.
- **Polish (T013–T016)** last. T015 [P] can run alongside T013.

### Parallel opportunities

- T003 with T002 (different files).
- T005 with T006 (different files).
- US1 and US2 device checks (T009, T011, T012) on the same tablet, in one session after T008.

## Implementation Strategy

- **MVP**: Setup + Foundational + US1 — the approved icon on the launcher. Both stories share the manifest edit, so US1 is not shippable without the Foundational label change in the same build.
- **Increment 2**: US2 label check and the update-in-place check, in the same device session.
- **Final**: Polish, scope check, and evidence recording.

## Task count

- Total: **16 tasks** (T001–T016).
- Setup: 1 · Foundational: 3 · US1: 5 · US2: 3 · Polish: 4.
- Parallel-marked: T003, T005, T006, T015.
- Format check: every task has a checkbox, a sequential ID, a `[USn]` label only in story phases, and a file path or named command.
