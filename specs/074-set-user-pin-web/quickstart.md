# Quickstart: verifying 074

**Classification**: INTERNAL

## Backend (real MySQL; all `-e` flags BEFORE `app`)
```bash
docker compose exec -e APP_ENV=testing -e DB_DATABASE=warkop_wjm_test \
  -e CACHE_STORE=array -e SESSION_DRIVER=array app \
  php artisan test --filter='UserPinAdminTest|PinLoginTest|PinLoginTerminalTest'
```
Expect: owner/admin can set a PIN for a user with none / replace an existing one; non-admin → 403 (even with the Users menu); weak or non-6-digit → 422 and nothing changes; lock and failed count cleared; the user's PIN tokens deleted; the old PIN refused and the new one accepted at `/auth/pin-sessions`; activity log row without the PIN; `has_pin`/`pin_locked` in the list and the `has_pin` filter; `/pin-suggestions` returns a valid PIN every time (many iterations) and is admin-only.

## Frontend
```bash
npm test
```
Expect `SetPinModal` and `UsersView` tests to pass (column, filter, action hidden for non-admin, generated PIN shown once and wiped on close).

## Real browser (Constitution II)
1. `npm run dev` (Vite :5180 here) with the API up; sign in as `owner`.
2. Users → a user with no PIN shows "No PIN"; filter "No PIN" keeps only such users.
3. Set PIN: type `482916` → saved → row shows "Has PIN". Try `111111` and `12345` → clear messages.
4. Reset PIN → Generate → note the PIN → Save → close; reopen: no PIN is shown anywhere.
5. Sign in on Android (or call `/auth/pin-sessions`) with the new PIN; the old one is refused.
6. Check the browser console for errors.

Not yet run — nothing is implemented.
