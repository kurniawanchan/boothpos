# Quickstart: Verify Product Segment Length (6 letters)

## Automated (run in the app container with test overrides)

```bash
docker compose exec -e APP_ENV=testing -e DB_DATABASE=boothpos_test app php artisan test \
  --filter='ProductSegmentTest|ProductTest|ProductSegmentChangeTest|VariantSkuTest|MasterDataImportTest|CodeAllocatorTest|LiveToDemoClonerTest'
npm test   # Vitest, on the host (no backend needed)
```

Expected: segment `ABCDEF` accepted, `ABCDEFG` refused; `SH-K` accepted, `-SH`, `SH-`, `S--K` and `SH K` refused; code 13 accepted; SKU 20 accepted and 21 refused; a segment change with a hyphen regenerates SKUs whose number is still the last group; segment change refused (nothing written) when a regenerated SKU would exceed 20; import accepts a hyphenated segment; the DEMO clone keeps `SH-K` whole.

## Migration

```bash
docker compose exec -e APP_ENV=testing -e DB_DATABASE=boothpos_test app php artisan migrate:fresh --seed
docker compose exec -e APP_ENV=testing -e DB_DATABASE=boothpos_test app php artisan migrate:rollback --step=1   # only safe while no row exceeds the old widths
docker compose exec -e APP_ENV=testing -e DB_DATABASE=boothpos_test app php artisan migrate
```

Never run these against the dev database (`boothpos`) without reading CLAUDE.md first.

## Manual (browser, DEMO mode)

1. Products → edit a product → segment `SH-K` (hyphen). The segment field accepts up to 6 characters, not 7.
2. Save. Code shows `…-SHOP`; a new variant's SKU shows `…-SHOP-1`.
3. Change the segment to `ABCDEF`; the code is 13 characters, and a variant with a 6-digit number shows a 20-character SKU.
4. Enter a sequence that would make the SKU 21 characters; the message appears on the sequence field and nothing saves.
5. Change the segment to one that would push an existing SKU past 20; the save is refused on the segment field.
