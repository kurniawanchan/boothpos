**Classification: INTERNAL**

# Data Model: Transaction Reference — Multiple Images (055)

## `order_reference_images` (NEW)

| Column | Type | Rules |
|---|---|---|
| `id` | bigint PK | |
| `order_id` | FK → `orders.id`, `cascadeOnDelete` | required; index `(order_id, position)` |
| `path` | varchar(255) | required; `order-references/<uuid>.<jpg\|png\|webp>` on the private `local` disk; never the client's file name |
| `mime` | varchar(50) | required; one of `image/jpeg`, `image/png`, `image/webp`, sniffed from CONTENT |
| `size_bytes` | unsigned int, nullable | NULL only for rows migrated from 054 |
| `position` | unsigned smallint | `max(position)+1` within the order at insert time; gaps allowed; never renumbered |
| `uploaded_by` | FK → `users.id`, nullable, `nullOnDelete` | who added this image |
| `created_at` / `updated_at` | timestamps | |

- **No `data_mode` column**, no `HasDataMode` trait: the image follows its order (CloneRegistry `via => 'orders.order_id'`). Always reached through a mode-scoped `Order`.
- **Invariant**: at most `OrderReferenceService::MAX_IMAGES` (10) rows per `order_id` — enforced in the service under `Order::lockForUpdate()`, not by a DB constraint (a count limit cannot be a CHECK).
- **Only writer**: `OrderReferenceService` (`save()`, `delete()`). Rows are inserted/deleted, never updated.
- Model: `App\Models\OrderReferenceImage` (`belongsTo Order`, `belongsTo User uploader`). `Order::referenceImages()` = `hasMany(...)->orderBy('position')->orderBy('id')`.

## `orders` (CHANGED)

| Column | Change |
|---|---|
| `reference_note` | unchanged (≤ 1000 chars) |
| `reference_image_path` | **dropped** — moved to `order_reference_images` |
| `reference_image_mime` | **dropped** — moved to `order_reference_images` |
| `reference_updated_by` / `reference_updated_at` | unchanged; now also stamped when only images change |

`Order::hasReference()` = note is not null OR image count > 0 (count from `reference_images_count` aggregate, loaded relation, or one query — in that order).

## Migration `2026_11_22_000001_create_order_reference_images_table`

1. Create `order_reference_images`.
2. `DB::table('orders')->whereNotNull('reference_image_path')` (BOTH data modes, deliberately not Eloquent) → insert one row each: `position = 1`, `path`, `mime`, `size_bytes = NULL`, `uploaded_by = reference_updated_by`, `created_at = updated_at = reference_updated_at ?? now()`.
3. Drop `orders.reference_image_path`, `orders.reference_image_mime`.
4. `down()`: re-add both columns, copy back the lowest-position image per order, drop the table (extra images are lost on rollback; files are NOT deleted).

Files are not moved or opened; only the pointers move.

## Reference lifecycle (per sale)

```
none ──save(note and/or ≥1 image)──▶ present
present ──save(add/remove images, change note)──▶ present   (result must keep note or ≥1 image)
present ──save(removes everything)──▶ 422 (use DELETE)
present ──delete()──▶ none   (all image rows + files removed, note cleared)
```

Removing the last image of a note-less reference through the dialog is sent as `DELETE` by the SPA (FR-009, US3-2).

## Save algorithm (`OrderReferenceService::save`)

Inside one `DB::transaction`, holding `Order::lockForUpdate()`:

1. Authorise (`mayEdit`) — 403 otherwise.
2. `removeIds` = requested ids ∩ ids of THIS order's stored images (others and already-gone ids are ignored silently).
3. `remaining = stored − |removeIds|`; if `remaining + |newFiles| > MAX_IMAGES` → 409 `reference_image_limit` `{max, remaining: MAX_IMAGES − remaining}`.
4. For each new file: sniff MIME (422 `images.N` if not accepted), `storeAs(order-references, uuid.ext, local)`, remember path; insert row at next position.
5. Delete rows in `removeIds`; remember their paths.
6. Resolve note (`note` key present ⇒ trimmed/blank→NULL; absent ⇒ unchanged).
7. If note is NULL and no images remain → 422 `reference_empty`.
8. If nothing changed → return without writes or log.
9. Stamp `reference_updated_by/at`, write ONE `ActivityLogger` row.

After commit: delete the removed files. On any exception: delete the files written in step 4, rethrow.

## Payload: reference block (single presenter, `OrderReferenceService::present`)

```json
{
  "note": "Pesanan via WA",
  "images": [
    { "id": 41, "mime": "image/jpeg", "size_bytes": 812345, "position": 1, "created_at": "2026-11-04T07:31:00+07:00" },
    { "id": 42, "mime": "image/png",  "size_bytes": 240011, "position": 2, "created_at": "2026-11-04T07:31:00+07:00" }
  ],
  "image_count": 2,
  "has_image": true,
  "max_images": 10,
  "updated_at": "2026-11-04T07:31:00+07:00",
  "updated_by_name": "kasir01",
  "can_edit": true
}
```

Same block in `GET/PUT/DELETE /orders/{id}/reference` and inside `OrderResource.reference` (`GET /orders/{id}`, which also feeds `TransactionItemsModal`). Sales list rows keep only `has_reference`.

## Activity log

| Action | old_values | new_values |
|---|---|---|
| `order_reference_updated` | `{note, image_count}` | `{note, image_count, images_added, images_removed}` |
| `order_reference_removed` | `{note, image_count}` | `{note: null, image_count: 0}` |

## CloneRegistry

```php
'orders' => [ … 'files' => [] ],             // reference_image_path entry removed
'order_reference_images' => [
    'fks' => ['order_id' => 'orders'],
    'files' => ['path' => 'local'],
    'required_files' => ['path'],             // a row whose file is missing is skipped, never pointed at a LIVE file
    'via' => 'orders.order_id',
],
```

Placed immediately after `orders` (FK order). `CloneRegistryDriftTest` must stay green.
