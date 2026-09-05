# Quickstart & Manual Verification: Company Onboarding

These steps double as this feature's acceptance verification, alongside the `tests/Feature/` suite (Constitution II).

## 1. Onboard a company (User Story 1)

1. As an owner/admin, `POST /companies` with a valid business type, package, company details, contact, and owner username/password.
2. **Verify**: response is `201` with `status: "pending_activation"`; `GET /users` (or DB) shows the new owner user with `is_active: false`.
3. **Verify**: with `MAIL_MAILER=log` (this repo's shipped default), the activation email is written to `storage/logs/laravel.log` rather than actually sent, and `company_activation_notifications` has one row with `status: "skipped_not_configured"` — not silently missing.
4. Configure a real mailer (e.g. `smtp` against a local Mailhog/Mailtrap) and repeat — confirm `status: "sent"` and the code arrives at the contact address.

## 2. Activate (User Story 2)

1. Using the code from step 4 above, `POST /companies/{id}/activate` with the correct code.
2. **Verify**: `200`, company `status: "active"`, `activated_at` set; the owner user is now `is_active: true` and can log in via `POST /auth/login` with the username/password set at onboarding.
3. Repeat with the same (now-consumed) code — **verify** `422` "already active."
4. Onboard a second company, wait past `activation_code_expires_at` (or manually backdate it in a test), submit the code — **verify** `422` "expired."
5. Submit a wrong 6-digit code against a fresh pending company — **verify** `422` "invalid code," company remains pending.

## 3. Resend invalidates the previous code

1. Onboard a company, capture its (hashed, so capture via a test-only debug hook or DB read of the pre-hash value in a controlled test) code.
2. `POST /companies/{id}/resend-activation`.
3. **Verify**: the original code no longer activates (422 invalid), only the newly issued one does.

## 4. Package lifecycle

1. Create a package, confirm it appears in `GET /packages?is_active=1` and as a selectable option for a new `POST /companies` call.
2. Deactivate it (`PUT /packages/{id}` with `is_active: false`).
3. **Verify**: it no longer appears in `?is_active=1`, but a company already referencing it still shows its package name/tier correctly via `GET /companies/{id}`.
4. Attempt `DELETE /packages/{id}` on a package referenced by an existing company — **verify** `409`.

## 5. Role gating

1. As a `cashier` or `inventory` user, attempt any `/companies`, `/packages`, `/business-types` endpoint.
2. **Verify**: `403` on all of them — the sidebar/menu for these screens must also be entirely absent for these roles (Constitution III — hidden, not disabled).
