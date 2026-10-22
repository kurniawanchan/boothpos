# Quickstart / verification: Terminal name only

Run tests in the container ONLY with the safe flags (CLAUDE.md):
`docker compose exec -e APP_ENV=testing -e DB_DATABASE=warkop_wjm_test app php artisan test -e CACHE_STORE=array -e SESSION_DRIVER=array --filter=<Test>`

1. **Upgrade check (existing data)** — before migrating, note one old receipt (`T01-…`) and a paired tablet. `php artisan outlets:reconcile --before`, `php artisan migrate`, `--after --compare`. Expect: tablet still logs in; old receipt number unchanged; duplicate names (if any) suffixed.
2. **Pair by name** — `POST /api/v1/devices/pairings {"name":"Kasir Depan"}` → 201, no `code`. Same name again (also `" kasir depan "`) → 422 `name`. Other outlet, same name → 201. Revoke, then re-pair the same name → 201.
3. **Rename** — `PATCH /api/v1/devices/{id}` → 200; duplicate → 422; other outlet's device → 404.
4. **Receipt numbers** — settle two orders on two terminals of one outlet → `000001`, `000002`; an order at another outlet → `000001`. Old receipts unchanged.
5. **Android** (tablet or emulator): PIN screen, shift screens, chips, tab opened on another terminal, order list/detail, struk preview/print — each shows the terminal name; nothing shows `T0n`; a tablet paired before the upgrade shows "Terminal" until its first heartbeat, then its real name.
6. **Web Sales page (real browser, console clean)** — Terminal column present; filter appears only with ≥2 terminals in the list; export file has the Terminal column matching the screen.
7. **Suites**: `DevicePairingTest`, `ReceiptNumberingTest`, `TabOpenPosFieldsTest`, `TabPrebillTest`, `SalesTransactionsTest`, new `TerminalNameMigrationTest`; Android `./gradlew test`; `npm test`.
