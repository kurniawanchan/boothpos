# Kontrak CLI: pengisian katalog WJM

**Klasifikasi**: INTERNAL

Tidak ada rute HTTP baru; `docs/openapi-pos-mvp.yaml` tidak berubah. Antarmuka fitur ini hanya CLI di server.

## 1. Perintah

```text
php artisan app:seed-wjm-catalog
    [--mode=both]      live | demo | both. Bawaan: both (LIVE diproses lebih dulu)
    [--outlet=KODE]    kode outlet tujuan; hanya sah bersama --mode=live atau --mode=demo
    [--dry-run]        pratinjau: jalur yang sama lalu rollBack; tidak menulis foto; boleh pada katalog draf
    [--catalog=PATH]   berkas katalog. Bawaan: config('seeding.wjm_catalog_path'),
                       jatuh ke database/seeders/data/wjm-odoo/catalog.json
    [--force]          lewati konfirmasi saat APP_ENV=production (tidak berlaku untuk --dry-run)
```

### Urutan pemeriksaan

1. **Opsi**: `--mode` di luar `live|demo|both`, atau `--outlet` bersama `--mode=both` → galat, kode keluar 1, tanpa perubahan.
2. **Katalog**: dimuat dan divalidasi (data-model §1). Masalah struktur → semua mode `refused`, kode keluar 1. Status `draft` → `refused`, kecuali `--dry-run`, yang tetap berjalan dengan peringatan.
3. **Konfirmasi**: hanya bila `APP_ENV=production`, bukan `--dry-run`, dan tanpa `--force`. Ditolak → kode keluar 1, tanpa perubahan.
4. **Per mode** (LIVE lalu DEMO): resolusi outlet (research D5), jalankan satu transaksi, lalu cetak laporan mode itu. Kegagalan satu mode tidak membatalkan mode yang sudah `applied`.

### Kode keluar

| Kode | Arti |
|---|---|
| 0 | Semua mode yang diminta berstatus `applied` (atau `previewed` saat `--dry-run`) |
| 1 | Selain itu: opsi salah, katalog bermasalah atau draf, konfirmasi ditolak, ada mode `refused` atau `failed` |

## 2. Seeder

```text
php artisan db:seed --class=WjmCatalogSeeder        (APP_ENV=production: tambahkan --force milik db:seed)
```

Setara dengan `app:seed-wjm-catalog --mode=both` dengan katalog bawaan, tanpa pratinjau dan tanpa konfirmasi tambahan. Laporan dicetak lewat `$this->command`. Bila ada mode yang tidak `applied`, seeder melempar `RuntimeException` setelah mencetak laporan, sehingga `db:seed` keluar dengan kode ≠ 0. Seeder ini **tidak** dipanggil `DatabaseSeeder` (FR-001).

## 3. Bentuk laporan (contoh generik)

```text
Mode LIVE — outlet "Warkop WJM 3" (WJM), dibuat baru — status: applied

  Jenis           Dibuat   Sudah ada
  Stasiun              2           0
  Kategori            10           0
  Item                79           0
  Varian             111           0
  Grup pilihan         1           0
  Foto                61           0

  Dikecualikan (4): Partial Payment — produk teknis Odoo; …
  Tanpa foto (18): 6 terpotong di ekspor XLSX, 12 kosong — …
  Harga 0 (1): …
  Kit/BoM Odoo dijual sebagai item biasa (7): …
  Pertanggungjawaban sumber: produk 80 = 79 dibuat + 0 sudah ada + 1 dikecualikan; kategori 10 = 10 + 0; nilai atribut 26 = …

Mode DEMO — outlet "Warkop WJM 3" (WJ3) — status: applied
  …
  Ditarik dari menu outlet ini (32): …

Selesai: LIVE applied, DEMO applied.
```

- Saat `--dry-run`: status `previewed`, kolom "Dibuat" berarti "akan dibuat", dan baris pembuka bertuliskan "PRATINJAU — tidak ada yang disimpan".
- Saat `refused`: daftar masalah dicetak sebagai satu masalah per baris.
- Teks laporan berbahasa Indonesia (Konstitusi III).

## 4. Log aktivitas (per mode `applied`)

| Kolom | Nilai |
|---|---|
| `user_id` | `null` (CLI) |
| `action` | `catalog_seeded` |
| `entity_type` / `entity_id` | `Outlet` / id outlet tujuan |
| `description` | "Katalog WJM (Odoo) diisi ke outlet {kode} oleh app:seed-wjm-catalog." |
| `new_values` | jumlah dibuat, sudah ada, dan dikecualikan per jenis, ditambah `catalog_exported_on` dan `approved_on` |
