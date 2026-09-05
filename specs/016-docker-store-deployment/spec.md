# Feature Specification: Docker-Based Store Deployment

**Feature Branch**: `016-docker-store-deployment`

**Created**: 2026-09-05

**Status**: Implemented (2026-09-05) — all 3 user stories verified end-to-end via real `docker build`/`docker compose up` runs (not just authored), including two real bugs found and fixed during verification (see `plan.md`'s Implementation Notes)

**Input**: User description: "Dockerize BoothPOS as a real store-deployment option (local deployment) — bukan cuma dev tooling seperti feature 015. Toko harus bisa deploy BoothPOS sepenuhnya via Docker image siap pakai (tanpa perlu clone source code, tanpa build lokal) di laptop toko, dengan data (MySQL) persisten antar restart/upgrade image, kompatibel dengan app:backup/app:restore yang sudah ada, dan proses update ke versi baru yang tidak menghilangkan data. Ini adalah channel deployment baru yang membalik keputusan lama di CLAUDE.md ('production = native non-Docker install'), bukan revert dari feature 015 yang murni untuk dev."

## User Scenarios & Testing *(mandatory)*

### User Story 1 - First-time store setup without a source checkout (Priority: P1)

A new store operator receives a laptop with Docker installed and no other tooling (no PHP, no Node, no MySQL client, no copy of the BoothPOS source code). They obtain the BoothPOS deployment artifact (a ready-to-run image plus a small config file), start it, and within a few minutes have a fully working POS reachable in their browser at `localhost` — ready to configure store settings and start selling.

**Why this priority**: This is the entire point of the feature. Without this, every store still needs a developer-grade setup (git clone, composer, npm, a local MySQL) just to get a working install — the exact friction this feature exists to remove.

**Independent Test**: On a clean machine with only Docker installed, follow the deployment instructions with no access to the source repository. Confirm the app loads at `localhost`, an owner can log in with the seeded default account, and a test sale can be recorded.

**Acceptance Scenarios**:

1. **Given** a clean laptop with Docker installed and the BoothPOS deployment artifact available, **When** the operator runs the documented start command, **Then** BoothPOS becomes reachable in a browser at `localhost` with no further manual setup step (no manual `composer install`, `npm install`, or database creation).
2. **Given** the app has just started for the first time, **When** the operator opens it, **Then** the database schema is already migrated and ready to use (no manual `php artisan migrate` step required).
3. **Given** the operator has no copy of the BoothPOS source code on the machine, **When** they follow the deployment instructions, **Then** they never need to obtain the source code to get the app running.

---

### User Story 2 - Data survives restarts, reboots, and version upgrades (Priority: P1)

A store has been operating for weeks with real sales, stock, and settlement data. The laptop is restarted (power loss, routine reboot, Docker restart), or the operator upgrades to a newer BoothPOS version. In every case, all previously recorded data is exactly as it was before.

**Why this priority**: A POS that loses transaction history on a routine restart is unusable for a real store — this is a correctness requirement, not a nice-to-have, and is the main risk introduced by moving to a container-based deployment.

**Independent Test**: Record a sale, stop and restart the deployment (simulating a reboot), and confirm the sale is still present. Separately, upgrade to a newer image version and confirm all prior data (orders, stock, settings) is unchanged.

**Acceptance Scenarios**:

1. **Given** a store has recorded sales and stock movements, **When** the deployment is stopped and started again, **Then** all previously recorded data is still present and unchanged.
2. **Given** a store is running on an older BoothPOS version, **When** the operator upgrades to a newer version following the documented process, **Then** all existing data is preserved and any new database changes are applied automatically, the same way the native install already auto-runs migrations.
3. **Given** an upgrade is in progress, **When** it fails partway through, **Then** the store's existing data and previous working version remain intact and recoverable (no silent partial state).

---

### User Story 3 - Backup and restore keep working exactly as documented (Priority: P2)

A store's existing operational routine already includes running a backup command and, if needed, restoring from one (documented in `docs/RUNBOOK.md`, WBS 9.2). An operator running the Docker-based deployment needs to be able to do the same thing, producing and consuming the same backup archive format already used by native installs.

**Why this priority**: Backup/restore is an already-shipped, relied-upon safety net. If the new deployment channel breaks or diverges from it, stores lose a capability they were promised, and support burden increases.

**Independent Test**: On a running Docker-based deployment, produce a backup, then restore it into a fresh deployment of the same version and confirm the data matches.

**Acceptance Scenarios**:

1. **Given** a running Docker-based deployment with data, **When** the operator runs the documented backup command, **Then** a backup archive is produced in the same format the native install already produces.
2. **Given** a backup archive from a Docker-based deployment (or from a native install), **When** the operator runs the documented restore command against a Docker-based deployment, **Then** the data is restored correctly.

---

### Edge Cases

- What happens when the operator starts the deployment for the very first time and no backup exists yet — does the system come up with a clean, empty, already-migrated database?
- How does the system behave if the host laptop loses power mid-write (not mid-upgrade) — is the persisted data left in a consistent state on next start, consistent with MySQL's own crash-recovery guarantees?
- What happens if an upgrade is attempted directly from a very old version (skipping several intermediate versions) — are all intermediate migrations still applied in order?
- What happens if the operator tries to run backup/restore while the app is mid-transaction (a sale in progress)?
- What happens if the laptop this is deployed on has no internet access at all, before, during, and after setup — does normal day-to-day operation (recording sales) still work, consistent with this product's existing "no cloud tier" design?

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: The system MUST allow a store operator to bring up a fully working BoothPOS instance (app + database) using only a container runtime and a small, documented configuration step — with no requirement to obtain the application source code, install a language runtime/toolchain, or run a build step on the store's machine.
- **FR-002**: The system MUST persist all business/transactional data (as already defined by the existing DEMO/LIVE-scoped models) across container restarts, host reboots, and version upgrades, with no data loss.
- **FR-003**: The system MUST automatically apply any pending database migrations on startup and on upgrade, consistent with how the native install already auto-runs migrations, so the operator never runs a manual migration command.
- **FR-004**: The system MUST support producing a backup from a running Docker-based deployment, in the same archive format already produced by the native install's `app:backup` command, and support restoring that same archive format back into a Docker-based deployment.
- **FR-005**: The system MUST support upgrading an existing Docker-based deployment to a newer released version while preserving all existing data, and MUST leave the store's data and prior working version intact if an upgrade fails partway through.
- **FR-006**: The system MUST continue to operate entirely on the store's local machine (`localhost`) with no dependency on an internet connection or a cloud tier for core, day-to-day operation (recording sales, managing stock), consistent with this product's existing single-machine design.
- **FR-007**: The system MUST allow store-specific configuration (at minimum: store identity/settings already supported by the app, and any deployment-level secrets) to be supplied at deployment time without modifying application source code.
- **FR-008**: The documentation of this product's deployment options MUST be updated to reflect that a container-based path is now a supported real store-deployment channel alongside the existing native install — as an explicit, dated addition, not a silent rewrite of the prior documented decision.
- **FR-009**: The system MUST support obtaining the deployment artifact via BOTH a container-registry pull AND an offline, file-based transfer (e.g. a portable image archive movable by USB/download) — the two paths must produce an identical, fully working deployment, so a store with no reliable venue internet is not blocked.
- **FR-010**: The system MUST require no automatic version-checking or update-notification mechanism — upgrading MUST be a single, explicit, operator-initiated action, consistent with this product's existing no-cloud-tier design.

### Key Entities

- **Deployment artifact**: The versioned, ready-to-run unit an operator obtains to stand up a store instance — distinct from the source code checkout used for development (feature 015).
- **Persistent data volume**: The durable storage location holding the store's database contents, decoupled from the deployment artifact's own lifecycle (an upgrade replaces the artifact but never the data volume).
- **Backup archive**: The existing, already-defined backup/restore format (WBS 9.2), unchanged by this feature — this feature only needs to keep working with it, not redefine it.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: A store operator with no prior BoothPOS or web-development experience can go from "Docker installed, nothing else" to a working, reachable POS in under 15 minutes, following only written instructions.
- **SC-002**: 100% of previously recorded transactional data survives every restart, reboot, and version upgrade exercised during testing — zero data loss incidents.
- **SC-003**: A backup taken from a Docker-based deployment restores successfully with complete data fidelity, matching the same success rate already expected of the native install's backup/restore path.
- **SC-004**: An operator can upgrade an existing store deployment to a newer version in under 10 minutes without contacting support, with all prior data intact afterward.

## Assumptions

- The existing per-store licensing model (`multi_artist_enabled`, `LicenseGate`) and its one-time-per-store nature are unchanged by this feature — a Docker-based deployment is still a single-store install, not a path to running multiple stores off one license.
- The existing `app:backup`/`app:restore` Artisan commands and their archive format (`contracts/backup-format.md`-style guarantees referenced elsewhere in this codebase) are reused as-is; this feature does not redesign backup/restore, only ensures it keeps working in the new deployment shape.
- "Store's machine" continues to mean a single physical/virtual machine at the venue, matching this product's existing no-cloud-tier, no-multi-tenancy design — this feature does not introduce a hosted or multi-store option.
- Seeding demo data (`SakanaFridgeDemoSeeder`) remains a deliberate, manual, opt-in step and is not part of the first-time store setup path (a real store deployment should start with clean/empty business data, not demo data).
- **Distribution**: The deployment artifact is supported via BOTH a container registry pull (for stores with reliable internet at setup/upgrade time) AND an offline file-based path (a portable image archive handed over via USB/download, for stores with unreliable or no venue internet). Both paths must produce an identical, working deployment — this is intentionally broader than a single-path MVP, per explicit product-owner direction.
- **Updates**: Fully manual and operator-initiated — consistent with this product's existing no-auto-update, no-cloud-check posture (see `CLAUDE.md`'s "no cloud tier" framing). There is no in-app version-check or update notification; the operator runs a single documented update command whenever they choose to.
