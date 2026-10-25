# Kontrak CLI: pengisian katalog WJM 2

**Klasifikasi**: INTERNAL

Perintah dan bentuk laporan sama dengan 068 (`specs/068-odoo-catalog-seeder/contracts/cli.md`). Dokumen ini hanya mencatat **perbedaannya**.

## 1. Pemanggilan

```text
php artisan app:seed-wjm-catalog --catalog=database/seeders/data/wjm2-odoo/catalog.json
    [--mode=both] [--outlet=KODE] [--dry-run] [--force]          (opsi seperti 068)

php artisan db:seed --class=Wjm2CatalogSeeder                    (setara --mode=both dengan katalog WJM 2)
```

`Wjm2CatalogSeeder` adalah seeder tipis seperti `WjmCatalogSeeder`, hanya berbeda jalur katalognya. Seeder ini tidak dipanggil `DatabaseSeeder`.

## 2. Resolusi outlet (`outlet.create_when = demo_missing`)

| Mode | Outlet bernama `outlet.name` | Hasil |
|---|---|---|
| LIVE | tepat satu aktif | dipakai |
| LIVE | tidak ada | **ditolak**: "Outlet \"Warkop WJM 2\" tidak ada di mode LIVE. Buat outlet itu lebih dulu lewat layar Outlet." |
| DEMO | tepat satu aktif | dipakai |
| DEMO | tidak ada | dibuat dengan kode bebas pertama dari `outlet.codes`, lalu `WJA…WJ9`; lisensi multi-outlet diperiksa |
| apa pun | lebih dari satu | ditolak; pilih dengan `--outlet=KODE` bersama satu `--mode` |

Satu-satunya outlet aktif yang namanya berbeda **tidak pernah** dipakai, karena itu bisa mengisi katalog ke outlet WJM 3 (research D8). `--outlet=KODE` tetap mengesampingkan nama.

## 3. Stasiun

| Keadaan outlet tujuan | Hasil |
|---|---|
| belum punya stasiun | buat dua stasiun dengan nama pertama tiap peran di `stations` |
| setiap peran cocok dengan satu stasiun (menurut daftar nama) | dipakai, dilaporkan "sudah ada" |
| sudah punya stasiun, tetapi sebuah peran tidak cocok | mode **ditolak**: "Stasiun untuk peran Bar tidak dikenali di outlet WJD. Stasiun yang ada: …. Tambahkan namanya ke baris **Stasiun** di tabel pemetaan." |

## 4. Tambahan di laporan

```text
Mode LIVE — outlet "Warkop WJM 2" (WJD) — status: applied
  Jenis           Dibuat   Sudah ada
  Stasiun              2           0
  Kategori             4           6        ← kategori bersama dihitung "sudah ada"
  Item                80           0
  Varian             141           0
  Foto                11           0
  Stok awal          141                    ← varian yang mendapat gerakan "initial"
  …
  Ditarik dari menu outlet ini (79): ABC Klepon; …         ← kini juga di LIVE bila withdraw_others.live
  Kategori dikecualikan (n): NASI — tanpa item di ekspor; …
  Nilai atribut dari tabel pemetaan (n): JASJUS: …; …
```

- Item milik outlet lain dengan nama sama **tidak** dihitung "sudah ada" (research D7).
- Log aktivitas `catalog_seeded` sama dengan 068, ditambah `new_values.withdrawn` (jumlah) dan `new_values.catalog` (`wjm2-odoo`).

## 5. Kode keluar

Tidak berubah dari 068.
