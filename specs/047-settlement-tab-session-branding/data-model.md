**Classification: INTERNAL**

# Data Model: Seller Settlement (feature 076 / spec dir 047)

New tables only; **no existing table changes** except what is listed under "Touches on existing data". Money = `decimal(14,2)` (same as `orders`/`payments`), returned to clients as 2-dp strings. Names in the app are Indonesian-commented as per codebase convention; the schema below is the contract.

Table names carry the `seller_` prefix on purpose: `artist_settlements` already exists (POS-only Seller Recap cache, feature 040) and is left untouched.

## `seller_settlements` — one statement per (seller, event)

| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| data_mode | enum demo/live | `HasDataMode` trait; stamped at creation |
| number | varchar(30) NULL, UNIQUE | `STL-YYYYMMDD-NNNN`, assigned at the **first finalize** (null only for a never-finalized draft) and **kept through reopen**; a draft that has a number cannot be deleted. Sequence counted across both modes with `withoutGlobalScope(DataModeScope)` inside the finalize transaction (`lockForUpdate`), unique index as the backstop; one retry on duplicate key. Clone appends `-DM`. |
| event_id | FK events | restrict; snapshot below |
| artist_id | FK artists | restrict; the "seller" |
| event_name, artist_name, artist_code | varchar | snapshots, so the document survives a rename/soft-delete |
| status | enum `draft`,`finalized` | the only stored lifecycle state |
| active_slot | tinyint NULL | `1` while the row exists as an active settlement; **UNIQUE (artist_id, event_id, active_slot)** ⇒ the database refuses a second active settlement for the same seller+event (FR-013). A deleted draft is hard-deleted, so the slot frees itself. |
| pos_units | int | Σ qty of source rows |
| pos_revenue | decimal(14,2) | Σ POS source rows |
| preorder_units | int | Σ qty of pre-order source rows (full quantity of pre-orders with collected > 0) |
| preorder_revenue | decimal(14,2) | Σ pre-order source rows (collected portion, rounded per row) |
| revenue_total | decimal(14,2) | `pos_revenue + preorder_revenue` (pre-order part uses the collected fraction **capped at 1**) |
| adjustments_total | decimal(14,2) | Σ signed adjustment amounts (negative = reduces what the seller receives) |
| net_amount | decimal(14,2) signed | `revenue_total + adjustments_total`; ≥ 0 store pays seller, < 0 seller pays store |
| paid_amount | decimal(14,2) ≥ 0 | **cache**, rewritten from `seller_settlement_payments` in the same transaction as every payment change |
| memo_preorder_uncollected | decimal(14,2) | informational only |
| memo_cogs_pos, memo_cogs_preorder | decimal(14,2) | informational only (cost of goods, proportioned for pre-orders by the same fraction) |
| finalized_at, finalized_by | datetime / FK users NULL | |
| created_by | FK users | |
| created_at, updated_at | | |

