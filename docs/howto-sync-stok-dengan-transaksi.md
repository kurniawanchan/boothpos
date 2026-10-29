# Panduan: Sinkronisasi Stok LIVE dengan Transaksi Sebelumnya

**Klasifikasi: INTERNAL** · Fitur 051 (`specs/051-stock-sync-from-transactions/`) · Perintah: `app:sync-stock-from-transactions`

## 1. Kapan dipakai

Pakai bila **stok riil per varian diketik SESUDAH penjualan atau serah-terima pre-order sudah ada**, sehingga angka yang diketik mengabaikan transaksi itu dan stok di sistem terlalu besar.

Contoh: stok awal 100, terjual 8 lewat POS, lalu stok riil diketik 50 (lewat impor stok Excel atau penyesuaian stok). Stok yang benar adalah 50 − 8 = **42**, tetapi sistem menampilkan 50.

Perintah ini hanya untuk data **LIVE**. Data DEMO tidak pernah dibaca atau diubah.

## 2. Yang dihitung (dan yang tidak)

| Dikurangkan dari stok | Tidak dikurangkan |
|---|---|
| Penjualan POS | Pre-order yang **belum diserahkan** (status ordered / dp_paid / arrived / settled) |
| Serah-terima pre-order (handed over) | Pre-order yang dibatalkan |
| (Retur dari void **menambah** kembali) | Transaksi yang dibuat **sesudah** stok diketik (sudah mengurangi stok seperti biasa) |

- Acuan per varian = pembaruan stok manual **terakhir** (impor stok Excel atau penyesuaian stok). Hanya transaksi di antara pembaruan manual sebelumnya dan yang terakhir yang dikoreksi.
- Koreksi dihitung dari **stok sekarang** (bukan angka yang diketik), jadi penjualan susulan tidak terhapus.
- Bila hasilnya di bawah 0, stok dibatasi ke **0** dan kekurangannya dilaporkan (tidak pernah disimpan negatif).

## 3. Prasyarat

- Kode fitur ini ada di working tree / branch yang dijalankan (`082-stock-sync-from-transactions`).
- Anda tahu **username** pemilik/admin/inventory yang akan menjalankan (untuk jejak audit).
- Perintah dijalankan terhadap database yang berisi data LIVE Anda:
  - **Docker dev**: container `app` memakai database `boothpos`.
  - **Instalasi native / toko**: database di `.env` aplikasi.
- Ini perintah biasa, **bukan tes**. Jangan menjalankan `php artisan test` di container tanpa `-e APP_ENV=testing -e DB_DATABASE=boothpos_test` (itu menghapus database).

## 4. Langkah

### Langkah 1 — Dry-run (aman, tidak mengubah apa pun)

```bash
# Docker dev
docker compose exec app php artisan app:sync-stock-from-transactions

# Instalasi native
php artisan app:sync-stock-from-transactions
```

Keluaran yang diharapkan:

```
+---------------------+---------------+
| Status              | Jumlah varian |
+---------------------+---------------+
| akan_diubah         | …             |
| perlu_perhatian     | …             |
| tidak_ada_perubahan | …             |
| sudah_disinkronkan  | …             |
| dilewati            | …             |
+---------------------+---------------+
Total pengurang bersih: … unit; kekurangan (dibatasi ke 0): … unit
Excel tinjauan: …/storage/app/private/stock-sync/stock-sync-dryrun-<waktu>.xlsx
Kode rencana: <64 karakter>
Dry-run — tidak ada yang diubah. …
```

Catat **Kode rencana** dan buka berkas Excel-nya. Dengan Docker, berkas muncul juga di folder proyek (`storage/app/private/stock-sync/`). Jalankan dry-run sebanyak yang Anda mau; ia tidak mengubah data.

### Langkah 2 — Tinjau Excel

Berkas punya tiga lembar:

- **Ringkasan** — mode (LIVE), waktu, kode rencana, jumlah varian per status, total pengurang dan total kekurangan.
- **Varian** — satu baris per varian:

  | Kolom | Arti |
  |---|---|
  | `penjual`, `produk`, `varian`, `sku` | Identitas varian |
  | `stok_sekarang` | Stok di sistem saat ini |
  | `acuan_id`, `acuan_waktu` | Pembaruan stok manual terakhir yang dikoreksi |
  | `acuan_sebelumnya_waktu` | Pembaruan manual sebelumnya (awal jendela) |
  | `terjual`, `retur`, `serah_terima_preorder` | Rincian pengurang |
  | `pengurang_bersih` | terjual + serah-terima − retur |
  | `stok_seharusnya` | Stok setelah koreksi |
  | `selisih` | Perubahan stok (selalu ≤ 0) |
  | `kekurangan` | Unit terjual melebihi stok (dibatasi ke 0) |
  | `status`, `catatan` | Lihat tabel status di bawah |

- **Sumber** — tiap pergerakan yang dikurangkan: SKU, jenis (Penjualan POS / Retur (void) / Serah-terima pre-order), **nomor transaksi**, kuantitas, waktu. Berkas tidak memuat data pelanggan.

