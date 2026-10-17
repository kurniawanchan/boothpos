# Quickstart: Thermal Printer for the Android POS

**Feature**: 050-android-thermal-printing | **Classification**: INTERNAL

Use this to build, test, and verify the feature on a real tablet and a real VSC TM-58V. The feature is not done until section 4 is complete (Constitution II: evidence from the current session, not inferred from the diff).

## 1. Build and unit-test (no printer needed)

```bash
cd android-pos
./gradlew :core-network:testDebugUnitTest :app:testDebugUnitTest
./gradlew :app:assembleDebug
```

Prerequisites: JDK 17 and an Android SDK with platform 34 and build-tools. An SDK exists at `~/Library/Android/sdk` on the development machine (build-tools and emulator are present); confirm `platforms/android-34` is there before the first build, because `quickstart.md` in the app root lists it as required.

**Expected new tests**: `EscPosTest`, `DocumentsTest`, `PrintQueueTest`, `TicketDtosTest`. All must pass before any device test.

## 2. Backend

No server change. Confirm nothing regressed by running the existing send and receipt tests:

```bash
php artisan test --filter=KitchenTicketsTest
php artisan test --filter=ReprintTest
```

Run them with the flags from CLAUDE.md, inside the container:

```bash
docker compose exec -e APP_ENV=testing -e DB_DATABASE=warkop_wjm_test app php artisan test -e CACHE_STORE=array -e SESSION_DRIVER=array --filter=ReprintTest
```

Use only the `warkop_wjm_test` database. Never run tests without the `-e` overrides (CLAUDE.md warns this has wiped dev data twice).

## 3. Pair the printer (once, on the tablet)

1. Switch on the VSC TM-58V and load 58 mm paper.
2. On the tablet, open Android **Settings → Bluetooth**, pair with the printer (PIN if asked, usually `0000` or `1234` for this class; **UNVERIFIED**, check the manual).
3. In the POS app, open **Perangkat & sinkron → Printer**, pick the paired "VSC TM-58V".
4. Press **Tes cetak**. Confirm a readable test slip with the store name and the date.

## 4. Device verification (required before "done")

Run each row on the tablet with the printer on, record the result in the table at the end of this file, and keep the photo of the slip.

| # | Check | Pass when |
|---|---|---|
| V1 | Test print | Slip is readable, the line of sample characters fits and does not clip. |
| V2 | Width | A 32-character test line prints on one line (confirms the `columns = 32` assumption). If it wraps early or clips, change `columns` in `EscPos.kt` and re-run V1. |
| V3 | Kirim ke dapur | Three items with one modifier and one note produce **one** KOT per station, with `KOT` in capitals. No prices on it. |
| V4 | Double send | Pressing "Kirim ke dapur" twice with no new items prints nothing the second time. |
| V5 | Retry after network drop | Put the tablet into airplane mode after pressing send, then restore it; the KOT prints exactly once. |
| V6 | Pay | Pay in cash with change. Struk prints automatically; the amounts match the screen. |
| V7 | Printer off during pay | Switch the printer off, pay. Payment is saved, the struk shows "Belum tercetak". Switch the printer on, press retry: it prints once. |
| V8 | Bluetooth off | Turn Bluetooth off on the tablet, send to the kitchen. The KOT job shows the message "Bluetooth mati" and the tab shows the warning. Nothing is lost. |
| V9 | Out of paper | Remove the paper, print. Message "Kertas habis"; job stays pending. |
| V10 | Reprint | Reprint from the Orders list: copy 2 prints as "SALINAN 2" with no approval. Copy 3 asks for a supervisor PIN. |
| V11 | Pre-bill | Print a pre-bill: the table shows "Bill tercetak" on the floor view. |
| V12 | Void a sent item | A BATAL slip prints for the kitchen. |
| V13 | Long text | Use the longest item name and a long note in the shop's menu. Nothing is cut at the right edge (SC-006). |
| V14 | Restart | Kill the app while a job is pending, reopen it. The job is still in Antrean cetak and prints once. |
| V15 | Timing | From "Kirim ke dapur" to paper is under 5 seconds on the shop network (SC-002). |

**Known risk to test first**: V2 and V1 confirm the two unverified printer facts (SPP connection and 32-column width). If V1 fails, stop and check the printer manual before running the rest, because every later check depends on it.

## 5. Results log

| Check | Result (pass / fail / not run) | Date | Tablet model and Android version | Notes |
|---|---|---|---|---|
| V1 | | | | |
| V2 | | | | |
| V3 | | | | |
| V4 | | | | |
| V5 | | | | |
| V6 | | | | |
| V7 | | | | |
| V8 | | | | |
| V9 | | | | |
| V10 | | | | |
| V11 | | | | |
| V12 | | | | |
| V13 | | | | |
| V14 | | | | |
| V15 | | | | |

## 6. Known limits of this quickstart

- A crash during the queue-file write itself can print a slip twice on restart. The write is atomic, so this window is very small, and it is accepted for a paper copy (recorded in data-model.md).
- Device checks need a person at the shop with the printer. They cannot be automated here.
