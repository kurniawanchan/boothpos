# Feature Specification: License Activation Gate

**Feature Branch**: `018-license-activation`

**Created**: 2026-09-05

**Status**: Implemented (2026-09-06) — all 3 user stories verified end-to-end via real `php artisan test` runs (434/434 passing, 7 new tests) and a real browser walkthrough (Playwright) of the full locked → activate → login → dashboard flow, including a global-middleware regression across the ENTIRE existing test suite found and fixed during that verification. See `plan.md`'s Implementation Notes and `tasks.md` (28/28 complete) for full detail, including two guarantees (cross-machine binding, fully-offline validation) verified by a documented, honest substitute rather than a literal second machine / disabled network, since neither was safely available in this environment.

**Input**: User description: "buat sistem lisensi untuk mulai menggunakan sistem - lisensi tidak bisa dishare ke device lain - lisensi akan ditrigger kirim manual ke email client yg sudah melakukan pembayaran - lisensi dimasukkan ke dalam page sendiri utk unlock - lisensi validity cek secara local, encrypted, berikan saran bagaimana menerapkannya - ketika lisensi valid, maka semua fungsi akan unlock, jika blum, maka tidak bisa dibuka, jika dihit langsung dari url akan muncul page lock"

**Scope clarified with product owner before drafting**:
- This gate is entirely **separate from the Company Onboarding pipeline (feature 017)** — it governs whether *this specific BoothPOS installation* may be used at all, matching this product's existing "one-time license installed locally per store" business model (`CLAUDE.md`). It has no relationship to 017's internal sales/CRM tracker.
- The license binds to **the one machine running this installation** (matching the existing single-machine-per-store architecture) — not to individual staff browsers/tablets that connect to it. Any device on the local network reaching an activated installation works normally once that one machine is licensed.
- The gate applies **before the login screen is reachable at all** — an unlicensed installation shows a lock page for any URL, including the login page itself.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Enter a license key to unlock the installation (Priority: P1)

An operator who has just installed BoothPOS (or whose installation is otherwise unlicensed) is shown a dedicated activation page instead of the normal application. They paste in the license key they received and submit it. If the key is valid for this installation, the application becomes fully usable immediately — no restart, no separate configuration step.

**Why this priority**: This is the entire point of the feature — without it, there is no way to ever get past the lock screen, so nothing else in this feature has value on its own.

**Independent Test**: On a fresh, unlicensed installation, navigate to any URL and confirm the activation page appears. Submit a valid license key and confirm the normal application (starting with the login screen) becomes reachable immediately afterward.

**Acceptance Scenarios**:

1. **Given** an unlicensed installation, **When** the operator submits a valid license key on the activation page, **Then** the installation becomes activated and the normal application is immediately usable.
2. **Given** an unlicensed installation, **When** the operator submits an invalid or malformed license key, **Then** activation is rejected with a clear reason and the installation remains locked.
3. **Given** an already-activated installation, **When** any user visits any application URL, **Then** the normal application loads directly — the activation page is not shown again.

---

### User Story 2 - An unlicensed or de-licensed installation cannot be used for anything (Priority: P1)

Regardless of which URL is requested — the login page, a deep link to a specific screen, or the bare root address — an installation that is not currently in a validly-activated state shows only the lock/activation page. No application function, including logging in, is reachable until activation succeeds.

**Why this priority**: This is the enforcement half of the feature. Without it, the activation page in User Story 1 would be purely cosmetic — someone could simply navigate around it to reach the real application unlicensed.

**Independent Test**: On an unlicensed installation, attempt to reach several different URLs directly (root, login, a specific deep-linked screen) and confirm every one of them shows the lock page rather than any part of the real application.

**Acceptance Scenarios**:

1. **Given** an installation with no license ever entered, **When** any URL in the application is requested, **Then** the lock/activation page is shown instead of the requested content.
2. **Given** a previously-activated installation whose local activation record is later found to be invalid (see Edge Cases), **When** any URL is requested, **Then** the lock/activation page is shown again, exactly as if never activated.
3. **Given** the lock/activation page is showing, **When** the underlying application's data or API is accessed directly rather than through the normal screens, **Then** it is still refused — the lock is enforced by the system itself, not merely hidden by the screen the operator happens to see.

---

### User Story 3 - Vendor manually sends a license key after payment (Priority: P2)

After a store owner completes payment for their BoothPOS license (through whatever sales process the vendor uses — outside this application), the vendor manually triggers sending that store's license key to the client's email address.

**Why this priority**: Necessary for the business to actually operate, but it is a vendor-side administrative action taken far less often than the moment-to-moment activation/enforcement behavior in User Stories 1–2, and the feature's core lock/unlock guarantee holds regardless of exactly how the key was delivered.

**Independent Test**: As the vendor, trigger sending a license key for a specific paying client's email address and confirm the email is dispatched (or its delivery attempt is otherwise recorded/visible) with a key that successfully activates an installation when entered.

**Acceptance Scenarios**:

1. **Given** a client has completed payment, **When** the vendor manually triggers a license send for that client's email address, **Then** a license key is generated and an email containing it is dispatched to that address.
2. **Given** a license key has been sent, **When** the client enters it on their installation's activation page, **Then** activation succeeds (User Story 1).

---

### Edge Cases

