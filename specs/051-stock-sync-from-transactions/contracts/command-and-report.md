# Contract: perintah dan berkas laporan

## Perintah

```
php artisan app:sync-stock-from-transactions                 # DRY-RUN (bawaan): tidak menulis apa pun
php artisan app:sync-stock-from-transactions --apply --approve=<plan_hash> --by=<username> [--yes]
```

- Dry-run: hitung rencana LIVE → tulis Excel → cetak ringkasan per status, jalur berkas, dan `plan_hash`. Tidak ada perubahan pada stok, pergerakan, atau log aktivitas.
- `--apply` tanpa `--approve` → ditolak (kode keluar ≠ 0) dengan petunjuk menjalankan dry-run.
- `--apply`: hitung ulang rencana; `plan_hash` berbeda dari `--approve` → ditolak, Excel baru ditulis, tidak ada perubahan (data berubah sejak ditinjau).
- `--by` wajib dan harus pengguna owner/admin/inventory (`canAccessAnyMenu`); selain itu ditolak.
- Konfirmasi interaktif; tanpa terminal interaktif wajib `--yes`.
- Cadangan pengaman dibuat sebelum menulis; gagal → batal.
- Penulisan satu transaksi (semua atau tidak sama sekali); sesudahnya laporan HASIL ditulis (sebelum → sesudah) dan dry-run ulang menghasilkan nol perubahan.
- Kode keluar 0 = sukses (termasuk dry-run), 1 = ditolak/gagal.

## Berkas Excel (`storage/app/private/stock-sync/stock-sync-<dryrun|applied>-<YYYYmmdd-HHMMSS>.xlsx`)

1. **Ringkasan**: mode (LIVE), waktu, pelaksana (bila apply), `plan_hash`, jumlah varian per status, total unit dikurangkan, total kekurangan.
2. **Varian**: kolom pada data-model.md (baris rencana).
3. **Sumber**: per varian terkoreksi — jenis (penjualan/serah-terima pre-order/retur), nomor transaksi, kuantitas, waktu.

Tanpa data kontak pelanggan. Teks diketik-pengguna selalu tersimpan sebagai teks (tidak pernah rumus).
