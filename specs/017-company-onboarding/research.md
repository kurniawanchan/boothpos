# Research: Company Onboarding

## R1 — New menu key (`companies`), gated owner/admin-only via a default-roles migration

**Decision**: Add a `companies` menu key, granted to the default `Owner` and `Admin` roles only, via a new migration (`2026_10_16_000005_add_companies_menu_key_to_default_roles.php`) that appends to `menu_keys` on the seeded default roles — mirroring `2026_10_12_000001_add_purchase_orders_menu_key_to_default_roles.php` exactly.

**Rationale**: Authorization in this codebase is genuinely role/menu-key-driven (`User::canAccessMenu()` reading `Role.menu_keys`, a JSON column) — not the stale "three mechanisms" `CLAUDE.md` still describes (already flagged as an Info finding in the 2026-09-05 security review, unrelated to this feature). Every existing admin-only entity (Vendors, Materials, Purchase Orders) got its own dedicated menu key added the same way when introduced; Company/Package onboarding is exactly this shape of change. A single `companies` menu key governs Company, Package, and BusinessType management together (they're one cohesive onboarding capability, not three independently-permissioned surfaces) — consistent with FR-012's "restricted to owner/admin roles" and the spec's explicit choice of owner/admin gating over the broader `canManageMasterData()` tier (that tier includes `inventory`, which has no business reason to onboard companies).

**Alternatives considered**: Reusing the existing `settings` menu key (`isOwnerOrAdmin()`'s underlying check) — rejected because it would make Company/Package access inseparable from actual store-settings access, and every other admin-only entity in this codebase gets its own dedicated key rather than piggybacking on `settings`.

## R2 — Activation code: 6-digit numeric, hashed at rest, single active code per company

**Decision**: `companies.activation_code_hash` (a `Hash::make()` hash of a `random_int(100000, 999999)` code, zero-padded to 6 digits) + `companies.activation_code_expires_at` (24 hours from generation). Only one code is ever valid per company — generating a new one (at creation or on resend) overwrites both columns, immediately invalidating the previous code. Verification uses `Hash::check()`, never a plaintext comparison.

**Rationale**: Mirrors this codebase's own password-hashing convention (`Hash::make`/`Hash::check` already used for `users.password`) rather than inventing a new secret-storage mechanism. A 6-digit numeric code (not a UUID like `payment_proofs.proof_token`) is deliberately chosen because this code is meant to be *read from an email and typed*, the same UX as a bank/OTP code — a UUID is copy-paste-only and a worse fit for that channel. Storing it hashed (not plaintext) means a database read/leak cannot itself activate any pending company, consistent with Constitution IV's security principle even though this is administrative data, not payment data. Overwriting rather than appending rows for "the current code" directly satisfies FR-006 ("resend invalidates any previously issued, unused code") with no separate cleanup step.

**Alternatives considered**: A UUID token like `proof_token` — rejected as a worse fit for an emailed, human-typed code. Keeping a history of every issued code (append-only) — rejected as unnecessary; only the *attempt* needs a permanent audit trail (R3 below), not the code values themselves, and keeping old code hashes around after a resend serves no purpose since they're already invalidated.

## R3 — Every send attempt logged in a dedicated audit table, mirroring `PreorderNotification`

**Decision**: A new `company_activation_notifications` table (`company_id`, `trigger` [`created`|`resend`], `status` [`sent`|`skipped_not_configured`|`failed`], `error_message`, `sent_at`) — an exact structural mirror of the existing `preorder_notifications` table/`PreorderNotification` model, written by `CompanyOnboardingService` the same way `PreorderNotifier` writes its own log: after the company/user database work commits, never inside that transaction, and never allowed to make the onboarding action itself fail if the email send fails.

**Rationale**: FR-005 requires every send attempt be visible and never silent — this codebase already solved exactly this problem for preorder status emails, including the specific edge case of an unconfigured mail system (`config('mail.default') === 'log'`) being recorded as `skipped_not_configured` rather than a hard failure. Reusing that exact shape (rather than inventing a new one) means this feature is recognizable to anyone who already knows the preorder-notification code, and avoids two different audit-log conventions existing side by side for what is structurally the same problem.

**Alternatives considered**: A single mutable "last send status" field on `companies` — rejected because it cannot show history across a create + one or more resends, which FR-005/SC-002 ("100% of activation-code send attempts are auditable") explicitly require to be visible per-attempt, not just the latest one.

## R4 — Package's licensing tier is descriptive data only; it does NOT touch `LicenseGate`/`multi_artist_enabled`

**Decision**: `packages.license_tier` (`'pro'`|`'master'`) is recorded on the `Company` purely as informational data about what that company signed up for. Activating a company does **not** call `Setting::set('multi_artist_enabled', ...)` or otherwise mutate this single installation's own license configuration.

**Rationale**: This is the one place where the "internal CRM tracker, not multi-tenancy" scope decision (confirmed with the product owner before drafting spec.md) has a sharp, easy-to-get-wrong edge: `multi_artist_enabled` is a single, global `Setting` row governing *this one installation's own* license tier (`LicenseGate`). If activating Company B silently overwrote that global setting, it would retroactively change what Company A (already using this same single install) is licensed for — a real correctness bug, not just an out-of-scope nice-to-have, because this app has no per-company data partition to make that mutation apply "only to" one company. The actual application of a company's chosen tier to *their own eventual separate installation* (native or Docker, per feature 016) is necessarily a manual, out-of-band step performed when that installation is provisioned — this feature's job ends at recording what was sold, not enforcing it live in a shared single-tenant install.

**Alternatives considered**: Auto-applying the tier to `Setting` on activation — rejected outright per the correctness problem above. Blocking a second company from being onboarded at all (to make the "one setting" assumption safe) — rejected as an arbitrary, unrequested restriction not implied by anything in spec.md; the CRM-tracker framing already implies multiple company *records* can coexist as pipeline data without implying multiple live licenses.

## R5 — Rate-limit the activation endpoint

**Decision**: `throttle:10,1` (10 attempts per minute) on the activation endpoint, mirroring the existing `throttle:5,1` already applied to `/auth/login`.

**Rationale**: A 6-digit code (R2) has only 1,000,000 possible values — without rate-limiting, an attacker with network access to this single-machine install (already assumed to be least-trusted per the existing security review's threat model) could brute-force it in a practical amount of time via automated requests. This is a direct, cheap mitigation for a real, newly-introduced attack surface, not defense-in-depth for its own sake — flagged explicitly per Constitution IV rather than left implicit.

**Alternatives considered**: A longer/alphanumeric code instead of rate-limiting — rejected because it would also make the code harder to read/type from an email, working against the exact UX reason a short numeric code was chosen in R2; rate-limiting solves the brute-force risk without that tradeoff.

## R6 — `Company` is `HasDataMode`-scoped; `Package`/`BusinessType` are not

**Decision**: `Company` (and `CompanyActivationNotification`, transitively via its parent) uses the existing `HasDataMode` trait, exactly like `Customer`/`Vendor`. `Package` and `BusinessType` do not — exactly like `PaymentChannel`.

**Rationale**: A company record is business/transactional data created at a point in time (like a Customer), so it fits `CLAUDE.md`'s existing rule ("business or transactional data" gets `HasDataMode`) directly. Package and Business Type are reference/configuration lists an admin maintains once and reuses across many companies (like Payment Channels), not per-transaction records — they should be visible identically in DEMO and LIVE mode, the same reasoning `CLAUDE.md` already gives for `payment_channels` not being mode-scoped.

**Alternatives considered**: None seriously — this maps directly onto an existing, already-documented rule rather than requiring a new judgment call.

## R7 — Owner user resolved against the existing seeded `Owner` role by name

**Decision**: `CompanyOnboardingService::onboard()` resolves `role_id` for the new owner `User` by looking up the seeded system-default role named `'Owner'` (`Role::where('name', 'Owner')->where('is_system_default', true)->firstOrFail()`), never accepting a client-supplied `role_id` for this user.

**Rationale**: Roles became fully data-driven in feature 001 (`Role.menu_keys`, replacing the old fixed 4-value enum) — there is no compile-time "owner" role constant to reference. The existing `DatabaseSeeder` already seeds a system-default `Owner` role with full menu access, and every fresh installation is expected to have it (it's how the very first owner user logs in at all). Never trusting a client-supplied `role_id` for this specific creation path is a direct application of Constitution IV ("client-supplied values ... never trusted") — the onboarding form only supplies a username/password for the owner login, not their permission level, which this feature always fixes to the full-owner role by design.

**Alternatives considered**: Letting the onboarding form choose any role for the new user — rejected; FR-002 only asks for "the company's owner login," not an arbitrary role assignment, and allowing arbitrary role selection here would reopen a privilege-escalation surface for no requested benefit.
