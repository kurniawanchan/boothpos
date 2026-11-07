# Quickstart: Verifikasi Produk Sebagai Bahan Resep

**Klasifikasi**: INTERNAL | **Date**: 2026-11-07

Skenario ini adalah bukti penerimaan. Hasil nyata dicatat di `evidence.md` (belum ada); sebelum dijalankan, semua butir berstatus **belum diverifikasi**.

## Menjalankan tes (aman untuk DB dev)

```bash
# Backend, DI DALAM kontainer: wajib semua -e (lihat CLAUDE.md, catatan 2026-10-16)
docker compose exec -e APP_ENV=testing -e DB_DATABASE=warkop_wjm_test -e CACHE_STORE=array -e SESSION_DRIVER=array \
  app php artisan test --filter='Ingredient|RecipeMenuStock|StockLedgerInvariant'
npm test            # Vitest
```

Jangan menjalankan dua proses tes pada DB tes yang sama (memori `one-test-process-per-test-db`).

## Data contoh

Outlet A. Produk: **Indomie Goreng** (bahan: ya, stok 100 bungkus), **Telur** (bahan: ya, 200 pcs), **Indomie Kuah** (bukan bahan, beresep). Resep Indomie Kuah, 1 porsi: 1 Indomie Goreng + 1 Telur.

## Skenario

| # | Langkah | Hasil yang diharapkan |
|---|---|---|
| 1 | Formulir Telur → nyalakan "Gunakan sebagai bahan", simpan | Tersimpan; Telur muncul di pilihan bahan resep dengan SKU, satuan, tersedia 200 |
| 2 | Resep Indomie Kuah: tambah Indomie Goreng 1, Telur 1 | Tersimpan tanpa catatan bahan baku baru; `variant_outlet_stock` tidak bertambah baris |
| 3 | Buka POS web dan tablet | Kartu Indomie Kuah = 100; Telur 200 |
| 4 | Jual 1 Indomie Kuah sampai selesai | Goreng 99, Telur 199; riwayat Telur: `recipe_sale` −1 menyebut Indomie Kuah + nomor pesanan |
| 5 | Jual 3 Telur langsung | Telur 196 (riwayat `sale` −3) |
| 6 | Tab terbuka: 5 Indomie Kuah (belum selesai) | Kartu Kuah turun 5; Telur untuk jual langsung = 196 − 5 = 191 |
| 7 | Coba jual langsung 192 Telur | Ditolak 409 `insufficient_stock_reserved` |
| 8 | Selesaikan tab, lalu void | Goreng dan Telur kembali persis (`recipe_return`), walau resep diubah di antaranya |
| 9 | Ubah resep (Telur 2), jual 1 Kuah | Telur turun 2; pesanan lama tidak berubah |
| 10 | Simpan resep "1 Telur untuk 2 porsi" | Ditolak 422 `recipe_ingredient_whole_quantity` |
| 11 | Pilih Indomie Kuah sebagai bahan resep lain / nyalakan penandanya | Ditolak 422 `recipe_ingredient_recursive` |
| 12 | Matikan penanda Telur | Resep lama tetap utuh dan tetap memotong; Telur hilang dari pilihan resep baru; resep lama menandainya |
| 13 | Nonaktifkan Telur saat dirujuk resep | Ditolak 409 `ingredient_in_use` menyebut resepnya |
| 14 | Dua terminal serentak menyelesaikan Kuah atas sisa 1 Telur | Tepat satu berhasil; saldo 0; tidak minus |
| 15 | Kirim ulang pesanan yang sama (`local_ref` sama) | Tidak ada pemotongan kedua |
| 16 | Ekspor lalu impor ulang lembar `recipes` | Baris varian utuh |
| 17 | Android: bandingkan angka kartu dengan web, sebelum/sesudah skenario 5 dan 6 | Identik |

## Pemeriksaan wajib sebelum "selesai" (Konstitusi II)

- Tes `tests/Feature/` lulus di MySQL nyata; bukti keserentakan (skenario 14) dari dua koneksi sungguhan, bukan satu transaksi terbungkus.
- Layar diuji di peramban nyata dengan konsol diperiksa: formulir produk, laci resep, riwayat stok.
- Perangkat Android (skenario 17): bila tidak dapat dijalankan, dicatat jujur sebagai **belum diverifikasi**.