- What happens if the installation's stored activation data is copied (along with the rest of the application's data) onto a different machine? That machine must show the lock page, not a working unlocked application — this is the specific behavior the "cannot be shared to another device" requirement exists to guarantee.
- What happens if the local activation record is deleted, corrupted, or otherwise unreadable? The system must default to treating this as **not activated** (locked), never as activated — an unreadable or missing record must never be interpreted as a green light.
- What happens if a store legitimately replaces or rebuilds the machine running their installation (hardware failure, upgrade)? The existing lock behavior would treat the new machine as unlicensed; the vendor must have a way to help that store re-activate on their new machine without this being indistinguishable from license sharing/abuse. The exact mechanism is a planning decision, but the requirement is that a legitimate hardware change is not a permanent dead end for a paying client.
- What happens if someone submits a license key that was validly issued but for a different installation/client? It must be rejected the same as any other invalid key — a key is not valid "in general," only for the specific installation it was bound to at activation time.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: The system MUST prevent every application function from being used until this specific installation has been successfully activated with a valid license key.
- **FR-002**: The system MUST show a dedicated activation page in place of any requested application content whenever the installation is not currently in a validly-activated state — this MUST apply to every URL, including the login screen and any deep link, with no exceptions.
- **FR-003**: The activation page MUST allow an operator to submit a license key and MUST report clearly whether activation succeeded or failed, including why it failed when possible (e.g., invalid format vs. rejected key) without revealing information that would help someone construct a working key by trial and error.
- **FR-004**: The system MUST validate a submitted license key's validity using only information available locally on the installation's own machine — no dependency on reaching any remote server or the internet for this check.
- **FR-005**: Upon first successful activation, the system MUST bind the license to the specific machine the installation is running on, such that copying the installation's stored data to a different machine does not itself constitute a working activation on that other machine.
- **FR-006**: The system MUST treat a missing, corrupted, or unreadable local activation record as **not activated**, never as activated — the system must fail closed, not open.
- **FR-007**: The locally-stored proof of activation MUST be encrypted/obfuscated such that it is not trivially readable or editable as plain text by inspecting the installation's local files or database.
- **FR-008**: Once activation succeeds, all previously-gated application functionality MUST become usable without any further restriction, restart, or additional configuration step.
- **FR-009**: The system MUST provide a way for an authorized vendor-side operator to manually trigger generating and emailing a license key to a specific client's email address — this action is deliberately manual/human-triggered, not an automatic self-service signup flow.
- **FR-010**: A license key MUST only activate the specific installation it was intended for — a key rejected for any reason must never partially unlock the application.

### Key Entities

- **License**: The credential issued by the vendor to one paying client — an identity (who it was issued to) and the key material itself. Issued and delivered by the vendor, outside the day-to-day operation of any single store's installation.
- **Installation Activation Record**: The local, encrypted proof that a specific installation successfully redeemed a license and was bound to the machine it was activated on. This is what every subsequent request checks — not the original license key itself, which is only consulted again if re-activation becomes necessary.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: 100% of URL requests to an unlicensed installation show the activation/lock page — zero paths (including direct API access) reach real application functionality.
- **SC-002**: An operator can unlock a freshly-installed system in under 2 minutes given a valid license key, with no step beyond submitting the key.
- **SC-003**: Copying an activated installation's full data to a different machine and starting it there results in the lock page, verified 100% of the time — never a working unlocked application on the new machine.
- **SC-004**: Local license validation succeeds with networking fully disabled on the installation's machine — zero dependency on internet connectivity.
- **SC-005**: A vendor operator can generate and send a license key to a specific paying client in under 2 minutes.

## Assumptions

- **License generation/sending tooling lives on the vendor's side, not inside each shipped installation.** Because one vendor issues licenses across many separate, independently-running store installations, the capability to *generate and send* a key is assumed to be a vendor-operated tool (its exact shape — a CLI command, a small internal utility — is a planning decision), while every individual customer's BoothPOS installation only ever needs to *validate and redeem* a key it receives. This mirrors how `docker/store/package-release.sh` (feature 016) is a maintainer-side tool never run on a store's own machine — the same separation of "vendor tooling" vs. "shipped product" applies here.
- **No expiry/subscription model assumed.** The request describes a one-time unlock tied to a completed payment, not a recurring subscription — this feature does not include license expiry, renewal, or revocation-over-the-network, since introducing any of those would require the exact kind of remote check FR-004 explicitly rules out. If a future need for time-limited licenses arises, that is separate, later-specified work.
- **Legitimate re-activation after a hardware change is a supported but manual, vendor-mediated path** (Edge Cases) — not a self-service "deactivate and move" feature in this pass, since building the latter well enough to resist abuse is materially more work than this request describes.
- **The activation page's failure messaging must balance operator-friendliness against not becoming an oracle for guessing valid keys** — FR-003's qualifier exists so implementation planning treats this as a real constraint, not an afterthought.
- **Technical approach for "local, encrypted" validation is intentionally left to the planning phase**, per this codebase's usual separation of WHAT (this spec) from HOW (`research.md` during `/speckit-plan`) — however, since the request explicitly asked for implementation suggestions, the recommended direction (to be elaborated fully in planning) is: a vendor-signed license key (verifiable offline using a public key baked into the app, so the app itself can never forge new valid keys) combined with a separately-stored, encrypted, machine-fingerprint-bound activation record created at the moment of first successful activation — the signature proves the key's authenticity, while the local fingerprint-bound record is what actually enforces "not usable on a different machine" on every later check.
