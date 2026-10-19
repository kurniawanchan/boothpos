# Feature Specification: Android POS App Icon and Name

**Feature Branch**: `060-android-app-icon-name`
**Created**: 2026-10-18
**Status**: Draft
**Input**: User description: "change android app icon to follow in this folder: /Users/chan/www/warkopwjm/design/AppIcons and change the app name to Warkop WJM POS."

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Recognise the POS on the tablet by its new icon (Priority: P1)

A cashier or owner looks at the tablet's home screen or app drawer and sees the Warkop WJM icon from the approved icon pack, not the placeholder or the previous icon.

**Why this priority**: the icon is the first thing staff see, and the icon pack is the approved brand asset.

**Independent Test**: install the updated app on the pilot tablet and check the launcher, the app drawer, and the recent-apps view show the new icon at every density.

**Acceptance Scenarios**:

1. **Given** the app is installed with this change, **When** the user opens the app drawer, **Then** the launcher icon is the Warkop WJM icon from the icon pack.
2. **Given** a tablet whose launcher uses a shaped mask (circle, rounded square), **When** the icon is shown, **Then** the foreground stays fully visible and the background is the approved plain colour (no clipped logo).
3. **Given** the same app on a lower- or higher-density screen, **When** the icon is shown, **Then** it is sharp (no visible blur or pixelation).

---

### User Story 2 - See the name "Warkop WJM POS" everywhere the app is named (Priority: P1)

The app's name as shown under the icon and in the system app list reads "Warkop WJM POS".

**Why this priority**: the name is what staff and the shop use to identify the app; it must match the new brand name exactly.

**Independent Test**: after install, read the label under the launcher icon and in Settings > Apps.

**Acceptance Scenarios**:

1. **Given** the app is installed, **When** the user reads the launcher label, **Then** it reads exactly "Warkop WJM POS".
2. **Given** the app is open, **When** the user opens Settings > Apps, **Then** the app is listed as "Warkop WJM POS".
3. **Given** the name is long for the launcher, **When** it wraps or truncates, **Then** the leading words "Warkop WJM" remain readable.

---

### Edge Cases

- The adaptive-icon background colour is not set by the icon pack; the pack's own guide suggests plain white (`#ffffff`). This needs a product decision or confirmation of that default.
- A device with an older launcher that ignores adaptive icons must still show a complete icon (the legacy launcher icon from the pack).
- The App Store and iOS icon assets in the pack do not apply: this repository has no iOS project.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: The Android POS app MUST use the launcher icons from the approved icon pack (`design/AppIcons/android/`) for every screen density the pack provides.
- **FR-002**: The app MUST provide an adaptive icon built from the pack's foreground image, on the approved background colour, so launcher masks display the logo fully.
- **FR-003**: The app's display name MUST be exactly "Warkop WJM POS" wherever Android shows it (launcher label, app list, task switcher).
- **FR-004**: The change MUST NOT alter the app's package identifier, signing, or data, so an existing install updates in place and keeps its stored settings and queued data.
- **FR-005**: The change MUST NOT alter the server-side app name setting (`app_name`, the "Powered by" text on documents). Renaming that is a separate decision.
- **FR-006**: Store-marketing and iOS assets from the pack are out of scope for this change and MUST NOT be wired into the repository.

### Key Entities

- **Launcher icon set**: the legacy launcher icons per density and the adaptive foreground, taken from the pack.
- **Display name**: the single string resource Android uses as the app label.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: On the pilot tablet, the launcher icon matches the approved icon pack image for 100% of the checked locations (launcher, app drawer, recent apps).
- **SC-002**: The label shown under the icon reads "Warkop WJM POS" character for character on the pilot tablet.
- **SC-003**: An app updated from the previous build keeps all stored settings, the paired terminal, and queued print jobs (no re-pairing required).
- **SC-004**: The Android unit test suite still passes with no failures after the change.

## Assumptions

- "Android app" means the Android POS app in `android-pos/` (the app the user runs on the tablet). The old WebView shell in `android/` is not changed.
- The icon source is the Android folder of the pack at `/Users/chan/www/warkopwjm/design/AppIcons/android/`; the pack's Xcode and store assets are not used.
- The adaptive background colour defaults to `#ffffff` as suggested by the pack's guide, unless the product owner gives another colour.
- The name change applies to the Android launcher and system labels only; the server's `app_name` setting stays "BoothPOS" unless separately requested.
- No server or API change is needed.
