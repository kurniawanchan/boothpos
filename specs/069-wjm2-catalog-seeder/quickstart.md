# Quickstart: Pengisian katalog Odoo Warkop WJM 2

**Klasifikasi**: INTERNAL

Semua perintah tes memakai flag wajib (CLAUDE.md):

```bash
docker compose exec -e APP_ENV=testing -e DB_DATABASE=warkop_wjm_test app php artisan test -e CACHE_STORE=array -e SESSION_DRIVER=array --filter=…
```

## 1. Bangkitkan katalog (mesin pengembang)

```bash
python3 -I specs/068-odoo-catalog-seeder/tools/build_catalog.py \
  --src "/Users/chan/Downloads/WJM 2" \
  --mapping specs/069-wjm2-catalog-seeder/catalog-mapping.md \
  --out database/seeders/data/wjm2-odoo
```

Diharapkan saat DRAF: 80 item, 141 varian, 11 foto, dan daftar nama varian sementara bila masih ada `?`. Persetujuan: `draft`.

Bangkitkan ulang juga katalog WJM 3 (format 2) dan pastikan diff `catalog.json`-nya hanya menyentuh kunci format 2 (`format`, `outlet`, `stations`, `withdraw_others`, `values_from`, `source_name`):

```bash
python3 -I specs/068-odoo-catalog-seeder/tools/build_catalog.py --src "/Users/chan/Downloads/WJM 3" \
  --mapping specs/068-odoo-catalog-seeder/catalog-mapping.md --out database/seeders/data/wjm-odoo
```

## 2. Tes (peta FR/SC → tes)

| Tes | Membuktikan |
|---|---|
| `WjmCatalogFileTest` (diperluas) | format 2 sah; katalog WJM 2 dan WJM 3 lolos validasi; aturan `outlet`, `stations`, `withdraw_others`, `values_from`, kategori dikecualikan (FR-001–FR-004, FR-017, FR-018) |
| `WjmCatalogSecondOutletTest` (baru) | outlet B diisi di samping outlet A: item B milik B dan hanya dijual di B; nama sama lintas outlet tidak dianggap "sudah ada"; `station_id` item B = stasiun B; potret A identik kecuali penanda "dijual di B" (FR-005–FR-009, FR-019, SC-002, SC-006) |
| `WjmCatalogOutletResolutionTest` (baru) | `demo_missing`: LIVE tanpa outlet B ditolak; DEMO membuat B dengan kode dari `outlet.codes`; satu-satunya outlet aktif bernama lain tidak dipakai (FR-012) |
| `WjmCatalogStationRoleTest` (baru) | peran dicocokkan lewat alias; outlet tanpa stasiun dibuatkan; peran tak cocok → ditolak tanpa perubahan (research D6) |
| `WjmCatalogSeedRerunTest` (diperluas) | jalan ulang outlet B: 0 dibuat, stok tetap (FR-014, SC-004) |
| `PosCategoriesOutletTest` (baru) | `/pos/categories` per outlet shift (research D10, SC-008) |
| `KitchenTicketRoutingTest`/tes dapur yang ada | pesanan item B di outlet B → tiket di stasiun B |
| Suite penuh | termasuk `StockLedgerInvariantTest`, `OutletScopeRegistryTest`, dan semua tes 068 |

## 3. Potret WJM 3 sebelum pengisian (DB dev)

```bash
docker compose exec -T app php artisan app:backup
```

Lalu jalankan skrip potret (dari scratchpad, bukan dari repo). Skrip menghitung hash per tabel atas baris milik WJM 3 di kedua mode: produk/varian/riwayat harga/stok/gerakan stok/penanda menu untuk outlet WJM 3, kategori, stasiun, dan grup. Simpan hasilnya.

## 4. Pratinjau

```bash
docker compose exec -T app php artisan app:seed-wjm-catalog --catalog=database/seeders/data/wjm2-odoo/catalog.json --dry-run
```

- **LIVE**: outlet WJD dipakai; 2 stasiun baru; 80 item; 141 varian; 79 item WJM 3 akan ditarik dari menu WJD.
- **DEMO**: outlet "Warkop WJM 2" akan dibuat (kode `WJ2`); 79 item WJM 3 akan ditarik dari menu outlet baru itu.
- Jumlah baris DB tidak berubah.

## 5. Persetujuan pemilik (pemblokir)

Pemilik mengisi nama varian `?`, memeriksa usulan kategori dan Hot/Ice, lalu mengubah status menjadi `Disetujui` dengan peran dan tanggal. Bangkitkan ulang katalog (§1) dan jalankan tes berkas.

## 6. Isi sungguhan dan bukti (dengan persetujuan pengembang)

```bash
docker compose exec -T app php artisan app:seed-wjm-catalog --catalog=database/seeders/data/wjm2-odoo/catalog.json
```

1. Jalankan ulang skrip potret §3: semua hash WJM 3 sama, kecuali penanda menu untuk outlet WJD dan WJ2 (SC-002).
2. Setiap varian WJM 2 = 100 di outlet WJM 2, dengan satu gerakan `initial` (SC-003).
3. Peramban/tablet: POS outlet WJM 2 hanya menampilkan item WJM 2 dan kategorinya. POS outlet WJM 3 tidak berubah dan tanpa tab kosong baru. Pesan satu minuman dan satu makanan di WJM 2: tiket masuk ke stasiun Bar/Dapur WJM 2. Konsol bersih.
4. Jalan ulang: 0 dibuat, stok tetap (SC-004).
5. Catat di `evidence.md`.

## 7. Server pelanggan

Seperti 068 T043: hanya dengan otorisasi eksplisit pengembang, pratinjau dulu, cadangan ke lokasi eksternal, lalu `--force`.
