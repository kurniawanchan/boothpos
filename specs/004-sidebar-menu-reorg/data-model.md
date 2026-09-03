# Phase 1 Data Model

No database entities — this feature is UI-only (confirmed in research.md
Decision 4). The "model" here is client-side UI state shape.

## Sidebar navigation entry (extended)

Existing shape in `AppSidebar.vue`'s `NAV_DEFS`, unchanged:

```js
{ name, label, icon, menuKey }                       // leaf item
{ key, label, icon, children: [{ name, label, menuKey }] }  // group
```

New group entries added (same shape as the existing `settings-group`):

```js
{
  key: 'inventaris-group',
  label: 'nav.inventaris_group',
  icon: 'ph-cube',
  children: [
    { name: 'categories', label: 'nav.categories', menuKey: 'categories' },
    { name: 'products', label: 'nav.products', menuKey: 'products' },
    { name: 'stock', label: 'nav.stock', menuKey: 'stock' },
  ],
}
{
  key: 'purchase-group',
  label: 'nav.purchase_group',
  icon: 'ph-shopping-bag',
  children: [
    { name: 'vendors', label: 'nav.vendors', menuKey: 'vendors' },
    { name: 'materials', label: 'nav.materials', menuKey: 'materials' },
  ],
}
```

`menuKey` values are unchanged from today — this is purely a
presentation regrouping (FR-004).

## Product filter state (Products page + POS)

Both screens gain/replace with the same two independent refs:

```js
const selectedArtistId = ref(null);   // null = "All Artists"
const selectedCategoryId = ref(null); // null = "All Categories" (POS already has this)
```

Sent to `GET /products` as `artist_id`/`category_id` query params only
when non-null (existing `when()`-chain server-side semantics: both
present = AND, per spec.md FR-008 — already true today, no server change).

## Product card (POS) — one field added

`buildProductCards()`'s returned card object gains `image_url` (passed
through unchanged from the `GET /products` row, which `ProductResource`
already provides):

```js
{ product_id, name, artist_name, category_code, variant_count,
  min_price, max_price, total_stock, out_of_stock, variants,
  image_url }   // NEW
```
