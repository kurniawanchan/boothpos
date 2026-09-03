# Feature Specification: Android Tablet Installer (Standalone)

**Feature Branch**: `008-android-installer`

**Created**: 2026-09-03

**Status**: Draft

**Input**: User description: "buat sistem installer: Installer: buat installer android supaya bisa dijalankan di tablet android" — confirmed by the user to mean a **fully standalone** copy of BoothPOS running entirely on the tablet, not a client connecting to a separate machine.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Run a complete, independent BoothPOS on a tablet (Priority: P1)

A shopkeeper installs BoothPOS directly on an Android tablet and runs their booth entirely from that one device — no separate computer, no network dependency, no ongoing connection to anything else. The tablet **is** the store's BoothPOS installation, the same way a Mac or PC install is today, just on different hardware.

**Why this priority**: This is the entire point of "fully standalone" — without the app genuinely working end-to-end with nothing else running alongside it, nothing else in this feature matters.

**Independent Test**: On a fresh tablet with Wi-Fi turned off entirely, install the app, complete first-run setup, log in, and complete a full sale (add items to cart, take payment, see it recorded) with zero network connectivity at any point.

**Acceptance Scenarios**:

1. **Given** a tablet with the app freshly installed and never opened, **When** the user launches it for the first time, **Then** the app sets itself up (its own local data store, an initial owner account) without requiring any other device, server, or internet connection.
2. **Given** first-run setup is complete, **When** the user logs in, **Then** they reach the same dashboard, POS, and management screens that exist on the desktop version, running entirely from this tablet.
3. **Given** the tablet has no network connection at all, **When** the user performs a complete sale from start to finish, **Then** it succeeds exactly as it would on the desktop version — connectivity is never required for core operation.
4. **Given** the user closes and reopens the app (or restarts the tablet), **When** they return to the app, **Then** all previously entered data (products, stock, sales, sessions) is still there, unchanged.

---

### User Story 2 - Back up and restore the tablet's data (Priority: P2)

Because each tablet now holds the **only** copy of that installation's data (unlike a desktop install backed by a machine the shop already treats carefully), the shopkeeper needs a way to save a copy of everything off the device and bring it back — whether the tablet is lost, damaged, reset, or replaced.

**Why this priority**: A standalone device is a single point of failure for a store's entire sales history and product catalog; this is the safety net that makes running the "only copy" on a tablet acceptable risk, but it's still secondary to the core app working at all.

**Independent Test**: With a tablet holding real data (products, a completed sale), create a backup, wipe or reset the app's data, restore from that backup, and confirm every product, stock level, and sale is exactly as it was before the wipe.

**Acceptance Scenarios**:

1. **Given** the tablet has data on it, **When** the user creates a backup, **Then** a single file is produced that the user can move off the tablet (e.g., to a computer, cloud drive, or another storage location of their choice).
2. **Given** a backup file exists, **When** the user restores it (on the same tablet or a replacement one with the app freshly installed), **Then** all data from the moment of that backup is back exactly as it was.
3. **Given** the user attempts to restore a backup, **When** the tablet already has data on it, **Then** the app requires explicit confirmation before overwriting what's currently there.

---

### User Story 3 - Recognizable, tablet-appropriate app (Priority: P3)

Staff and the shopkeeper see a properly named and branded BoothPOS icon on the tablet's home screen, and every screen is comfortable to use with touch on a tablet-sized display without pinching, zooming, or awkward horizontal scrolling.

**Why this priority**: Polish that affects daily comfort and trust in the tool, but the app is fully functional without it — lowest priority of the three.

**Independent Test**: Install the app on a tablet, confirm the home-screen icon and app name clearly say "BoothPOS," and confirm every core screen (POS, session, product list, reports) is readable and operable by touch without zooming or scrolling sideways.

**Acceptance Scenarios**:

1. **Given** the app is installed, **When** the user looks at the tablet's home screen, **Then** the icon and label clearly identify it as BoothPOS.
2. **Given** the user is on any core screen, **When** they use it by touch, **Then** buttons, fields, and lists are sized and spaced for comfortable tablet use without needing to zoom or scroll horizontally.

---

### Edge Cases

