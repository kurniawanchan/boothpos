# Research: License Activation Gate

## R1 — Signing scheme: Ed25519 via PHP's built-in `sodium` extension

**Decision**: The vendor signs each license key's payload with an Ed25519 private key using `sodium_crypto_sign_detached()`; the app verifies with `sodium_crypto_sign_verify_detached()` against a public key baked into `app/Support/LicenseSigning.php` as a constant. Confirmed available: `php -m` on this dev machine already lists `sodium` — it has been PHP core since 7.2, so no new Composer dependency and no new PHP extension requirement beyond what this app (PHP 8.4) already needs.

**Rationale**: Asymmetric signing is the only approach that satisfies FR-004 (verify entirely offline) *and* keeps the app itself unable to forge new valid keys — a symmetric HMAC secret would have to ship inside the app (readable by anyone who has the codebase), letting anyone mint their own "valid" keys. Ed25519 over RSA: smaller keys/signatures, faster verification, and a single well-defined API (`sodium_crypto_sign_*`) with no padding-scheme decisions to get wrong, unlike RSA's PKCS1/PSS choices.

**Alternatives considered**: RSA via `openssl_sign`/`openssl_verify` — works, but requires choosing a key size and padding scheme and produces a larger key/signature for no benefit here. A symmetric HMAC — rejected outright per the forgery concern above.

**Critical operational note**: The **private** signing key must never be committed to this repository or shipped in any customer's `.env` — only the vendor's own local, out-of-version-control environment ever holds it (see R5).

## R2 — License key format

**Decision**: A license key is `base64(json_payload) . '.' . base64(signature)`, where `json_payload` is `{"license_id": "<uuid>", "issued_to": "<client email/store name>", "issued_at": "<ISO8601>"}`. This whole string is what the vendor emails and the operator pastes into the activation page.

**Rationale**: A single opaque string is the simplest thing an operator can copy-paste without transcription errors (versus, say, separate "key" and "signature" fields). No expiry field, per spec.md's assumption of a one-time, non-subscription license — adding one now would need the exact remote revocation-check machinery FR-004 rules out, so it's deliberately absent rather than present-but-unenforceable.

