# Research: Android POS App Icon and Name

Status: resolved 2026-10-18. Sources read: the icon pack README and files in `/Users/chan/www/warkopwjm/design/AppIcons/android/`, `android-pos/app/src/main/AndroidManifest.xml`, `android-pos/app/build.gradle.kts`, and the constitution.

## R1. Current state of the launcher icon and label

- **Decision**: treat the icon and label as placeholders to replace.
- **Verified**: `AndroidManifest.xml` sets `android:icon` and `android:roundIcon` to `@android:drawable/sym_def_app_icon` (the Android system placeholder), and `android:label="Warkop POS"` is a hard-coded literal. `app/src/main/res/` holds only `xml/` (the network security config); there is no `values/`, `mipmap-*`, or `drawable` folder.
- **Rationale**: the pilot tablet currently shows the generic Android icon, so the pack must be wired in for the first time.

## R2. Pack contents and sizes

- **Decision**: use the pack's legacy launcher PNGs for `ic_launcher` in each density, and its 1024×1024 RGBA image as the adaptive foreground.
- **Verified**: `mipmap-xxxhdpi/ic_launcher.png` is 192×192 RGBA. The pack has `mipmap-mdpi`, `-hdpi`, `-xhdpi`, `-xxhdpi`, `-xxxhdpi`, and `adaptive-foreground.png` (1024×1024 RGBA).
- **Rationale**: the pack already supplies the per-density launcher files the manifest expects, so no resizing is needed in the repository.
- **Alternatives rejected**: generating the set with Android Studio's Image Asset tool from the 1024 image (duplicates work the pack already did, and risks a different crop than the approved design).
- **Open check**: whether the 1024 foreground keeps its artwork inside the adaptive safe zone (the central 66% circle). This is verified visually on the device (quickstart step 2), not assumed.

## R3. Adaptive icon wiring

- **Decision**: add `res/mipmap-anydpi-v26/ic_launcher.xml` (and `ic_launcher_round.xml`) that refers to `@drawable/ic_launcher_foreground` (the pack's foreground, placed in `res/drawable-nodpi/`) and to `@color/ic_launcher_background`.
- **Rationale**: the pack's README suggests `adaptiveIcon` with a foreground and `#ffffff` background; the native equivalent is the adaptive XML. Placing the foreground in `drawable-nodpi` stops Android from rescaling it by density.
- **Alternatives rejected**: a bitmap-only icon (no mask support; looks wrong on round or squircle launchers).
- **Legacy devices**: pre-Android 8 launchers use the `mipmap-*/ic_launcher.png` set, so the legacy icon is still complete.

## R4. Background colour

- **Decision**: `#FFFFFF` in `res/values/colors.xml` as `ic_launcher_background`.
- **Rationale**: this is the default suggested by the pack's README. The spec records it as an assumption that the product owner may change; changing it is one line.

## R5. Display name

- **Decision**: add `res/values/strings.xml` with `app_name` = "Warkop WJM POS", and set `android:label="@string/app_name"` in the manifest.
- **Rationale**: a resource is the standard source for the app label and keeps the name in one place; the manifest literal is the only other copy today.
- **Scope**: the server's `app_name` setting (`AppName::DEFAULT` "BoothPOS") and the frontend default are not touched (spec FR-005).

## R6. Update compatibility

- **Decision**: no `applicationId`, signing, or `namespace` change. `versionCode` is not changed by this feature.
- **Rationale**: spec FR-004 and SC-003 require the update to install in place with stored settings and the paired terminal intact. Android keeps app data for an update with the same package and signature, so a resource-only change is safe. A `versionCode` bump is a release decision (the pilot build ships with a version increment on release, not in this feature).
- **Alternatives rejected**: changing `applicationId` to a new package (would orphan the stored data and the pairing).

## R7. Verification without a device

- **Decision**: `./gradlew --offline :app:assembleDebug` must succeed, plus the existing unit suite. The resource and manifest changes are checked by the build (`aapt` resolves `@string/app_name`, `@color/...`, and the adaptive XML); the look and the label are checked on the tablet.
- **Verified**: `./gradlew --offline :app:testDebugUnitTest` ran in earlier features on this machine (167 tests passing after 059), so the Gradle offline toolchain works here.
- **Rationale**: no unit test can assert a launcher icon; the build plus a device check is the honest coverage (constitution II: verify, don't assume).
