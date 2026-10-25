# Analisis sumber: ekspor Odoo Warkop WJM 2

**Klasifikasi**: CONFIDENTIAL — katalog nyata milik pelanggan (Warkop WJM).

**Dianalisis**: 2026-10-25, dari `~/Downloads/WJM 2/` (diekspor 2026-10-23, antarmuka Odoo berbahasa Indonesia). Berkas XLSX tidak disalin ke repo. Kolom "Penanggung Jawab" (nama staf; 2 nilai unik) sengaja tidak dibaca isinya dan tidak akan dibawa ke keluaran apa pun.

Berkas keempat di folder itu, `Order Point of Sale (pos.order).xlsx`, **tidak dibuka**. Pengguna tidak menyebutnya, dan berkas itu kemungkinan memuat data pelanggan.

## 1. Kategori POS (`Kategori Point of Sale (pos.category).xlsx`)

Kolom: `Urutan`, `Nama Tampilan`. 13 baris, semua huruf kapital, tanpa induk:

| Urutan | Nama |
|---|---|
| 0 | MAKANAN |
| 0 | MIE |
| 1 | COFFEE |
| 2 | NON-COFFEE |
| 3 | SNACKS |
| 4 | TEA |
| 5 | ADD ON |
| 0 | LAIN LAIN |
| 0 | MIE & PANGSIT LEVEL |
| 0 | NASI |
| 0 | ROTI & PISANG |
| 0 | SIGNATURE COFFEE |
| 0 | SNACK RINGAN |

- Banyak kategori berurutan 0; urutan Odoo tidak cukup untuk menata layar POS.
- Sembilan nama sama dengan kategori WJM 3 jika huruf besar-kecil diabaikan (COFFEE, NON-COFFEE/Non Coffee, SNACKS, TEA, ADD ON, MIE & PANGSIT LEVEL, NASI, ROTI & PISANG, SIGNATURE COFFEE). MAKANAN, MIE, LAIN LAIN, dan SNACK RINGAN baru.

## 2. Atribut produk (`Atribut produk (product.attribute).xlsx`)

Kolom: `Urutan`, `Atribut`, `Display Type`, `Mode Pembuatan Varian`. 14 atribut, semuanya "Secara Instant" (membentuk varian) dan bertampilan Radio:

Varian Kentang, Varian, Varian Indomie, VARIAN ROTI, `Mentai ` (ada spasi di akhir), VARIAN SNACK, VARIANT MINUMAN DINGIN SHOWCASE, NUTRISARI, TEAJUS, JASJUS, INDOMIE KUAH, VARIANT INDOMIE, VARIANT MIE SEDAP, Varian signature coffee.

**Celah utama: ekspor ini tidak memuat nilai atribut.** Ekspor WJM 3 punya kolom `Values`; ekspor ini tidak. Akibatnya **nama 141 varian tidak ada di sumber**.

## 3. Produk (`Templete Produk (product.template).xlsx`)

Kolom: `Favorit`, `Nama`, `Referensi Internal` (kosong semua), `Penanggung Jawab` (nama staf, tidak dibaca), `Harga Jual`, `Modal` (0 semua), `Kuantitas Di Tangan` (0 semua), `Jumlah Perkiraan` (0 semua), `Satuan` (Unit), `QR Code` (kosong), `Dekorasi Pengecualian Aktivitas` (kosong), `Is Combo Product` (False semua), `# Varian Produk`, `Atribut produk`, `Gambar 1024`.

- **80 produk, 141 varian** (jumlah `# Varian Produk`). Tidak ada kolom kategori POS, tidak ada BoM, dan tidak ada produk teknis (`nf_…`).
- **Ekspor tampaknya terpotong.** Tepat 80 baris (ukuran halaman bawaan daftar Odoo), urut abjad, dan berhenti di "MIX PLATTER". Tidak ada item berawalan N–Z. Kategori NASI, ROTI & PISANG, SIGNATURE COFFEE, dan TEA tidak punya satu pun item yang jelas cocok. Item WJM 3 seperti Nasi Goreng, Roti Bakar, Pisang Bakar, dan Teh juga tidak ada. (ASSUMPTION: ini batas halaman ekspor, bukan isi menu sebenarnya.)
- **Atribut per item**: 29 item punya atribut, semuanya tepat satu: `Varian` (22 item, 2 varian; pola Hot/Ice di WJM 3), `VARIANT INDOMIE` (3 item: 6 + 3 + 3 varian), `VARIANT MIE SEDAP` (2 item: 6 + 5), `JASJUS` (1 item, 4), dan `VARIANT MINUMAN DINGIN SHOWCASE` (1 item, 19). Sebanyak 51 item tanpa atribut (1 varian). Totalnya 44 + 12 + 11 + 4 + 19 + 51 = 141.
- **Harga 0** (3 item): ABC MOCCA, ALL VARIANT MINUMAN DINGIN(SHOWCASE) (19 varian), KAPAL API KOPI SUSU.
- **Foto**: 11 JPEG utuh (CHACHA, DRINK BENG BENG, ES BATU, JELLY, KEJU, KERUPUK TAHU, KUACI, LARUTAN CAP KAKI 3, MAKARONI BON CABE, MALKIST ROMA, MILO). 69 terpotong ("Konten pada kolom ini terlalu panjang untuk file XLSX …"). Tidak ada sel foto kosong.
- **Nama**: huruf kapital semua. Ada spasi ganda ("MARTABAK TELUR  KORNET MINI") dan ejaan campuran ("GOODDAY MOCHACINO" dan "GOOD DAY CAPPUCHINO").

## 4. Perbandingan dengan sistem saat ini (DB dev, 2026-10-25)

- **LIVE** sudah punya outlet "Warkop WJM 2" (kode `WJD`), dibuat pengguna. Outlet ini belum punya stasiun, meja, perangkat, kasir, maupun item sendiri. Saat ini outlet itu **menjual ke-79 item WJM 3** (penanda "dijual" di WJD menyala untuk semuanya).
- **DEMO** belum punya outlet Warkop WJM 2.
- Kategori berlaku untuk seluruh mode, bukan per outlet. Kategori WJM 3 menunjuk ke stasiun WJM 3 (LIVE: "Dapur Minuman", "Dapur Makanan").
- Banyak nama item WJM 2 juga ada di WJM 3 dengan ejaan berbeda (mis. "ABC KLEPON"/"ABC Klepon", "BASRENG"/"Basreng"), tetapi harga atau variannya bisa berbeda (mis. Kapal Api: Rp5.000 dengan 2 varian di WJM 2, Rp7.000 tanpa varian di WJM 3).
