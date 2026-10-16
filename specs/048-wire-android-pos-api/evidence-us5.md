**Classification**: INTERNAL

# Bukti US5 — pajak, diskon, bagi bill, cetak ulang, refund (T063, 2026-10-16)

Sebagian lewat perangkat dan sebagian lewat API (diberi label). Dev DB (warkop_wjm), outlet 4, shift 12 terbuka.

## Hasil per langkah
| Langkah | Jalur | Hasil | Bukti |
|---|---|---|---|
| 1. Order 60.000 + PPN 10% = 66.000 | Perangkat | LULUS (struk: tanggal salah, lihat cacat) | Order 2046 (A-022, T01-000012): subtotal 60.000, pajak 6.000, total 66.000, tunai 66.000; DB cocok |
| 2. Diskon 10% di atas batas kasir | API | LULUS | Tanpa persetujuan 409 `override_required`; persetujuan Sari 201; diskon 200: diskon 6.000, pajak 5.400, total 59.400; bayar tunai 59.400 → completed (order 2047) |
| 2b. Persetujuan lewat dialog di perangkat | Perangkat | GAGAL (cacat) | Tekan "Setujui" membekukan aplikasi ("tidak merespons"); tidak ada baris override dari perangkat |
| 3. Bagi rata 2 bagian | API | LULUS | Tab 2048 (66.000): `parts` [{index 1, 33.000}, {index 2, 33.000}]; jumlah = 66.000; bayar tunai 66.000 → completed |
| 4. Cetak ulang | API | LULUS | Salinan 2 (pertama, gratis) 200; salinan 3 tanpa persetujuan 409 `override_required`; dengan persetujuan Sari 200 `copy_no` 3 |
| 5. Laporan back office = struk | API | LULUS | Laporan 16 Okt (tanggal bisnis): bersih 582.000, diskon 6.000, pajak 17.400, bruto 593.400 = jumlah total 21 order selesai di DB (termasuk residu uji sebelumnya). Tiga order T063: 2046 = 66.000, 2047 = 59.400, 2048 = 66.000 |

## Cacat yang ditemukan (dicatat, belum diperbaiki)
1. **Struk menampilkan tanggal 31/10/2026** padahal hari ini 16/10/2026: tanggal contoh tertanam di layar/struk perangkat. Harus diganti tanggal server (completed_at / tanggal bisnis).
2. **Dialog persetujuan membekukan aplikasi** saat "Setujui" ditekan (ANR "Warkop POS tidak merespons") pada percobaan diskon di perangkat. Berbarengan dengan putusnya jaringan emulator. Penyebab belum diidentifikasi. Ini memblokir persetujuan di perangkat untuk semua aksi.
3. Lingkungan: jaringan emulator putus beberapa kali; cache `setting:system_mode` basi (live) sempat menyembunyikan outlet 4 (403). Cache dibersihkan; DB tetap demo.

## Catatan teknis
- Nomor salinan: salinan 1 adalah struk asli; cetak ulang pertama = salinan 2 (gratis, `reprint_free_copies` = 1); salinan 3 dan seterusnya butuh persetujuan.
- Diskon dihitung sebelum pajak (subtotal 60.000 − diskon 6.000 = 54.000; pajak 10% = 5.400).
- Bruto = bersih − diskon tingkat order + pajak (keputusan pemilik; sama dengan struk).

## Belum diverifikasi
- Persetujuan diskon, bagi bill, dan cetak ulang lewat dialog di perangkat (terhalang cacat 2b).
- Struk tanggal benar (cacat 1).
- Refund (tidak diuji dalam T063).

## Status
T063 BELUM SELESAI. Langkah API lulus; langkah perangkat terhalang dua cacat aplikasi.