Raw CHECK constraints (MySQL-only, like the repo's other CHECKs — the DB itself guards the balance):

- `chk_sset_revenue`: `revenue_total = pos_revenue + preorder_revenue`
- `chk_sset_net`: `net_amount = revenue_total + adjustments_total`
- `chk_sset_paid_nonneg`: `paid_amount >= 0`
- `chk_sset_paid_cap`: `paid_amount <= ABS(net_amount)`
- `chk_sset_final`: `status = 'draft' OR (number IS NOT NULL AND finalized_at IS NOT NULL)`

Indexes: `(event_id, artist_id)`, `(status)`, unique `number`, the unique on `active_slot` above.

## `seller_settlement_sources` — frozen per-transaction detail (traceability, FR-008)

Child of `seller_settlements`; **no `data_mode` column** (rides the parent via `CloneRegistry 'via'`, same as `purchase_order_items`).

| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| settlement_id | FK cascade | |
| source_type | enum `order`,`preorder` | |
| source_id | bigint | **no FK** — the snapshot is the truth; the source may later be voided/cancelled |
| source_number | varchar(30) | `order_number` / `preorder_number` snapshot (no customer data, FR-023) |
| source_date | datetime | order `created_at` / pre-order `created_at` |
| units | int | Σ qty for this seller in that transaction |
| gross_amount | decimal(14,2) | Σ `line_total` (before the collected fraction) |
| amount | decimal(14,2) | recognised revenue, **rounded once** (POS = gross; pre-order = gross × collected/subtotal) |
| uncollected_amount | decimal(14,2) | pre-order only, memo |
| cost_amount | decimal(14,2) | memo (cost snapshot × qty [× fraction]) |

UNIQUE `(settlement_id, source_type, source_id)`.

## `seller_settlement_adjustments`

| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| settlement_id | FK cascade | |
| kind | enum `deduction`,`adjustment` | |
| label | varchar(120) | |
| reason | text | required (FR-007) |
| basis | varchar(160) NULL | allocation basis of a charged cost (FR-011), e.g. "40% of event cost by sales share" |
| amount | decimal(14,2) | **signed effect on net**; a `deduction` is stored negative (UI takes a positive number), an `adjustment` may be ±, never 0 |
| post_finalization | boolean | true when added after finalize |
| created_by | FK users | |
| created_at, updated_at | | |

CHECK `chk_sadj_amount`: `amount <> 0 AND (kind <> 'deduction' OR amount < 0)`.

## `seller_settlement_acknowledgements` (append-only)

| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| settlement_id | FK cascade | |
| method | enum `signature`,`typed`,`external` | `external` is labelled on the proof as "acknowledged outside the system" |
| signer_name | varchar(120) | required for `signature`/`typed`; optional for `external` |
| signature_path | varchar NULL | private `local` disk, `settlement-signatures/`; only for `signature` |
| note | varchar(255) NULL | required for `external` |
| acknowledged_at | datetime | server time |
| recorded_by | FK users | |
| superseded_at | datetime NULL | set when replaced, or when the settlement is reopened/adjusted |
| superseded_reason | enum `replaced`,`reopened`,`adjusted` NULL | |

"Current" acknowledgement = the row with `superseded_at IS NULL` (at most one; enforced in the service under the settlement row lock). Files of superseded rows are kept for audit, never served to non-authorised roles.

## `seller_settlement_payments`

| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| settlement_id | FK cascade | |
| client_ref | char(36) UNIQUE | idempotency; SPA generates one per dialog open and reuses it on retry |
| amount | decimal(14,2) | `> 0` (CHECK); direction comes from the settlement's net sign |
| paid_at | date | |
| method | enum `cash`,`bank_transfer`,`ewallet`,`other` | |
| reference | varchar(120) NULL | |
| notes | varchar(255) NULL | |
| proof_path | varchar NULL | private `local` disk, `settlement-proofs/` |
| recorded_by | FK users | |
| created_at, updated_at | | |

## Derived values (never stored)

- `direction` = `store_pays_seller` if `net_amount > 0`, `seller_pays_store` if `< 0`, `none` if `= 0`.
- `balance` = `ABS(net_amount) − paid_amount`.
- `payment_status` = `net_zero` (net = 0) / `unpaid` (paid = 0) / `partially_paid` / `settled` (balance = 0).
- `acknowledged` = a current acknowledgement exists.
- `display_status` = first match of: `draft` · `finalized` (not acknowledged) · `acknowledged` (balance > 0 and paid = 0) · `partially_paid` · `settled` (acknowledged **and** balance = 0, or net zero and acknowledged).
- `source_changed` (finalized only, read-time) = live `SellerSettlementBuilder` totals compared with frozen `pos_revenue` / `preorder_revenue`; returns `{pos_delta, preorder_delta}` or `null`.

## Reconciliation (FR-009/FR-010)

Computed independently of the stored totals by `SettlementReconciliation::for($settlement)` from rows, in integer cents:

```
sources_sum      = Σ seller_settlement_sources.amount
adjustments_sum  = Σ seller_settlement_adjustments.amount
expected_net     = sources_sum + adjustments_sum
difference       = net_amount − expected_net            // must be 0.00
payment_check    = (paid_amount) − Σ seller_settlement_payments.amount   // must be 0.00
```

Also returned: `pos_check = pos_revenue − Σ(source rows type order)` and `preorder_check` likewise. `finalize` refuses unless every check is exactly `0.00` (409 `reconciliation_mismatch`, body names the failing figure). Because the same cents are summed once and rounded per row, the check passes by construction; it exists to catch bugs/tampering and to **show** the user the proof.

## State transitions

```
            create               finalize (reconciled, non-empty)
 (none) ─────────────► draft ───────────────────────────────────► finalized
                        ▲  │ refresh sources / add-edit-remove adjustments      │
                        │  └ delete (hard)                                       │
                        │                                                        │ acknowledge  (adds current ack)
                        │ reopen (no payments; supersedes ack, reason=reopened)  │ add payment (≤ balance)
                        └────────────────────────────────────────────────────────┤ post-finalization adjustment
                                                                                  │   (supersedes ack, reason=adjusted;
                                                                                  │    refused if net would drop below paid
                                                                                  │    or flip direction while paid > 0)
```

No settlement is ever deleted after its first finalize (even if reopened to draft). Payments can be deleted (owner/admin, audited) unless that would leave the cache inconsistent — the cache is recomputed in the same transaction.

## Validation rules (server-side only; client values for money are never trusted)

- Create: `event_id` and `artist_id` exist in the active mode; no active settlement for the pair (409 `settlement_exists` with the existing id).
- Sources are always rebuilt by the server (`SellerSettlementBuilder`); no endpoint accepts a revenue number.
- Adjustment: label ≤ 120, reason required, basis ≤ 160, amount 0 < |x| ≤ 99 999 999 999.99, 2 dp max.
- Acknowledge: `signature` needs `signer_name` + PNG ≤ 512 KB; `typed` needs `signer_name`; `external` needs `note`.
- Payment: amount > 0 and ≤ balance; settlement must be finalized and not net-zero; `client_ref` UUID; proof optional JPEG/PNG ≤ 5 MB (same cap as payment proofs; type re-checked from file content).

## Touches on existing data

- `MenuKeys::ALL` gains `'settlements' => 'Settlement'`; one data migration appends the key to the default **Owner** and **Admin** roles (template: `2026_10_12_000001_add_purchase_orders_menu_key_to_default_roles.php`). Cashier/Inventory unchanged.
- `EventController::destroy` and `ArtistController::destroy` gain a delete guard (409) when a `seller_settlements` row references them.
- `CloneRegistry::tables()` gains the five tables (parent first; children via `via`), `-DM` suffix on `number`, new UUID on `client_ref`, `files` for the two private paths.
- `ReportController::preorderRecognizedRevenueBase()` delegates to the new `App\Support\PreorderRecognizedRevenue` (same SQL, same results — covered by the existing report tests plus a parity test).
- `GET /settings/features` gains `store_logo_url`.
- `artist_settlements`, `payments`, `orders`, `preorders` and their services are **not** modified.
