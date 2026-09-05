# Quickstart & Manual Verification: License Activation Gate

These steps double as this feature's acceptance verification, alongside `tests/Feature/`. Constitution II — cross-machine binding and offline validation have no automated-test equivalent (research.md's Technical Context) and are verified here manually, mirroring feature 016's precedent.

## 0. Generate a keypair (one-time, as the "vendor")

```bash
php -r '[$pub, $sec] = array_values((array) sodium_crypto_sign_keypair()); echo base64_encode($pub)."\n".base64_encode($sec)."\n";'
```

Set the public key via `LICENSE_PUBLIC_KEY` in `.env` (overriding `config/license.php`'s dev-only default — never edit a source-code constant for this, per its own comment); keep the secret key only in your own shell env for `license:generate`, never committed anywhere.

## 1. Unlicensed installation locks everything (User Story 2)

1. On a fresh installation (no `license_activations` row), visit `/`, `/login`, and any other deep link directly.
2. **Verify**: every one of them shows the activation/lock page — never the real login screen or any app content.
3. `curl -i http://127.0.0.1:8000/api/v1/auth/login -d '{"username":"owner","password":"password123"}'` — **verify** `423 Locked`, not a normal login attempt.

## 2. Enter a valid key to unlock (User Story 1)

1. `LICENSE_SIGNING_PRIVATE_KEY=<secret> php artisan license:generate test@example.com "Test Store"` — capture the printed key.
2. On the activation page, submit that key.
3. **Verify**: `200`, and the app now behaves normally — `/login` shows the real login screen, `GET /api/v1/license/status` returns `{"activated": true}`.
4. Submit an obviously-malformed string — **verify** `422` with the generic invalid-key message, installation stays activated (this doesn't un-activate it).

## 3. Cross-machine binding (FR-005/SC-003 — manual only, no automated-test equivalent)

1. With the installation from step 2 activated, copy its full database (or the whole app directory, for the Docker path) to a **different physical/virtual machine**.
2. Start the app there and visit any URL.
3. **Verify**: the lock page appears on the new machine — the copied `license_activations` row's fingerprint hash does not match the new machine's computed fingerprint.
4. Enter the same key again on that new machine — **verify** it activates successfully there too (research.md R8's documented, honest limitation — this is expected, not a bug: the key itself isn't single-machine-locked, only an *already-activated installation's data* fails to transplant silently).

## 4. Fully offline validation (FR-004/SC-004 — manual only)

1. Disconnect the machine's networking entirely (airplane mode / unplug).
2. Repeat step 2 (submit a valid key) — **verify** activation still succeeds with zero network connectivity.

## 5. Docker store-deployment path (research.md R6)

1. Bring up `docker-compose.store.yml` with the `/etc/machine-id` bind mount in place.
2. Activate, then run through feature 016's own upgrade procedure (`docker compose pull app && up -d app` or the offline equivalent).
3. **Verify**: the installation is still activated after the upgrade — the fingerprint (host `/etc/machine-id`) did not change just because the `app` container was recreated.
