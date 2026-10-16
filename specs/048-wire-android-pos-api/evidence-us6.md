**Classification**: INTERNAL

# Bukti US6 — ketahanan emulator (T066), 2026-10-16

Status: **BELUM SELESAI — terhalang kondisi host/emulator.** Tidak ada skenario yang dinyatakan LULUS.

## Lingkungan
- Perangkat: `emulator-5554` (wjm_tablet), outlet 4, X-Outlet-Id 4. Build terpasang: commit 3fbcbab (heartbeat T065).
- Load host (1 mnt / 5 mnt): 14,5 (awal, aman) → 40,6 → 57,6 → 23,8 (dipoll sampai < 25) → 17,5 / 41,7.
- Wadah lain di host (talenta-full-binary-db-1, boothpos-node-1) tidak disentuh.

## Kejadian
- Setelah instal ulang, perangkat tidak lagi merespons: dialog sistem "Process system isn't responding" muncul dan tetap ada setelah dua kali "Wait".
- `system_server` masih hidup (pid 559), tetapi UI sistem tidak responsif; aplikasi POS tidak tampil.

## Skenario
| # | Skenario | Hasil | Catatan |
|---|---|---|---|
| 1 | Putus jaringan saat tulis (buka, tambah baris, kirim, bayar, tutup) | BELUM DIJALANKAN | Tutup shift termasuk skenario tetapi aturan "tanpa tutup" melarangnya; perlu keputusan. |
| 2 | Token dicabut saat order terbuka | BELUM DIJALANKAN | Perangkat belum stabil. |
| 3 | Budi dinonaktifkan saat masuk, lalu diaktifkan kembali | BELUM DIJALANKAN | Perangkat belum stabil. |
| 4 | Layar Sinkronisasi: koneksi, skew, rilis, printer | BELUM DIJALANKAN | Perangkat belum stabil. |

## Keadaan server (baca saja)
- Shift 12 terbuka; 0 order terbuka di shift 12.
- Budi: user id 12, aktif.

## Percobaan pemulihan (2026-10-16, 18:49 WIB)
- Emulator wjm_tablet di-restart dingin satu kali (`-no-snapshot`). Boot selesai dalam 6 poll.
- Setelah peluncuran aplikasi, layar tetap splash (ikon Android) pada dua pengecekan; PIN belum muncul.
- Load host saat itu: 17,6 / 33,2 / 36,4 (1 / 5 / 15 mnt). Load 5 mnt di atas 25 sepanjang sesi.
- Tidak ada skenario 1–4 yang dijalankan. Tidak ada tulisan ke server selama percobaan ini.

## Keputusan yang perlu diambil
1. Skenario 1 mencakup "tutup shift", sementara aturan T066 melarang penutupan. Perlu konfirmasi: jalankan langkah tutup dengan persetujuan, atau keluarkan dari skenario 1.
2. Stabilitas host: perangkat tidak merespons saat load 5 mnt > 30. Perlu menunggu atau menghentikan beban wadah lain (di luar cakupan ini).

## Pemeriksaan sisi server (2026-10-16, sore WIB) — dilakukan induk lewat API
- **A. Ulang dengan kunci sama (tanpa duplikat):** buka tab 2× dengan `local_ref` sama → id sama (2051); tambah baris 2× dengan `Idempotency-Key` sama → 1 baris; kirim 2× dengan kunci sama → 1 tiket dapur; bayar 2× dengan `client_ref` sama → 1 pembayaran. **LULUS.**
- **B. Token dicabut:** logout sesi kedua → 204; token itu lalu ditolak `GET /floor` dengan 401; sesi lain tetap 200. **LULUS.**
- **C. Staf dinonaktifkan di tengah sesi:** sebelum perbaikan, token budi TETAP BERFUNGSI (`POST /tabs` 201, order baru tercipta). **GAGAL → diperbaiki**: middleware `EnsureUserIsActive` (setelah `auth:sanctum` di grup API) menolak akun nonaktif dengan 401 `account_inactive` dan mencabut token. Setelah perbaikan (uji `InactiveUserTokenTest` 2/2, dan uji langsung ke server dev): `POST /tabs` → 401 `account_inactive`; tidak ada order baru; budi diaktifkan kembali. **LULUS setelah perbaikan.**
- **Residu uji:** order 2052 (tab kosong dari token budi sebelum perbaikan) dibatalkan dengan persetujuan Sari (`cancel_order`, 200, status voided). Tab 2051 (uji retry) dilunasi tunai 1.200 (completed). Shift 12 tidak punya tab terbuka.
- **Akar masalah cache (diketahui sekarang):** tes menulis `setting:system_mode = live` ke cache file dev. Uji satu berkas membuktikannya (cache kosong setelah `cache:clear`, berisi `live` sesudah satu berkas tes). Penyebabnya: `CACHE_STORE=file` dari env_file container mengalahkan `<env force>` di phpunit.xml. Perbaikan: flag perintah tes + penjaga di `TestCase::setUp`. Cache dev dikosongkan; mode kembali `demo`, outlet 1–4 terlihat.

## Yang BELUM dilakukan (perangkat)
- Putus jaringan di tengah tiap langkah tulis di perangkat, token kedaluwarsa di perangkat, layar PIN setelah staf dinonaktifkan, dan layar Sinkronisasi dengan nilai nyata. Ditunda sampai beban host turun (keputusan pemilik).
- T066 tetap `[ ]` sampai bagian perangkat selesai.


## T066 — pelaksanaan di perangkat (2026-10-16, ~09:10 WIB, outlet 4, shift 12)
Status: **SEBAGIAN — dihentikan karena load host 1 mnt > 25** (33,25 setelah langkah 1b). Skenario 2–4 belum dijalankan.

Metode: `svc wifi disable` → ketuk aksi (permintaan tidak sampai ke server) → tunggu → `svc wifi enable` → ketuk aksi yang sama (kunci Idempotency-Key tertahan, niat sama) → hitung DB. Hanya menguji jalur coba-ulang untuk permintaan yang tidak pernah sampai; jalur "server sudah menerima tetapi respons hilang" belum diuji.

| Langkah | Sebelum | Saat putus | Sesudah coba-ulang | Hasil |
|---|---|---|---|---|
| 1a Buka order (`open:counter`) | 7 order di shift 12 | 7 | 8 (order 2053) | LULUS: tepat satu |
| 1b Tambah baris Kopi Susu | 0 baris di order 2053 | 0 | 1 baris, qty 1 | LULUS: tepat satu |
| 1c Kirim ke dapur | — | — | — | BELUM |
| 1d Bayar tunai | — | — | — | BELUM |
| 1e Tutup shift 12 | — | — | — | BELUM |
| 2 Sesi dicabut mid-order | — | — | — | BELUM |
| 3 Budi dinonaktifkan | — | — | — | BELUM |
| 4 Layar Sinkronisasi | — | — | — | BELUM |

Order uji terbuka: **2053** (1 baris Kopi Susu, belum dikirim, belum dibayar). Tidak di-void, tidak dibatalkan; harus diselesaikan atau dibiarkan terbuka sebagai residu uji (dilaporkan).
