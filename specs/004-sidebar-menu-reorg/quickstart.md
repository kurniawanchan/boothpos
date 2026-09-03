# Quickstart

No migration/seed steps — frontend-only.

```bash
npm run build       # or npm run dev
php artisan serve
```

## Manual verification (Constitution II)

1. Log in as `owner`. Confirm sidebar order: Dashboard, POS, Cashier
   Session, Sales, Purchase (group), Inventaris (group), Pre-orders,
   Events, Artists, Customers, Reports, Pengaturan (group).
2. Expand "Inventaris" — confirm Kategori/Produk/Stok in that order, each
   navigating to its existing page unchanged.
3. Expand "Purchase" — confirm Vendor/Bahan Baku, each navigating
   unchanged.
4. Log in as `kasir01` — confirm "Inventaris"/"Purchase" groups are
   entirely absent (no menu_key change, same as today's behavior for
   those pages individually).
5. Open Produk — confirm each row shows a thumbnail or placeholder;
   confirm artist/category filters are now clickable chips including an
   "All" option each; click an artist chip + a category chip together and
   confirm the list narrows to both.
6. Open POS — confirm product cards show images/placeholders; confirm a
   new artist chip row exists alongside the existing category chip row;
   click "All Artists" after selecting one and confirm the full grid
   returns.

## Automated tests

```bash
npm test
```
