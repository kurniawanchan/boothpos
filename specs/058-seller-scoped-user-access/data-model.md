# Data Model: Seller-Scoped User Access

INTERNAL

## Schema changes (one migration, `2026_11_24_000001_...`, after `2026_11_23_000001`)
| Table | Column | Detail |
|---|---|---|
| users | `artist_id` | nullable FK → artists, restrictOnDelete, indexed. NULL = no scope (all existing rows) |
| customers | `artist_id` | nullable FK → artists, nullOnDelete. Origin seller stamped when a scoped user creates the customer |

No backfill. Migration filename date prefix is load-bearing (do not rename).

## Entities
- **User**: gains `artist()` relation; `isSellerScoped()`; payload carries `artist {id,name,code,is_active}`.
- **Artist**: `users()` relation; delete guard #3 (linked users, 409).
- **SellerGate** (support class, not stored): `current(): ?int`; `applyToItems(Builder|QueryBuilder, string $col)`; `visibleOrderIds()`.

## Scope rules per model (when a seller is set)
| Model | Rule |
|---|---|
| Artist | `id = mine` |
| Product | `artist_id = mine` |
| ProductVariant, StockMovement, BOM line | product's `artist_id = mine` (incl. soft-deleted products) |
| Order, Preorder (header) | EXISTS item with `artist_id = mine` |
| OrderItem, PreorderItem (relation) | unscoped (whole order shown); raw/report use `applyToItems` |
| Payment, Shipment, proofs | follow the visible order |
| Customer | linked to a visible order/pre-order OR `artist_id = mine` |
| PurchaseOrder | `artist_id = mine` |
| SellerSettlement (+children), ArtistSettlement | `artist_id = mine` |
| PosDraft | own user (unchanged); lines of other sellers rejected on open |
| Event, Category, PaymentChannel | unscoped reference data |

## Validation
- `artist_id` on user create/update: nullable, exists in `artists` (not soft-deleted, current `data_mode`: `Rule::exists` bypasses the scope, so filter explicitly).
- Role Owner: `artist_id` must be null (422 `seller.owner_not_assignable`); role changed to Owner clears `artist_id`; `SellerGate` ignores `artist_id` for Owner.
- Only a user holding the `users` menu with edit action may change it; logged (`user_seller_changed`) inside the transaction.

## State
Seller link: unset ⇄ set(A) ⇄ set(B). Takes effect on the next request (no token reissue).
