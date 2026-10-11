**Classification**: INTERNAL

# Contract: price changes, menu changes, releases, approvals, reasons, availability poll

All under `/api/v1`, `auth:sanctum`. Permissions are action-level (`PermissionCatalog`), enforced server-side; menu key `products` is also required. Release and approval routes answer `404`-free but report `release_enabled: false` and refuse writes with `409 release_disabled` when `menu_release_enabled` is off, except where noted.

## Bulk price change

| Route | Purpose | Permission |
|---|---|---|
| `POST /price-changes/preview` | body `{category_ids[], outlet_ids[]? (B), type: percent|amount, value, rounding: none|500, effective_from}` → `{items: [{product, variant, old_price, new_price}], count, warnings[]}` computed set-based, nothing stored | `menu.price.bulk` |
| `POST /price-changes` | same body → recomputes server-side, validates, applies all-or-nothing: immediate mode applies and writes `price_history`; release mode creates one pending `menu_change` per variant (over-threshold ones `awaiting_approval`) under one `price_change_batch` → `{batch_id, applied, pending, awaiting_approval}` | `menu.price.bulk` |

Validation (`422`, field named): no category; empty/zero value; `effective_from` in the past; a computed price below 0 or equal to 0; two results that would collapse a variant to zero. A scope that matches nothing → `{count: 0}` and submission refused (`422`).

## Menu changes and releases (release mode)

| Route | Purpose | Permission |
|---|---|---|
| `GET /menu/changes?status=&kind=` | pending/awaiting/approved changes with a one-line summary | `products` menu |
| `POST /menu/changes/{id}/cancel` | cancel a non-released change (the owner of the change or `menu.release.publish`) | — |
| `GET /menu/releases` · `GET /menu/releases/{id}` | version list; detail lists exactly the contained changes, requester, approver, releaser, times (**B**: per-outlet delivery state) | `products` menu |
| `POST /menu/releases` | body `{publish_at?: ISO (null = now), note?, change_ids?: [] (default = all approved/pending-eligible), outlet_ids? (B)}` → creates the next version; includes only changes that are `pending` (not needing approval) or `approved`; `409 changes_not_ready` lists any `awaiting_approval` ones chosen; applies at once when `publish_at` is null or due | `menu.release.publish` |
| `POST /menu/releases/{id}/cancel` | cancel a **scheduled** release | `menu.release.publish` |
| **B** `POST /menu/releases/{id}/retry` | retry failed outlets | `menu.release.publish` |

Applying a release writes the new values into the live tables inside one transaction (`PriceService`, lifecycle service, link tables), marks the changes `released`, stores `price_history` rows with `source: release`, and writes the activity log. `publishDue()` publishes scheduled releases whose time has come, called lazily (research D3).

## Approvals

| Route | Purpose | Permission |
|---|---|---|
| `GET /approvals?kind=menu_price&status=pending` | list with requester, variant, old → new price, `difference_pct`, threshold | `menu.price.approve` |
| `POST /approvals/{id}/approve` | approves; `403`/`409 self_approval` when the caller is the requester | `menu.price.approve` |
| `POST /approvals/{id}/reject` | body `{reason_code_id, note?}` | `menu.price.approve` |

Approved changes become `approved` (eligible for a release); rejected ones stay visible with who/why and cannot be released. Decisions are logged in the same transaction.

## Reasons

| Route | Purpose | Permission |
|---|---|---|
| `GET /reason-codes?category=` | active codes with labels in the caller's language | any logged-in user |
| `POST /reason-codes` · `PUT /reason-codes/{id}` | create/edit/deactivate (never delete a used code) | `settings` menu |

## Availability poll

`GET /menu/availability?since=<etag>` → `{etag, menu_version, sold_out: [{product_id, variant_ids[], reason_code, expires_at}], discontinued_ids: []}`; `304` when the etag matches. Cached ≤ 2 s server-side; used by the POS and floor screens every 5 s; it is a convenience — the server enforces availability again at order time (`409 item_unavailable`). **B**: scoped to the terminal's outlet.

## Settings (existing bulk `PUT /settings`)

`menu_release_enabled` (bool), `price_approval_threshold_pct` (0–100), `price_rounding_default` (`none|500`); owner/admin; validated like the 042 keys; exposed to every role through `GET /settings/features` as `menu_release_enabled` (the UI needs it to show/hide the banner).
