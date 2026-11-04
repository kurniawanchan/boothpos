**Classification: INTERNAL**

# Contract: Order reference API (055)

Supersedes the 054 contract for `/orders/{id}/reference*`. `docs/openapi-pos-mvp.yaml` MUST change in the same commit.

All endpoints: `auth:sanctum`, licence gate, `{order}` bound through the DEMO/LIVE-scoped `Order` model (other mode → 404).

## `GET /orders/{order}/reference`

- Gate: `canAccessMenu('sales')`, else 403.
- 200: reference block (see data-model.md), `images` ordered by `position, id`.

## `PUT /orders/{order}/reference` (multipart; send as `POST` + `_method=PUT`)

| Field | Type | Rule |
|---|---|---|
| `note` | string, optional | ≤ 1000. Changed only when the key is present; `''` clears. (unchanged from 054) |
| `images[]` | file[], optional | 1…10 per request; each `mimes:jpeg,png,webp`, ≤ 5120 KB; MIME re-sniffed from content |
| `remove_image_ids[]` | int[], optional | ids of images to remove; ids not belonging to this order or already gone are ignored |

At least one of the three must be present (422 `reference_empty` otherwise).

| Status | When | Body |
|---|---|---|
| 200 | saved (or nothing changed) | reference block |
| 403 | not owner/admin and not the sale's cashier | `{message}` |
| 409 | stored − removed + new > 10 | `{message, code: "reference_image_limit", max: 10, remaining: <int>}` |
| 422 | bad note length; > 10 files in one request; a file of wrong type/size (`errors["images.N"]`); result would be empty | `{message, errors}` |

Removed from 054: `image` (single file) and `remove_image` (bool).

## `DELETE /orders/{order}/reference`

Unchanged semantics: clears the note AND removes every image (rows + files). 200 → empty block (`images: []`, `image_count: 0`). 403 as above.

## `GET /orders/{order}/reference/images/{image}` (NEW; replaces `GET /orders/{order}/reference/image`)

- Route uses `scopeBindings()`: `{image}` must belong to `{order}`, else 404.
- Gate: `canAccessMenu('sales')`, else 403.
- 200: file bytes, `Content-Type: <stored mime>`, `Cache-Control: private, no-store`.
- 404: image id unknown / removed / file missing (`orders_payments.reference_image_missing`).
- Fetched by the SPA through axios as a blob (token required), never as a plain `<img src>`.

## `GET /orders/{order}` (OrderResource)

`reference` is the same block as above (single presenter), now including `images[]`, `image_count`, `max_images`.

## `GET /reports/sales` → `transactions[].has_reference`

Unchanged shape; now true when the note OR ≥ 1 image exists (computed with one `withCount`, no N+1).

## SPA API module (`resources/js/api/orders.js`)

| Function | Change |
|---|---|
| `getReference(orderId)` | unchanged |
| `getReferenceImageUrl(orderId, imageId)` | now takes `imageId`; returns a blob URL (caller revokes) |
| `saveReference(orderId, { note, images = [], removeImageIds = [] })` | appends `images[]` / `remove_image_ids[]`; `image`/`removeImage` removed |
| `deleteReference(orderId)` | unchanged |

## UI contract

- `TransactionItemsModal` reference card: note (as today) + `ReferenceThumbnails` (read-only) when `image_count > 0`; button "View/Edit reference" or "Add reference" as today. `data-testid="reference-thumbnail"` per thumbnail.
- `ReferenceThumbnails.vue` props: `orderId`, `images` (block's `images`), `removable` (bool), `markedForRemoval` (Set of ids, v-model); emits `toggle-remove(id)`. Fixed-size square thumbnails (token classes only), wrap to new rows, placeholder icon on fetch failure, concurrency 3, all blob URLs revoked on unmount / when `images` change.
- `ImageLightbox.vue`: new optional props `images: [{src, alt}]`, `index` (`v-model:index`); prev/next buttons + `n / total` + ←/→ only when `images.length ≥ 2`; existing `src` usage unchanged.
- `OrderReferenceModal.vue`: `<input type="file" multiple accept="image/jpeg,image/png,image/webp">`; per-file client check (type/size) lists refused files by name and keeps the valid ones; shows "{n} more image(s) allowed" from `max_images − (stored − marked + picked)`; existing images get a remove toggle (undo before save); Save sends ONE request; removing everything with no note routes to the existing delete confirmation.
- New locale keys (en + id): `sales_reference.images_title`, `sales_reference.add_images`, `sales_reference.remaining_slots`, `sales_reference.limit_reached`, `sales_reference.file_refused`, `sales_reference.image_unavailable`, `sales_reference.remove_image`, `sales_reference.undo_remove`, `common.previous` / `common.next` if missing. `localeKeys.test.js` must stay green.
