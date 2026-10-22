# Evidence — 064 Terminal identified by name only (2026-10-22)

## Real browser (web `/sales`) — DONE
Headless Chrome 155 over CDP against the dev stack (`localhost:8010`, SPA rebuilt with `npm run build`), logged in as the seeded `owner`.
- Columns: `… Cashier · Terminal · Sold at · Item …` — Terminal cells read "Tablet kasir 10", "Tablet kasir 8", … (names; no `T0n` text anywhere in `document.body.innerText`).
- "All terminals" multi-select shown (≥ 2 terminals in the list). 49 rows, 0 console errors / exceptions / `Log` errors.
- Screenshot kept in the session scratchpad (not committed).

## Android (tablet / emulator) — NOT DONE
No tablet or emulator was available in this session. What was verified instead: `./gradlew testDebugUnitTest` for `app` + `core-domain` (274 tests, 0 failures) including new `OrderMapperTerminalTest` and struk/receipt tests. On-device checks from quickstart step 5 (PIN screen, shift screens, chips, other-terminal tab, order list/detail, struk preview/print, and the "pre-upgrade tablet learns its name from the heartbeat" case) still need a run on a real tablet.

## Upgrade rehearsal (T036) — DONE on a COPY of the dev DB
`warkop_wjm` dumped into a scratch schema, then `outlets:reconcile --before` → `migrate` → `--after` → `--compare`: **REKONSILIASI OK — tidak ada selisih** (orders 69, order_items 97, users 12, …).
- 26 active terminals with 3 distinct names → 26 distinct names after (`Tablet kasir`, `Tablet kasir 2`, `Tablet kasir Samsung`, `Tablet kasir 3`, …); `devices.code` nullable; old `T11-000001` receipts untouched.
- `migrate:rollback --step=1`: `devices.code` NOT NULL again.
- Scratch schema dropped afterwards.

## Full suites — DONE
- Backend (real MySQL, safe `-e` flags): **1839 passed, 1 skipped, 0 failed** (8754 assertions, 2670 s).
- Vitest: 189 files / 1649 tests passed; `npm run build` OK (`localeKeys` guard green).
- Android `app` + `core-domain`: 274 tests, 0 failures (`core-network` tests blocked by the pre-existing compile error below).

## Known pre-existing failure (not caused by this feature)
`android-pos` `:core-network:compileDebugUnitTestKotlin` fails in `S4DtosTest.kt:51` (`ReprintRequestDto()` — "No value passed for parameter 'seen0'/'copyNo'/'overrideToken'") on a clean HEAD too (verified by stashing this feature's changes). Consequence: `core-network`'s own unit tests (including the `ReceiptDtoTest` assertion added here) cannot run until that file is fixed; the same JSON field is covered by `ReceiptMapperTest` in `app`.
