# Quickstart: Verifikasi 092

**Klasifikasi**: INTERNAL

Perintah tes di dalam kontainer WAJIB memakai flag aman (CLAUDE.md): jangan pernah menjalankan `php artisan test` tanpa flag `-e APP_ENV=testing -e DB_DATABASE=warkop_wjm_test -e CACHE_STORE=array -e SESSION_DRIVER=array` pada `docker compose exec` (sebelum nama layanan `app`). Jangan menjalankan tes terfilter selagi suite penuh berjalan (satu proses per DB tes).

```bash
docker compose exec -T -e APP_ENV=testing -e DB_DATABASE=warkop_wjm_test -e CACHE_STORE=array -e SESSION_DRIVER=array app php artisan test --filter=RecipeMenuStock
npm test            # Vitest (qa-tests/)
```

## Data uji (contoh spec)

Menu "Indomie Kuah", satu varian, satu resep (Portions 1): Indomie 1 pcs, Telur 1 pcs, Sayur 50 gr, Kuah 200 ml. Saldo outlet: 20 pcs, 10 pcs, 500 gr, 2.000 ml.

## Skenario penerimaan

| # | Langkah | Harapan |
|---|---|---|
| 1 | `GET /products?pos=1&with_variants=1` sebagai kasir shift outlet itu | varian: `stock_basis=recipe`, `outlet_stock=10`, `track_stock=true`, `stock_limiting` menunjuk Telur/Sayur/Kuah |
| 2 | Set saldo Telur = 0, muat ulang | `outlet_stock=0`; kartu habis di web dan tablet |
| 3 | Pulihkan, jual 2 porsi (`POST /orders`) | saldo: 18/8/400/1.600; satu `sale` per bahan; TIDAK ada pergerakan `stock_movements` varian |
| 4 | Kirim `POST /orders` yang sama (`local_ref` sama) 5 kali | pemotongan hanya sekali |
| 5 | Pesan 11 saat stok menu 10 | 409 `insufficient_recipe_stock`; tidak ada baris/pergerakan |
| 6 | Dua tab terbuka: tab A tambah 6, tab B tambah 6 | B ditolak 409 (A menahan 6); kartu menampilkan 4 setelah A |
| 7 | Hapus baris A, tambah di B | B berhasil (tahanan lepas) |
| 8 | Satu pesanan dua baris menu berbeda yang berbagi Telur (kebutuhan gabungan > saldo) | ditolak; tidak ada yang tersimpan |
| 9 | Void pesanan skenario 3 | bahan kembali ke 20/10/500/2.000 dari buku besar |
| 10 | Ubah resep sesudah penjualan lalu void | pengembalian tetap sesuai yang dulu dipotong |
| 11 | Arsipkan resep | menu kembali memakai stok varian; menu tanpa resep tak pernah berubah di seluruh skenario |
| 12 | Muat 5 lalu 50 menu beresep dengan penghitung kueri | jumlah kueri sama |

## Uji dua terminal (manual, DB dev)

Otomasi satu proses tidak membuktikan keserentakan MySQL. Setelah tes hijau, jalankan dua permintaan paralel (`xargs -P2` atau dua terminal) yang masing-masing menambah 6 porsi ke tab berbeda pada stok 10, ulangi 20 kali dengan saldo direset; harapan: tidak pernah dua-duanya berhasil dan saldo bahan tidak pernah minus. **Belum dijalankan sampai tugas implementasi selesai; jangan dilaporkan lulus sebelum itu.**

## Pemeriksaan klien

- Web: kartu POS dan Tab menampilkan 10; tombol tambah berhenti di batas; pesan menyebut bahan penghambat; angka berubah setelah penjualan.
- Android: kartu Counter menampilkan angka yang sama dengan web pada outlet yang sama; tarik-untuk-segarkan memperbarui; APK lama (tanpa bidang baru) tetap menampilkan angka dan ditolak server bila melebihi. **Verifikasi tablet memerlukan perangkat; tandai belum terverifikasi bila tidak tersedia.**
- Konsol peramban tanpa galat (Konstitusi II).
