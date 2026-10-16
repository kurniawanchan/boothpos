# Quickstart: verify the segment change (rewritten 2026-10-16)

## 1. Backend tests (safe, MySQL test DB)

```bash
docker compose exec -T -e APP_ENV=testing -e DB_DATABASE=boothpos_test app php artisan test --filter='ProductTest|MasterDataImportTest|CodeAllocatorTest|LiveToDemoClonerTest'
```

## 2. Form check (browser, console checked)
1. Products → New. Artist SPF, category SH, name "Conan", segment `S`.
2. Preview shows `SPF-SH-S-001` (no `X`).
3. Save; the product list and the variant SKU show `SPF-SH-S-001`.
4. Segment `SPFX` (4 letters) → message on the field, not saved.

## 3. Import
Upload a products sheet with segment `S` → created with `SPF-SH-S-001`; segment `ABCD` → row error, nothing written.

## 4. Demo clone still works
`php artisan app:clone-live-to-demo` (default = simulasi, nothing written) with a LIVE product whose segment is 1–2 letters completes without a CloneException. `--force` writes; do not use it against the live dev DB without a backup.

## 5. Existing products
An existing product's code and SKUs are unchanged after the release.
