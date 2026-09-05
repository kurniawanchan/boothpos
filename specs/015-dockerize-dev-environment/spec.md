# Feature Specification: Dockerize Local Development Environment

**Feature Branch**: `015-dockerize-dev-environment`

**Created**: 2026-09-05

**Status**: Draft

**Input**: User description: "i want to dockerize the app" — clarified as scoped to the local development environment only (contributor onboarding), not a new production/store deployment channel. Production/store deployment is explicitly out of scope and unaffected: it remains a native install on the shopkeeper's machine, with no cloud tier and no Docker requirement there.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Get the full stack running with one command (Priority: P1)

A contributor cloning this repository for the first time wants to get the backend API, frontend dev server, and a real MySQL 8 database all running together, without manually installing PHP, Node, or MySQL locally, and without hand-configuring the database connection each time.

**Why this priority**: This is the entire point of the request — today a new contributor must install a specific PHP version, a specific Node version, and either install MySQL natively or already know to run the project's existing `laradock-mysql-1` container, then manually keep `.env`'s DB settings in sync. Removing that setup friction is the whole value of dockerizing.

**Independent Test**: Can be fully tested by cloning the repository on a machine with only Docker installed (no PHP, Node, or MySQL), following the documented startup steps, and confirming the app is reachable in a browser with a working login.

**Acceptance Scenarios**:

1. **Given** a clean checkout of the repository, **When** a contributor follows the documented Docker startup steps, **Then** the backend API, the frontend, and a MySQL 8 database are all running and connected to each other, with no manual `.env` database edits required beyond what's documented.
2. **Given** the stack is running, **When** the contributor opens the app in a browser, **Then** they can log in with one of the existing seeded dev accounts and use the app normally (POS, reports, etc.).
3. **Given** the contributor stops and restarts the stack, **When** it comes back up, **Then** previously seeded/entered data is still present (the database persists across restarts, not reset every time).

---

### User Story 2 - Run the automated test suites inside Docker (Priority: P2)

A contributor wants to run the existing backend (`php artisan test`) and frontend (`npm test`) test suites using the Dockerized environment, getting the same pass/fail results as running them natively, without needing a second, separate test database set up by hand.

**Why this priority**: Tests are how this project's own Constitution (Principle II) requires every change to be verified — if the Dockerized path can't run them, it's a second-class environment contributors will abandon for the native one the moment they need to verify a change.

**Independent Test**: Can be fully tested by running the documented Docker test command and confirming it reports the same total test count and pass/fail outcome as the existing native `php artisan test`/`npm test` commands.

**Acceptance Scenarios**:

1. **Given** the Dockerized stack, **When** the contributor runs the documented backend test command, **Then** the full backend suite runs against a real MySQL 8 database (not SQLite) and reports results, without affecting the development database's data.
2. **Given** the Dockerized stack, **When** the contributor runs the documented frontend test command, **Then** the full frontend suite runs and reports results.

---

### User Story 3 - Existing native workflow keeps working, untouched (Priority: P2)

A contributor who prefers the current native setup (PHP/Node installed locally, `laradock-mysql-1` for MySQL) wants that workflow to keep working exactly as it does today — Docker is an additional option, not a replacement they're forced onto.

**Why this priority**: This codebase's own environment constraints (documented in `CLAUDE.md`) were hard-won — MySQL-only migrations, the exact `laradock-mysql-1` container name, `.env.testing` pointing at a separate database — and this feature must not silently break or reinterpret any of them for contributors who don't opt into Docker.

**Independent Test**: Can be fully tested by following the existing (pre-this-feature) native setup steps on a machine that also has Docker installed, and confirming nothing about that native path changed.

**Acceptance Scenarios**:

1. **Given** a contributor follows only the pre-existing native setup instructions, **When** they run the app and its tests, **Then** the outcome is identical to before this feature existed.
2. **Given** both the native and Dockerized paths could theoretically run at once, **When** a contributor mistakenly tries to run both simultaneously, **Then** they get a clear, obvious conflict (e.g. a port already in use), not silent data corruption or a confusing mixed state.

---

### Edge Cases

- A contributor with no `.env` file yet (fresh clone) must have a clear, documented step to produce one for the Docker path, mirroring how the native path already documents this.
- Running the Dockerized database for the first time must apply migrations and (optionally) the demo seeder without requiring the contributor to already know Laravel's `artisan` command syntax by heart — the documented steps must spell out the exact commands.
- Stopping the Docker stack must not silently delete the database volume/data unless the contributor explicitly asks for that (e.g. a documented "reset" command, distinct from the normal "stop" command).
- The Dockerized backend must still refuse to run its test suite against SQLite or against the same database used for development data — this project's existing MySQL-only and separate-test-database rules apply identically inside Docker.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: A contributor MUST be able to start the backend API, frontend dev server, and a MySQL 8 database together via a single documented command, without first installing PHP, Node, or MySQL natively.
- **FR-002**: The Dockerized MySQL database MUST persist its data across stack restarts, and MUST require an explicit, separately-documented action to wipe it — restarting or stopping the stack normally MUST NOT lose data.
- **FR-003**: The Dockerized environment MUST support running this project's existing backend and frontend automated test suites, against a real MySQL 8 database, using a database separate from the development database (mirroring the existing native `.env.testing` convention).
- **FR-004**: The existing native (non-Docker) setup and workflow MUST continue to work exactly as documented today — this feature is additive, not a replacement or migration.
- **FR-005**: Documentation MUST be updated so a new contributor can choose either the native or the Docker path and follow one consistent, complete set of steps for whichever they pick.
- **FR-006**: The Dockerized setup MUST NOT change, weaken, or bypass any of this project's existing non-negotiable environment constraints (MySQL-only migrations, `.env.testing` pointing at a separate test database, etc.) — it must satisfy them inside containers the same way the native setup satisfies them on the host.
- **FR-007**: The Dockerized setup MUST NOT introduce or imply any change to how the actual product is deployed to a shopkeeper's machine — that remains a native, offline, single-machine install with no Docker dependency.

### Key Entities

- **Development environment configuration**: New, purely additive tooling (container definitions, an environment file for the Docker path) — does not represent or touch any business data.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: A contributor with only Docker installed can go from a fresh clone to a working, logged-in app in under 10 minutes, without installing PHP, Node, or MySQL.
- **SC-002**: 100% of the existing backend and frontend automated tests produce the same pass/fail outcome whether run natively or via the Dockerized path.
- **SC-003**: Zero changes to the native setup's documented steps or behavior — an existing contributor following only those steps notices no difference.
- **SC-004**: A contributor can stop and restart the Dockerized stack any number of times without losing previously entered data, and can deliberately reset it in one documented step when they do want a clean slate.

## Assumptions

- "Dockerize the app" refers to the local development environment only, per explicit clarification — it is not a new way of distributing or deploying the product to real stores, and does not replace or extend the in-progress Android tablet distribution channel.
- The existing `laradock-mysql-1` container (used today for MySQL only, with PHP/Node still run natively) is superseded, for contributors who opt into this feature, by a fuller Docker Compose setup covering PHP/Node too — contributors who prefer today's partial setup (native PHP/Node + that one container) may continue using it, per User Story 3.
- Seeded dev accounts and the demo seeder (`SakanaFridgeDemoSeeder`) continue to work identically inside Docker — no new seed data or accounts are introduced by this feature.
- No change to `APP_ENV`/production configuration semantics — Docker here targets `local`/`testing` environments only, matching how the native setup is scoped today.