- **Each tablet installation is its own independent island of data.** If a shop uses more than one tablet, or a tablet alongside an existing desktop install, they do **not** automatically share products, stock, or sales with each other — each is a separate BoothPOS instance with its own data, exactly as if they were separate stores. This feature does not introduce any syncing between installations; see Assumptions.
- What happens when the tablet's storage runs low? The app must warn the user before it runs out of space rather than silently failing or corrupting data mid-transaction.
- What happens if the app is updated to a new version? Existing on-device data must carry forward untouched — an update is not a reset.
- What happens if a restore is attempted from a backup file that doesn't match this app (wrong format, corrupted, from an incompatible version)? The app must reject it with a clear explanation rather than partially applying it.
- What happens if the tablet is factory-reset or the app is uninstalled without a backup having been made first? All data on that device is permanently lost — this is stated plainly to the user during backup-related prompts (see FR-011), not just left as an undocumented risk.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: The system MUST provide an installable Android application package that runs BoothPOS's complete functionality entirely on-device, with no dependency on a separate server, computer, or network connection for normal operation.
- **FR-002**: On first launch, the app MUST set up everything it needs to operate (its own local data storage and an initial owner account) without requiring any other device or an internet connection.
- **FR-003**: Every core capability available in the existing desktop version (POS sales, cashier sessions, product/stock/artist/category management, pre-orders, purchase orders, reports, settings, user/role management) MUST be available on the tablet, operating against that tablet's own local data.
- **FR-004**: The app MUST function with no network connectivity at all for every one of the capabilities in FR-003 — network access is never a precondition for core operation.
- **FR-005**: Data entered or generated on the tablet (products, stock, sales, sessions, pre-orders, etc.) MUST persist across app restarts and device reboots.
- **FR-006**: The user MUST be able to create a single backup file capturing all of that installation's data, at a time of their choosing.
- **FR-007**: The user MUST be able to move the backup file off the tablet to storage of their own choosing (this feature is only responsible for producing the file, not for where the user ultimately keeps it).
- **FR-008**: The user MUST be able to restore a previously created backup file, either onto the same tablet or a different one running the same app, fully replacing that installation's data with the backup's contents.
- **FR-009**: The app MUST require explicit user confirmation before a restore overwrites any existing data already on the device.
- **FR-010**: The app MUST reject a restore attempt from a file that is not a valid backup for this app, with a clear explanation, rather than partially applying invalid data.
- **FR-011**: The app MUST make clear to the user, at the point they'd naturally need to know it (e.g., first run, or wherever backup is offered), that this device holds the only copy of its data unless they back it up themselves.
- **FR-012**: The app's icon and displayed name MUST identify it as BoothPOS.
- **FR-013**: Core screens MUST be usable by touch on common tablet screen sizes without requiring pinch-zoom or horizontal scrolling to reach controls.
- **FR-014**: The installable package MUST be distributed as a file staff can install directly on a tablet (sideloading), consistent with this product's existing model of being installed by the shop rather than downloaded from a public app store.

### Key Entities

- **Local Data Store**: the complete set of a store's business data (products, stock, sales, sessions, customers, everything BoothPOS already tracks), held entirely on the tablet itself, with no copy anywhere else unless the user backs it up. Each app installation has exactly one, independent of any other installation.
- **Backup Archive**: a single file representing a complete snapshot of one installation's Local Data Store at the moment it was created, usable to restore that same state later, on the same or a different device.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: A shopkeeper with no technical background can install the app and complete first-run setup in under 3 minutes, with the tablet in airplane mode the entire time.
- **SC-002**: 100% of the desktop version's capabilities relevant to a given role are confirmed working on the tablet with no network connection present.
- **SC-003**: A full backup-then-restore cycle reproduces every product, stock level, and sale exactly as it was, with zero data discrepancies.
- **SC-004**: A shopkeeper can complete a backup in under 1 minute once they know where they want to save it.
- **SC-005**: The app continues operating correctly through at least 500 recorded sales and a full day's continuous use without a restart being required.

## Assumptions

- **Each tablet installation is entirely independent — this feature does not include syncing or sharing data between multiple tablets, or between a tablet and a desktop install.** A shop running several tablets is running several separate, unconnected BoothPOS instances, each with its own product catalog, stock, and sales history, exactly as if they were unrelated stores. If a shop needs several terminals sharing one shared set of data, that is the existing thin-client-to-one-machine model this system already supports over a local network — a fundamentally different feature from what's being built here, and out of scope for this spec.
- Making the *entire* existing application (today built as a server-side PHP application backed by a MySQL-compatible database) run standalone on an Android device — with no separate server process the user manages — is a substantial technical undertaking. This spec deliberately stays at the level of what the app must do for the user; **how** the existing server-side technology is made to run entirely on-device is a feasibility and design question for the planning phase, not this document. If planning determines the current technology stack cannot run standalone on Android within reasonable effort, that finding must be raised as a blocking issue before implementation proceeds, not worked around silently.
- A minimum Android OS version and minimum device storage/RAM are required for installation and operation; devices below that bar show the OS's own standard incompatibility message or an in-app storage warning (per the Edge Cases) rather than attempting to run in a degraded mode.
- The backup file format and mechanism are new to this feature but are expected to mirror the intent of this project's existing desktop backup/restore capability (a single-file, full-data snapshot) — enough to reuse operator understanding, not necessarily reuse implementation.