**Yang perlu diperiksa:**
1. Ambil 3–5 varian, cocokkan `nomor_transaksi` di lembar *Sumber* dengan layar Penjualan / Pre-order, dan stok-nya dengan layar Stok.
2. Periksa semua baris `perlu_perhatian` (terjual melebihi stok yang diketik): apakah angka stok riil yang diketik memang benar?
3. Periksa baris dengan `catatan` "beberapa pembaruan stok manual".
4. Periksa `acuan_waktu`: harus sama dengan waktu Anda mengetik stok riil.

**Arti status**

| Status | Arti | Ditulis saat apply? |
|---|---|---|
| `akan_diubah` | Stok akan dikurangi sesuai `selisih` | Ya |
| `perlu_perhatian` | Terjual melebihi stok → dibatasi ke 0, kekurangan tercatat | Ya (bila `selisih` ≠ 0) |
| `tidak_ada_perubahan` | Tidak ada transaksi yang perlu dikurangkan | Tidak |
| `sudah_disinkronkan` | Sudah pernah dikoreksi untuk pembaruan stok yang sama | Tidak |
| `dilewati` | Belum ada pembaruan stok manual, atau varian sudah dihapus | Tidak |

Bila ada yang tidak cocok, **jangan lanjut**. Catat variannya dan hubungi pengembang.

### Langkah 3 — Terapkan (mengubah data)

Hanya setelah Excel ditinjau dan cocok. Pakai **kode rencana dari Langkah 1**:

```bash
docker compose exec app php artisan app:sync-stock-from-transactions \
  --apply --approve=<kode rencana> --by=<username>
```

Perintah akan:
1. Menghitung ulang rencana dan membandingkan kodenya dengan `--approve`. Bila **berbeda** (ada transaksi/perubahan stok baru sejak Anda meninjau): **ditolak**, Excel baru dibuat, tidak ada yang diubah.
2. Meminta konfirmasi `yes` (tanpa terminal interaktif wajib `--yes`).
3. Membuat **cadangan pengaman** database; bila gagal, dibatalkan.
4. Menulis semua koreksi dalam **satu transaksi** (semua atau tidak sama sekali), masing-masing sebagai penyesuaian stok di riwayat stok varian dengan alasan "Sinkronisasi stok dengan transaksi sebelum pembaruan stok #…".
5. Membuat Excel hasil: `stock-sync-applied-<waktu>.xlsx`.

### Langkah 4 — Pastikan selesai

Jalankan dry-run lagi (Langkah 1). Semua varian yang tadi `akan_diubah` kini harus `sudah_disinkronkan`, dan tidak ada yang `akan_diubah` tersisa. Menjalankan apply lagi tidak mengurangi dua kali.

## 5. Pemecahan masalah

| Pesan / gejala | Penyebab | Tindakan |
|---|---|---|
| `--apply butuh --approve=<kode rencana>` | Kode tidak diberikan | Jalankan dry-run dulu, pakai kode yang dicetak |
| `Kode rencana tidak cocok…` | Data berubah sejak ditinjau, atau kode salah/terpotong | Buka Excel terbaru yang dicetak, tinjau ulang, pakai kode baru |
| `--apply butuh --by=<username>` / `Pengguna tidak ditemukan atau tidak berwenang…` | `--by` kosong, salah, atau pengguna kasir | Pakai username owner/admin/inventory |
| `Mode non-interaktif membutuhkan --yes` | Dijalankan tanpa terminal interaktif | Tambahkan `--yes` (setelah meninjau Excel) |
| `Cadangan pengaman gagal, dibatalkan` | `mysqldump` tidak tersedia / folder cadangan tak bisa ditulis | Perbaiki cadangan (lihat RUNBOOK §Backup); tidak ada data yang diubah |
| `Gagal; semua perubahan dibatalkan` | Kesalahan saat menulis | Tidak ada yang berubah; kirim pesan galat ke pengembang |
| Varian tidak muncul sebagai `akan_diubah` padahal stoknya salah | Stok yang diketik sama dengan stok saat itu (tidak ada riwayat pembaruan) | Status `dilewati`; koreksi manual lewat penyesuaian stok |

## 6. Batas yang perlu diketahui

- Varian yang diketik stoknya lebih dari sekali hanya dikoreksi untuk jendela pembaruan **terakhir** (diberi catatan di Excel).
- Kedatangan barang pre-order (`arrived`) **tidak** ditambahkan kembali; stok riil yang diketik dianggap sudah memuat barang yang datang.
- Bahan baku (material) di luar cakupan.
- Aturan acuan **belum diuji terhadap data LIVE sungguhan**; peninjauan Excel adalah pemeriksa utama.

## 7. Memulihkan bila perlu

Cadangan pengaman dibuat sebelum penulisan dan ID-nya dicetak. Pulihkan dengan `php artisan app:restore <path/database.sql>` (lihat `docs/RUNBOOK.md` §Backup). Pemulihan mengganti **seluruh** database dengan isi cadangan, jadi transaksi yang terjadi sesudah cadangan akan hilang; pakai hanya bila benar-benar perlu.

## 8. Referensi

- Spesifikasi dan keputusan: `specs/051-stock-sync-from-transactions/` (`spec.md`, `research.md`)
- Ringkasan operasional: `docs/RUNBOOK.md` §"Sinkronisasi stok LIVE dengan transaksi sebelumnya"
