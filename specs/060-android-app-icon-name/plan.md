# Implementation Plan: Android POS App Icon and Name

**Branch**: `060-android-app-icon-name` (not created; the repository is on `054-android-pin-layout-fixes`, and the setup script reports this) | **Date**: 2026-10-18 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `specs/060-android-app-icon-name/spec.md`

## Summary

Replace the Android POS app's placeholder launcher icon and hard-coded label with the approved Warkop artwork and the name "Warkop WJM POS". The pack's launcher PNGs go into the `mipmap-*` folders, the 1024 px foreground goes into `drawable-nodpi` with an adaptive-icon XML on a white background (`#FFFFFF`, the pack's suggested default), and the label moves to a `strings.xml` resource. No package name, signing, or data change, so an existing install updates in place. Resource-only change; no code path changes and no server change.

## Technical Context

**Language/Version**: Kotlin (Android app module `android-pos/app`), Android Gradle Plugin as already configured in the repository; no version change.

**Primary Dependencies**: none added. Android resource system only (mipmap, drawable, values, mipmap-anydpi-v26 adaptive XML).

**Storage**: none. (No data model; `data-model.md` intentionally not produced.)

**Testing**: `./gradlew --offline :app:assembleDebug` (build proves the resources and manifest link) and `./gradlew --offline :app:testDebugUnitTest` (no regression); device check per `quickstart.md`.

**Target Platform**: Android tablet (Warkop WJM 3 pilot), launcher with adaptive and legacy icon support.

**Project Type**: mobile app (resource change inside an existing Android module).

**Performance Goals**: none; a static icon. The 1024 px foreground is loaded once by the launcher.

**Constraints**: package name, signing, and stored data must not change (spec FR-004, SC-003). The `android/` WebView shell and iOS or store assets are out of scope (FR-006).

**Scale/Scope**: 5 density launcher PNGs, 1 adaptive foreground, 1 background colour, 1 string, 1 manifest edit, and 2 adaptive XML files.

## Constitution Check

*Gate evaluated before Phase 0 and again after Phase 1.*

| Principle | Assessment | Status |
|-----------|------------|--------|
| I. Code Quality | Name lives in one resource (`app_name`) instead of a manifest literal. Icon files live in the standard resource folders. No new code. | PASS |
| II. Testing | No unit test can assert a launcher icon; coverage is the build (resource links) plus the device checklist in `quickstart.md`. Stated as the verification, not assumed. | PASS (device check pending) |
| III. UX Consistency | The launcher name and icon match the approved pack; the label is the single brand string. | PASS |
| IV. Security | No secrets, network, or permission change. | PASS |
| V. Performance | Static resources only; the foreground is loaded once by the launcher. | PASS |
| Stack constraints | MySQL and backend rules unaffected; no `applicationId` change. | PASS |

No violations, so no Complexity Tracking entries.

## Project Structure

### Documentation (this feature)

```text
specs/060-android-app-icon-name/
├── plan.md          # this file
├── research.md      # R1–R7 decisions
├── quickstart.md    # device verification steps
├── spec.md
└── checklists/requirements.md
```

No `data-model.md` (no entities) and no `contracts/` (no interface; the app's API and the server are not changed).

### Source Code (repository root)

```text
android-pos/app/src/main/
├── AndroidManifest.xml                         # EDIT: android:icon, roundIcon -> @mipmap/ic_launcher; label -> @string/app_name
└── res/
    ├── values/
    │   ├── strings.xml                         # NEW: app_name = "Warkop WJM POS"
    │   └── colors.xml                          # NEW: ic_launcher_background = #FFFFFF
    ├── drawable-nodpi/
    │   └── ic_launcher_foreground.png          # NEW: from pack android/adaptive-foreground.png (1024×1024)
    ├── mipmap-mdpi/ic_launcher.png             # NEW: from pack android/mipmap-mdpi/ (and -hdpi, -xhdpi, -xxhdpi, -xxxhdpi)
    ├── mipmap-anydpi-v26/
    │   └── ic_launcher.xml                     # NEW: adaptive (background colour + foreground drawable)
    └── xml/network_security_config.xml         # unchanged
```

**Structure Decision**: resources only, in the standard Android folders. The pack's PNGs are copied, not referenced from the design folder, so the build does not depend on a path outside the repository (the design folder is a separate, personal location on this machine).

## Design decisions

- **Adaptive icon for Android 8+**: `mipmap-anydpi-v26/ic_launcher.xml` declares the white background and the foreground drawable. Legacy launchers use the `mipmap-*` PNGs.
- **Foreground in `drawable-nodpi`**: stops density rescaling of the 1024 px image (research R3).
- **`roundIcon` points at `@mipmap/ic_launcher`**: the pack has no round PNG, and pre-Android-8 devices need a `mipmap-*/ic_launcher_round.png` file to resolve, so a round XML alone is not enough. Launchers that mask to a circle use the adaptive XML from `ic_launcher`.
- **Name from a resource**: `app_name` is the only place the label lives in the Android app.

## Complexity Tracking

Not applicable; no violations.