**Alternatives considered**: A shorter, human-typo-friendly code (like the 6-digit codes in features 016's sibling feature 017) — rejected because a license key encodes real identity data (`license_id`, `issued_to`) and a cryptographic signature, which cannot be compressed into 6 digits; this key is meant to be copy-pasted from an email, not memorized/typed by hand, so length is an acceptable tradeoff.

## R3 — Machine fingerprint: OS-specific, computed locally, never transmitted

**Decision**: `app/Support/MachineFingerprint.php` computes a stable per-machine identifier:
- **Linux** (native install, and the Docker store-deployment path per R6): read `/etc/machine-id` (a standard, purpose-built, stable-per-OS-install identifier already used by systemd distros for exactly this kind of need; `/var/lib/dbus/machine-id` as a fallback path on older systems).
- **macOS** (dev machines, per this project's own dev environment): shell out to `ioreg -rd1 -c IOPlatformExpertDevice` and extract `IOPlatformUUID` — confirmed working on this dev machine (`EF227E76-7FA3-5921-B682-B9853AB3B5B8`).
- The raw value is hashed (`hash('sha256', ...)`) before ever being stored — the stored record never contains the raw platform identifier in recoverable form (defense-in-depth alongside R7's encryption).

**Rationale**: Both identifiers are already the standard, OS-blessed mechanism for "identify this specific machine installation" — not a home-grown combination of hostname/MAC address (which change more often — a machine can get a new hostname or NIC without becoming a different machine, but its OS-level machine ID does not change from that). Using the OS's own purpose-built identifier is both simpler and more correct than assembling one from scratch.

**Alternatives considered**: Hashing a combination of hostname + primary MAC address — rejected as the primary mechanism because either can change (DHCP-assigned MAC on a replaced NIC, a renamed host) without the machine actually being "different" for licensing purposes, which would cause false lockouts on legitimate, non-abusive changes.

## R4 — Enforcement: a global backend middleware first, frontend router guard as a UX mirror only

**Decision**: `app/Http/Middleware/EnsureInstallationIsActivated` is registered globally on the `api` middleware group (`bootstrap/app.php`) and returns **423 Locked** with a machine-readable body for every request *except* the two license endpoints (`GET /license/status`, `POST /license/activate`) — this includes `/auth/login` itself, per FR-002's explicit "no exceptions." The Vue router's existing `beforeEach` guard (`resources/js/router/index.js`) gets a new check *ahead of* its existing `auth.isAuthenticated` check: if a small `useLicenseStore`'s cached status says "not activated," every route (including `/login`) redirects to a new `/activate` route, mirroring exactly how `to.meta.public` already short-circuits before the auth check today.

**Rationale**: Constitution IV is explicit — "every access-control decision MUST be enforced server-side; hiding a button or menu item in the UI is a cosmetic convenience only." A router-only guard would be trivially bypassed by calling the API directly (curl, Bruno), which SC-001 explicitly requires to also be blocked ("zero paths, including direct API access, reach real application functionality"). **423 Locked** is chosen over reusing 403 specifically because this app's existing convention (`CLAUDE.md`) already gives 403 a fixed meaning ("role/ownership denial") — license-lock is a categorically different condition (nothing to do with who the user is; the installation itself isn't authorized to run at all), and a distinct, semantically-correct status code lets the frontend tell the two apart without inspecting response bodies.

**Alternatives considered**: Reusing 403 for the locked state — rejected because it would make this app's own documented status-code convention ambiguous for every existing 403 consumer (does 403 now sometimes mean "the whole app is locked" and sometimes "you personally lack this role"?). A frontend-only gate — rejected outright per Constitution IV and SC-001.

## R5 — License generation is vendor-side CLI tooling; the private key never ships to customers

**Decision**: `php artisan license:generate {email} {issued_to}` is a new Artisan command that: (1) reads the signing private key from `LICENSE_SIGNING_PRIVATE_KEY` (an env var present only in the *vendor's own* local environment — never in `.env.example`, `.env.docker.example`, or `.env.store.example`), (2) builds and signs the payload (R2), (3) sends it via a new `LicenseKeyMail` to the given address, mirroring the exact synchronous-send-plus-audit-log pattern already established by `PreorderNotifier` (007) and `CompanyOnboardingService` (017) — including the same `mail.default === 'log'` → recorded-as-skipped convention.

**Rationale**: One vendor issues licenses across many independently-running customer installations, so — exactly like feature 016's `docker/store/package-release.sh` — the tool that *creates* the licensed artifact is maintainer/vendor-side, never a screen inside the shipped, customer-facing product. The command's **code** does ship inside this same repo (unlike feature 016's Docker image, this Laravel app has no separate vendor/customer build split) — this is safe specifically because the command is cryptographically inert without `LICENSE_SIGNING_PRIVATE_KEY`, which no customer's `.env` will ever contain; a customer who somehow ran this command would get an error, not a working forged license, since the app only ever ships the *public* verification key (R1).

**Alternatives considered**: A customer-facing admin screen to request/generate licenses — rejected outright per the product-owner's own scope decision (this is deliberately separate from and not routed through feature 017's Company admin UI).

## R6 — Docker store-deployment path needs the host's machine-id bind-mounted in

**Decision**: `docker-compose.store.yml`'s `app` service gets one new bind mount: `/etc/machine-id:/etc/machine-id:ro`.

**Rationale**: R3's Linux fingerprint reads `/etc/machine-id` — inside a container without this mount, that path either doesn't exist or (worse) is the *container's own* ephemeral ID, which changes every time the container is recreated (e.g., on every upgrade per feature 016's own documented upgrade procedure) — that would make a legitimately-activated Docker-based store re-lock itself on every routine upgrade, a severe, self-inflicted false lockout this feature must not cause. Bind-mounting the **host's** `/etc/machine-id` read-only ties the fingerprint to the actual physical/virtual machine the store runs on, stable across container recreation, exactly matching the native-install behavior and this feature's "one machine, one license" model.

**Alternatives considered**: Deriving a fingerprint from the container's own hostname/ID — rejected outright for the false-lockout-on-upgrade reason above; this would make feature 016's entire "upgrade preserves data" guarantee (its own SC-002/quickstart Section 3) collide with this feature's licensing lock, which is unacceptable.

## R7 — Activation record: one row, hashed fingerprint, fail-closed on any ambiguity

**Decision**: A new `license_activations` table holds at most one row (this app is single-tenant, matching `CLAUDE.md`'s "one-time license installed locally per store"): `license_id`, `issued_to` (both copied from the verified payload, informational/audit only), `machine_fingerprint_hash` (`Hash::make()` of R3's hashed fingerprint — the same one-way, non-reversible mechanism this codebase already uses for passwords and, per feature 017, activation codes), `activated_at`. `LicenseActivationService::isActivated()` returns true only if: a row exists, AND `Hash::check(current_fingerprint, stored_hash)` succeeds. Any other condition — no row, a read/decode error, a mismatch — returns false. NOT `HasDataMode`-scoped: this describes the installation itself, transcending DEMO/LIVE (the same category as `payment_channels`/`activity_logs`, per `CLAUDE.md`'s own existing rule) — switching to DEMO mode must never appear to "unlicense" the app.

**Rationale**: `Hash::make`/`Hash::check` is chosen over reversible `Crypt::encryptString` for the same reason it was chosen for passwords and feature 017's activation codes — the check only ever needs *equality*, never needs to recover the original fingerprint, so a one-way hash is the simpler, strictly-sufficient primitive. FR-006's fail-closed requirement is implemented by construction: `isActivated()` has exactly one path to `true` (a matching hash) and every other code path — including any exception thrown while reading/hashing — falls through to `false`, never the reverse.

**Alternatives considered**: Storing the activation state as an encrypted `Setting` row — rejected as a category mismatch; `Setting` is a general key-value config store, not designed for a single security-critical credential, and every other security-sensitive record in this codebase (payment proofs, activation codes) already gets its own dedicated table rather than being folded into a generic store.

## R8 — Honest limitation: this design cannot detect the *same* key being activated on two independently-fresh machines

**Decision**: Documented explicitly, not silently accepted or hidden. FR-005/SC-003 ask for (and this design provides) protection against *copying an already-activated installation's data* to a different machine — that is fully prevented, since the copied `license_activations` row's fingerprint hash won't match the new machine. What this design **cannot** prevent, given FR-004's explicit no-network-dependency constraint: someone taking the *original key string* itself (e.g., forwarding the vendor's email) and entering it into a second, independently-fresh installation, which has no way to know the key was "already used" elsewhere, since no shared server state exists between installations by design.

**Rationale**: Preventing key reuse across independently-fresh machines is only possible with a central, reachable authority recording "this key has been consumed" — which directly contradicts FR-004. This is not a bug to be engineered around; it is the correct, honest tradeoff of the fully-offline model the spec explicitly asked for, and matches this codebase's own established practice (`security-review` skill's tone guidance) of calibrating exploitability claims precisely rather than either overstating or silently omitting a known limitation. Mitigation, per spec.md's own Assumptions, is a vendor-side business process (tracking which key was issued to which client, noticing and investigating anomalies), not a technical control this feature implements.

**Alternatives considered**: A best-effort, non-blocking "phone home" ping at activation time purely for the vendor's own visibility (never gating the activation decision itself) — considered, but not adopted: it adds real scope (a new endpoint the vendor must host and monitor) that spec.md never asked for, and even a "non-blocking" network call sits uncomfortably close to violating the spirit of FR-004. Left as an explicitly out-of-scope future option, not built here.
