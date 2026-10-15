**Classification**: INTERNAL

# Design fidelity — Android POS UI (diperiksa 2026-10-15)

Dibandingkan pada emulator tablet 2360×1480 (landscape, ~1180dp) terhadap `design/screens/tablet/*.png`; sebagian dicek di ukuran telepon (1080×2400, ~411dp). Tangkapan layar kedua sisi dibaca satu per satu.

## Dicek terhadap desain (tablet, gambar vs emulator, berdampingan)
01 login-pin · 02 open-shift · 03 tables · 04/05 order-editor · 06 modifier · 07 line-actions · 08 order-actions · 09 split · 10 discount · 11 void · 12 manager-override · 13 tender-cash · 14 edc · 15 qris-record · 16 qris-verify · 17 payment-complete · 18 receipt-copy · 19/20 orders · 21 shift-hub · 22 cash-out · 23 x-report · 24 close-shift · 25 close-result · 26 device-sync · 27 dark · 28 offline-terbatas.
Perbaikan dari perbandingan: lembar samping seragam (judul + isi + tombol utama tertambat), kontrol segmen, kartu preset diskon, dialog setuju lebih ringkas, kartu QRIS dengan QR di kiri, struk monospace bertanda SALINAN, daftar order selesai + panel rincian, X-report satu kartu, hasil tutup shift tiga kartu.
Preset debug: `adb shell am start -n com.warkop.pos/.MainActivity --es preset NN` (hanya build debuggable; `PosStore.debugPreset`).

## Dicek di ukuran telepon
01 (PIN), 29 (open shift), 34 (tables), 30 (menu + bar order), 31 (modifier sheet sebagai lembar bawah). Satu crash (kolom bergulir bersarang di compact) ditemukan dan diperbaiki.

## Belum dicek / belum dibangun
- 35 (tablet 8"), 36 (tablet 13"), 32/33 (telepon tender/selesai), dan dialog lain di ukuran telepon.
- Selisih kecil yang diketahui: ikon kunci pada chip "Offline terbatas" (desain) belum ada; lingkaran dekoratif pada kartu antrean (17) belum ada; dialog void/override di desain sedikit lebih lebar.
- Font Roboto Flex belum dipasang (font sistem); foto menu belum ada (ikon); bilah status desain hanyalah bingkai gambar.
- Data masih contoh dalam memori (`PosCatalog`, `PosStore`) — belum memanggil API sungguhan (termasuk endpoint PIN B1). Antrean sinkron/cetak hanya cermin tampilan.
