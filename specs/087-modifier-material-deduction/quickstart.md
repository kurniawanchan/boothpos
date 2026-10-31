# Quickstart / Verifikasi: 087

**Klasifikasi**: INTERNAL

## Tes backend (di kontainer, WAJIB dengan flag aman)

```bash
docker compose exec -e APP_ENV=testing -e DB_DATABASE=warkop_wjm_test app \
  php artisan test -e CACHE_STORE=array -e SESSION_DRIVER=array --filter=ModifierMaterial
```

Tes yang diharapkan ada (`tests/Feature/`):

| File | Membuktikan |
|---|---|
| `ModifierOptionMaterialMappingTest` | simpan/ganti/biarkan pemetaan; 422 untuk qty ≤ 0, duplikat, bahan nonaktif/beda mode; log `modifier_materials_changed`; `used_by_modifier_options`; 409 hapus bahan terpakai |
| `ModifierMaterialConsumptionTest` | checkout ritel memotong Σ(qty × porsi); dua menu satu bahan = satu saldo; pilihan tanpa pemetaan = tanpa movement; outlet transaksi; DEMO tidak menyentuh LIVE; biaya bahan masuk `cost_price` dan `total_cost` |
| `TabMaterialConsumptionTest` | tambah/kurangi/ganti pilihan/hapus/pindah/gabung di tab terbuka = 0 movement; settle lunas = potong isi akhir; settle sebagian = 0; split = pesanan baru memotong sendiri, sisa tab saat lunas |
| `MaterialWasteTest` | batal baris `waste` = movement `waste` sebelum baris dihapus; `return` = tidak ada; voidTab/cancelTab = `waste` hanya untuk baris dimasak |
| `MaterialRestoreTest` | void dan refund = `return` sebesar net per baris; pemetaan diubah setelah jual tidak memengaruhi; refund kedua ditolak tanpa movement; `waste` tidak dikembalikan |
| `MaterialNegativeStockTest` | saldo menjadi minus untuk `sale`/`waste` dan `material_warnings` terisi; koreksi/transfer keluar yang menghasilkan minus tetap 422; pembelian dari minus menambah dengan benar; `stock_status=negative` |
| `MaterialConsumptionIdempotencyTest` | retry `local_ref`/`client_ref` = satu potongan; penjaga movement `sale` ganda |
| `MaterialMovementsEndpointTest` | paginasi, saring jenis/outlet/tanggal, `OutletFilter`, rujukan nomor pesanan, gerbang |

Konkurensi (SC-003): satu tes yang menjalankan dua checkout pada bahan sama lewat dua koneksi DB tidak dapat diandalkan di PHPUnit; buktikan lewat (a) urutan kunci teruji unit (`material_id ASC`), dan (b) skrip manual di bawah.

## Tes frontend

```bash
npm test -- modifierMaterials materialsNegative
```

## Verifikasi manual (browser nyata, konstitusi II)

1. Login `owner`. Bahan: buat **Indomie Soto** (bungkus, stok outlet A = 5), **Telur** (butir, 1), **Cabai Bubuk** (gram, 100).
2. Restaurant Setup → Grup modifier → **Rasa Kuah**: SOTO → Indomie Soto ×1. **Topping**: Telur → Telur ×1. **Level Pedas**: Extra Pedas → Cabai Bubuk ×2. Simpan, buka lagi: pemetaan tampil.
3. Bahan → Telur → "Dipakai oleh" menampilkan Topping → Telur.
4. POS web, outlet A, ritel: Indomie Kuah ×2 (SOTO, Extra Pedas, Telur), bayar tunai. Harapan: toast peringatan Telur (1 → −1); Indomie Soto 3, Cabai 96, Telur −1; konsol bersih.
5. Bahan → saringan "Minus" menampilkan Telur.
6. Tab meja: tambah Indomie Kuah (SOTO) ×2, kurangi ×1, ganti ke AYAM BAWANG: stok bahan tidak berubah. Lunasi: hanya bahan AYAM BAWANG −1.
7. Refund pesanan langkah 4: Indomie Soto 5, Cabai 100, Telur 1.
8. Tablet (bila tersedia): ulangi langkah 6 dari aplikasi; pastikan pelunasan berhasil dan aplikasi tidak galat karena `material_warnings`.
9. Konkurensi manual: dua tab browser checkout bersamaan pada Telur; saldo akhir = awal − 2, dua movement.

Catat hasil di `specs/087-modifier-material-deduction/evidence.md`.
