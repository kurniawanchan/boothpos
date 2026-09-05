# Contract: License Endpoints + Vendor CLI

## Backend gate

`app/Http/Middleware/EnsureInstallationIsActivated` runs globally on the `api` middleware group (registered in `bootstrap/app.php`), on every route **except** the two below. When not activated, it short-circuits with:

```
423 Locked
{"message": "Instalasi ini belum diaktivasi.", "activated": false}
```

## `GET /api/v1/license/status`

No auth required (must be reachable while locked). Always `200`.

```json
{"activated": true}
```
or
```json
{"activated": false}
```

## `POST /api/v1/license/activate`

No auth required. Body:

```json
{"license_key": "<pasted string>"}
```

Success (`200`):
```json
{"activated": true}
```

Failure (`422`) — one generic reason, never more specific (research.md R1/data-model.md — avoids being an oracle for guessing a valid key):
```json
{"message": "Kunci lisensi tidak valid.", "errors": {"license_key": ["Kunci lisensi tidak valid."]}}
```

## Vendor CLI (never routed/exposed to the shipped product — research.md R5)

```bash
LICENSE_SIGNING_PRIVATE_KEY=<base64 ed25519 secret key, vendor's own local env only> \
  php artisan license:generate client@example.com "Toko Contoh"
```

- Generates a signed key for `issued_to = "Toko Contoh"`, sends it via email to `client@example.com` (mirrors `CompanyOnboardingService`'s send-and-audit-log pattern — logs to `storage/logs/laravel.log` if `mail.default === 'log'`, i.e. unconfigured, same as every other transactional email in this app).
- Prints the generated key to the console as well, so the vendor has a copy even if the email send fails.
- Fails loudly (non-zero exit, clear error) if `LICENSE_SIGNING_PRIVATE_KEY` is not set — this is the only thing preventing a customer's own installation from producing a working forged key (research.md R5).
