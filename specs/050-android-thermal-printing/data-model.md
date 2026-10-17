# Data Model: Thermal Printer for the Android POS

**Feature**: 050-android-thermal-printing | **Date**: 2026-10-17 | **Classification**: INTERNAL

This feature adds **no server table and no server field**. Everything below lives on the tablet. The server data it reads already exists.

## Server data read (unchanged)

| Source | Used for | Notes |
|---|---|---|
| `POST /tabs/{id}/send` → `tickets[]` (`TicketResource`) | KOT | One ticket per station per round. Fields: `id`, `round_no`, `station.name`, `table.name`, `tab_label`, `order.order_number`, `items[]` (`name`, `qty`, `modifiers`, `note`). |
| `GET /orders/{id}/receipt` (`ReceiptDto`) | Struk | Store name/address, `receipt_no`, `copy_no`, items, subtotal, discount, tax, total, paid, change, payment summary. |
| `POST /tabs/{id}/prebill` → tab (`TabDto`) | Pre-bill | Lines, totals, balance. |
| `POST /orders/{id}/reprints` | Reprint reservation | Returns the server copy number. |
| `GET /tickets/{id}` (`TicketResource`) | KOT reprint | Same shape as the send response. |

## Local entities (tablet only)

### PrintJob

One document to print.

| Field | Type | Rule |
|---|---|---|
| `key` | String | Unique. `kind:refId:copyNo`, e.g. `kot:2201:1`, `struk:118:2`, `prebill:77:1`, `batal:2201:1`. Dedupe identity (FR-007). |
| `kind` | Enum | `STRUK`, `KOT`, `PREBILL`, `BATAL`. |
| `refId` | String | Server id: order id for STRUK, ticket id for KOT/BATAL, tab id for PREBILL. |
| `copyNo` | Int | Starts at 1. Reprints reuse the server's copy number (D2). |
| `status` | Enum | `PENDING` (Belum tercetak), `FAILED` (Gagal), `PRINTED` (Tercetak), `DISMISSED`. |
| `attempts` | Int | Incremented per print try. |
| `lastError` | String? | `bluetooth_off`, `out_of_range`, `paper_out`, `write_error`, `not_configured`, or null. |
| `createdAt` | Instant | Enqueue time (device clock). |
| `printedAt` | Instant? | Set only when `PRINTED`. |
| `outletId` / `terminal` | Long / String | Copied from the terminal config so a job shows the right outlet. |

**State transitions**:

```text
PENDING --print ok--> PRINTED            (terminal; never printed again)
PENDING --print fail--> FAILED
FAILED  --retry ok--> PRINTED
FAILED  --retry fail--> FAILED           (attempts += 1)
FAILED  --user dismiss--> DISMISSED      (terminal; needs a reason for KOT, FR-020)
PENDING --dismiss--> DISMISSED
```

**Invariants**:
- A job in `PRINTED` is never sent to the printer again, by retry or by a new enqueue with the same key (FR-019).
- A job is written to the queue file **before** the print attempt, so a crash mid-print leaves it `PENDING` and not lost (FR-021). The cost is that a crash during the write itself can print a slip twice; this is accepted and documented in quickstart.md.

### PrinterConfig

| Field | Type | Rule |
|---|---|---|
| `name` | String | Bonded device name, shown to the user, e.g. "VSC TM-58V". |
| `address` | String | Bluetooth MAC of the chosen bonded device. |
| `paperMm` | Int | Constant `58`. Not user-editable (FR-003). |
| `columns` | Int | Constant `32` for the default font. **UNVERIFIED** until the test print. |

**Storage**: `printer.json` in `filesDir`. Absent = not configured.

### PrinterStatus (in memory, not stored)

`NOT_CONFIGURED` | `DISCONNECTED` | `CONNECTING` | `CONNECTED` | `ERROR(reason)`. Derived from the last socket attempt. Shown on the Perangkat screen (FR-001).

## Derived views (no storage)

- **Antrean cetak** = jobs with status `PENDING` or `FAILED`, newest first.
- **Order print state** on the Sales/Tender screen = the status of that order's STRUK job, or "Belum tercetak" when none is printed (FR-018).
- **Tab warning** = any `KOT` job for that tab in `PENDING` or `FAILED` (FR-020).

## Relationships

```text
PrintJob  --refId-->  server Order (STRUK, PREBILL) or KitchenTicket (KOT, BATAL)
PrintJob  --1..n--    Printer (single configured device)
```

## Validation rules (from the spec)

- Printed text is Indonesian regardless of app language (FR-016).
- No `phone` or `email` field is read into any template (FR-015). Enforced by a unit test that renders every template from fixtures containing those values and asserts they are absent.
- KOT templates contain no price fields (FR-014). Enforced the same way.
