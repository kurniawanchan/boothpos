# Tabel pemetaan katalog WJM 2 (usulan)

**Klasifikasi**: CONFIDENTIAL — katalog nyata milik pelanggan (Warkop WJM).

**Status**: Disetujui — halaman 1 dan halaman 2 diterima apa adanya (halaman 2: 48 item baru, kategori inferensi dari nama item dan pola WJM 3, Hot/Ice untuk 8 minuman, Rp0 untuk TOP CAPPUCINO dan SUSU COKLAT/JAHE/PUTIH, kategori baru "Lain Lain"), kecuali item yang ditunda (lihat `## Item ditunda`). MARIMAS ALL VARIANT memakai nama MARIMAS seperti yang sudah diubah pemilik di LIVE.

**Disetujui oleh (peran)**: Pemilik · **Tanggal**: 2026-10-25

**Outlet**: Warkop WJM 2 · **Kode usulan**: WJ2, WD2 · **Buat outlet**: DEMO bila belum ada

**Tarik item lain dari menu outlet**: LIVE ya, DEMO ya

**Stasiun**: Bar = Dapur Minuman, Bar; Dapur = Dapur Makanan, Dapur

**Stok awal**: 100

**Sumber**: tiga ekspor Odoo WJM 2 ([source-analysis.md](./source-analysis.md)). Kolom kategori dan varian di bawah adalah **inferensi** dari nama item, jumlah varian Odoo, dan pola WJM 3. Inferensi ini belum diverifikasi. Ekspor produk datang dalam dua halaman: halaman 1 (80 baris, A–M) dan `… page 2.xlsx` (51 baris, M–T, tanpa kolom kategori dan tanpa kolom jumlah varian).

## Ringkasan usulan

| Kategori sumber | Kategori tujuan | Stasiun | Item dibuat | Varian dibuat |
|---|---|---|---|---|
| MAKANAN | — (tanpa item) | Dapur | 0 | 0 |
| MIE | Mie | Dapur | 5 | 14 |
| COFFEE | Coffee | Bar | 18 | 35 |
| NON-COFFEE | Non Coffee | Bar | 21 | 30 |
| SNACKS | Snacks | Dapur | 16 | 16 |
| TEA | Tea | Bar | 4 | 8 |
| ADD ON | Add on | Dapur | 4 | 4 |
| LAIN LAIN | Lain Lain | Dapur | 3 | 3 |
| MIE & PANGSIT LEVEL | Mie & Pangsit Level | Dapur | 4 | 4 |
| NASI | — (tanpa item) | Dapur | 0 | 0 |
| ROTI & PISANG | Roti & Pisang | Dapur | 10 | 10 |
| SIGNATURE COFFEE | — (tanpa item) | Bar | 0 | 0 |
| SNACK RINGAN | Snack Ringan | Dapur | 39 | 39 |
| **Total** | | | **124** | **163** |

Hitungan di atas hanya item yang dibuat; 6 item ditunda (JASJUS, SHOWCASE, MIE SEDAP ×2, NUTRISARI, TEAJUS) dan Partial Payment (produk teknis Odoo) dikecualikan. Halaman 1 + halaman 2 = 131 baris sumber = 124 item + 7 dikecualikan.

- Kategori tujuan yang namanya sudah ada di mode itu (Coffee, Non Coffee, Tea, Snacks, Add on, Mie & Pangsit Level) **dipakai bersama** dengan WJM 3 dan tidak diubah. "Snack Ringan" dan "Mie" baru. Tiket item WJM 2 tetap masuk ke stasiun WJM 2 karena stasiun diset per item.
- Tiga kategori sumber masih tanpa item di dua halaman ekspor: MAKANAN, NASI, SIGNATURE COFFEE. "Lain Lain" (baru) dan "Roti & Pisang" (bersama dengan WJM 3) kini berisi item halaman 2.
- Harga setiap varian = harga item, kecuali tertulis di `## Harga varian khusus`.
- Nama varian bertanda `?` belum diketahui (34 varian: JASJUS ×4, MINUMAN DINGIN SHOWCASE ×19, MIE SEDAP KUAH ×5, MIE SEDAP DOUBLE ×6). Pembangkit memberi nama sementara "(belum diisi n)" selama status DRAF dan **menolak** status Disetujui bila masih ada.

## Pertanyaan untuk pemilik

**Halaman 2 (2026-10-25)** — kategori halaman 2 adalah inferensi dari nama item dan pola WJM 3 (ekspornya tanpa kolom kategori dan tanpa jumlah varian):

- **H1. Hot/Ice** — NESCAFFE AMERICANO, TOP CAPPUCINO, SUSU COKLAT/JAHE/PUTIH, TEH MANIS/TARIK/TAWAR dianggap bervarian Hot/Ice (atribut "Varian"; ASSUMPTION karena jumlah varian tidak ada di ekspor).
- **H2. Harga 0** — TOP CAPPUCINO, SUSU COKLAT, SUSU JAHE, SUSU PUTIH berharga Rp0 di Odoo (kemungkinan harga ada di tambahan harga atribut). Dipakai Rp0, atau beri harga lewat `## Harga varian khusus`?
- **H3. Kategori yang ragu** — ROTI AOKA dan ROTI BAGELEN KERING (Snack Ringan atau Roti & Pisang?), PEYEK KACANG (Snack Ringan atau Add on?), SO FRESH, TISSUE TRAVEL, TOLAK ANGIN (kategori baru "Lain Lain", stasiun Dapur usulan), OMELETTE MIE (Mie atau Makanan?).
- **H4. Ditunda** — NUTRISARI ALL VARIANT dan TEAJUS ALL VARIANT: nama rasa belum diketahui.
- **H5. Barang buatan pemilik di LIVE** — empat item yang ditunda dan MARIMAS (diganti nama dari "MARIMAS ALL VARIANT") sudah dibuat pemilik langsung di LIVE; pengisian tidak menyentuhnya (lihat perbaikan penarikan menu di `evidence.md`).

**Halaman 1:**

1. **Nama varian** — Isi nama untuk 34 varian bertanda `?`: JASJUS ALL VARIANT (4 rasa), ALL VARIANT MINUMAN DINGIN(SHOWCASE) (19), MIE SEDAP KUAH (5), MIE SEDAP DOUBLE (6). Ganti `?` di kolom "Varian usulan" dan isi atributnya di `## Nilai atribut`.
2. **Hot/Ice** — Benarkah 22 item berikut bervarian Hot/Ice dengan harga Ice sama dengan Hot? ABC KLEPON, ABC MOCCA, ABC SUSU, FREEZE COOKIES AND CREAM, FREEZE HAZELNUT, FREEZE MOCHAFRIO, GOOD DAY CAPPUCHINO, GOOD DAY CHOCOCINO, GOODDAY MOCHACINO, INDOCAFE, KAPAL API, KAPAL API KOPI SUSU, KAPAL API PAHIT, KOPI LIONG, LUWAK WHITE COFFEE, CHOCOLATOS COKLAT, CHOCOLATOS MATCHA, DANCOW COKLAT, DANCOW VANILA, DRINK BENG BENG, MILO, LEMON TEA.
3. **Rasa Indomie** — Usulan mengikuti WJM 3: Goreng = Goreng Original, Rendang, Mie Aceh; Kuah = Soto, Ayam Bawang, Kari Ayam; Double = keenam rasa. Benar?
4. **Harga 0** — Berapa harga ABC MOCCA, KAPAL API KOPI SUSU, ALL VARIANT MINUMAN DINGIN(SHOWCASE)? Di Odoo harganya Rp0.
5. **Kategori yang ragu** — GOLDA (Coffee atau Snack Ringan?), ENERGEN (Non Coffee atau Coffee?), JELLY, LARUTAN CAP KAKI 3, BASRENG, kerupuk (Snack Ringan atau Add on seperti WJM 3?), KEJU dan KORNET (Add on atau Snacks?), dan Indomie/Mie Sedap (kategori "Mie" baru atau "Indomie" milik WJM 3?).
6. **Gaya nama** — Nama sumber huruf kapital semua (mis. "ABC KLEPON"). Dipakai apa adanya, atau diubah ke huruf judul seperti sebagian besar WJM 3 lewat `## Nama item khusus`?
7. **Nama stasiun** — Usulan mengikuti LIVE WJM 3: "Dapur Minuman" (minuman) dan "Dapur Makanan" (makanan). Sudah benar untuk WJM 2?
8. **Kategori tanpa item** — Setelah ekspor lengkap (nasi, roti, pisang, teh, kopi signature, dll.), kategori itu akan dibuat. Perlu ekspor ulang kapan?

## Pemetaan per item

Yakin: **T** = tinggi, **S** = sedang, **R** = rendah (perlu keputusan pemilik). "Standard" = satu varian tanpa atribut.

### MAKANAN (Dapur) — tanpa item

### MIE → Mie (Dapur)

| Item | Harga (Rp) | Varian usulan | Yakin | Catatan |
|---|---|---|---|---|
| INDOMIE DOUBLE | 15.000 | VARIANT INDOMIE: Goreng Original, Rendang, Mie Aceh, Soto, Ayam Bawang, Kari Ayam | S | Rasa mengikuti WJM 3 (usulan) |
| INDOMIE GORENG | 8.000 | VARIANT INDOMIE: Goreng Original, Rendang, Mie Aceh | S | Rasa mengikuti WJM 3 (usulan) |
| INDOMIE KUAH | 8.000 | VARIANT INDOMIE: Soto, Ayam Bawang, Kari Ayam | S | Rasa mengikuti WJM 3 (usulan) |
| MIE SEDAP DOUBLE | 15.000 | VARIANT MIE SEDAP: ?, ?, ?, ?, ?, ? | R | Nama varian belum diketahui — pemilik mengisi |
| MIE SEDAP GORENG | 8.000 | Standard | T |  |
| MIE SEDAP KUAH | 8.000 | VARIANT MIE SEDAP: ?, ?, ?, ?, ? | R | Nama varian belum diketahui — pemilik mengisi |
| OMELETTE MIE | 13.000 | Standard | S | Hidangan mie (halaman 2) |

### COFFEE → Coffee (Bar)

| Item | Harga (Rp) | Varian usulan | Yakin | Catatan |
|---|---|---|---|---|
| ABC KLEPON | 7.000 | Varian: Hot, Ice | S | Hot/Ice mengikuti WJM 3 (usulan) |
| ABC MOCCA | 0 | Varian: Hot, Ice | R | Hot/Ice mengikuti WJM 3 (usulan); harga 0 — pemilik menetapkan |
| ABC SUSU | 5.000 | Varian: Hot, Ice | S | Hot/Ice mengikuti WJM 3 (usulan) |
| FREEZE COOKIES AND CREAM | 7.000 | Varian: Hot, Ice | S | Hot/Ice mengikuti WJM 3 (usulan) |
| FREEZE HAZELNUT | 7.000 | Varian: Hot, Ice | S | Hot/Ice mengikuti WJM 3 (usulan) |
| FREEZE MOCHAFRIO | 7.000 | Varian: Hot, Ice | S | Hot/Ice mengikuti WJM 3 (usulan) |
| GOOD DAY CAPPUCHINO | 7.000 | Varian: Hot, Ice | S | Hot/Ice mengikuti WJM 3 (usulan) |
| GOOD DAY CHOCOCINO | 7.000 | Varian: Hot, Ice | S | Hot/Ice mengikuti WJM 3 (usulan) |
| GOODDAY MOCHACINO | 7.000 | Varian: Hot, Ice | S | Hot/Ice mengikuti WJM 3 (usulan) |
| INDOCAFE | 7.000 | Varian: Hot, Ice | S | Hot/Ice mengikuti WJM 3 (usulan) |
| KAPAL API | 5.000 | Varian: Hot, Ice | S | Hot/Ice mengikuti WJM 3 (usulan) |
| KAPAL API KOPI SUSU | 0 | Varian: Hot, Ice | R | Hot/Ice mengikuti WJM 3 (usulan); harga 0 — pemilik menetapkan |
| KAPAL API PAHIT | 5.000 | Varian: Hot, Ice | S | Hot/Ice mengikuti WJM 3 (usulan) |
| KOPI LIONG | 5.000 | Varian: Hot, Ice | S | Hot/Ice mengikuti WJM 3 (usulan) |
| LUWAK WHITE COFFEE | 5.000 | Varian: Hot, Ice | S | Hot/Ice mengikuti WJM 3 (usulan) |
| GOLDA | 5.000 | Standard | R | Minuman kopi kemasan atau camilan? |
| NESCAFFE AMERICANO | 5.000 | Varian: Hot, Ice | S | Hot/Ice mengikuti WJM 3 (usulan); halaman 2 tanpa kolom jumlah varian |
| TOP CAPPUCINO | 0 | Varian: Hot, Ice | R | Hot/Ice mengikuti WJM 3 (usulan); halaman 2 tanpa kolom jumlah varian; harga 0 — pemilik menetapkan |

### NON-COFFEE → Non Coffee (Bar)

| Item | Harga (Rp) | Varian usulan | Yakin | Catatan |
|---|---|---|---|---|
| AIR MINERAL | 5.000 | Standard | T |  |
| AIR MINERAL 1.5L | 10.000 | Standard | T |  |
| AIR PUTIH PANAS | 2.000 | Standard | T |  |
| ALL VARIANT MINUMAN DINGIN(SHOWCASE) | 0 | VARIANT MINUMAN DINGIN SHOWCASE: ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ? | R | Nama varian belum diketahui — pemilik mengisi; harga 0 — pemilik menetapkan |
| CHOCOLATOS COKLAT | 7.000 | Varian: Hot, Ice | S | Hot/Ice mengikuti WJM 3 (usulan) |
| CHOCOLATOS MATCHA | 7.000 | Varian: Hot, Ice | S | Hot/Ice mengikuti WJM 3 (usulan) |
| DANCOW COKLAT | 8.000 | Varian: Hot, Ice | S | Hot/Ice mengikuti WJM 3 (usulan) |
| DANCOW VANILA | 8.000 | Varian: Hot, Ice | S | Hot/Ice mengikuti WJM 3 (usulan) |
| DRINK BENG BENG | 7.000 | Varian: Hot, Ice | S | Hot/Ice mengikuti WJM 3 (usulan) |
| ENERGEN | 7.000 | Standard | R | Minuman sereal; Non Coffee atau Coffee? |
| ES JERUK | 12.000 | Standard | R | WJM 3: Non Coffee |
| EXTRAJOSS | 7.000 | Standard | T |  |
| EXTRAJOSS SUSU | 10.000 | Standard | T |  |
| JASJUS ALL VARIANT | 5.000 | JASJUS: ?, ?, ?, ? | R | Nama varian belum diketahui — pemilik mengisi |
| KUKUBIMA | 7.000 | Standard | T |  |
| KUKUBIMA SUSU | 10.000 | Standard | T |  |
| LARUTAN CAP KAKI 3 | 5.000 | Standard | R | Minuman kemasan |
| MARIMAS ALL VARIANT | 5.000 | Standard | T |  |
| MILO | 8.000 | Varian: Hot, Ice | S | Hot/Ice mengikuti WJM 3 (usulan) |
| NUTRISARI ALL VARIANT | 7.000 | NUTRISARI: ? | R | Nama varian belum diketahui — pemilik mengisi (halaman 2) |
| SUSU COKLAT | 0 | Varian: Hot, Ice | R | Hot/Ice mengikuti WJM 3 (usulan); halaman 2 tanpa kolom jumlah varian; harga 0 — pemilik menetapkan |
| SUSU JAHE | 0 | Varian: Hot, Ice | R | Hot/Ice mengikuti WJM 3 (usulan); halaman 2 tanpa kolom jumlah varian; harga 0 — pemilik menetapkan |
| SUSU PUTIH | 0 | Varian: Hot, Ice | R | Hot/Ice mengikuti WJM 3 (usulan); halaman 2 tanpa kolom jumlah varian; harga 0 — pemilik menetapkan |
| SUSU SODA | 10.000 | Standard | S | Halaman 2 |

### SNACKS → Snacks (Dapur)

| Item | Harga (Rp) | Varian usulan | Yakin | Catatan |
|---|---|---|---|---|
| BAKSO ACI + TELOR | 15.000 | Standard | T |  |
| BASRENG | 6.000 | Standard | R | WJM 3 menaruhnya di Snacks; harga seperti camilan ringan |
| CIRENG ISI AYAM SUIR | 15.000 | Standard | T |  |
| CIRENG RUJAK | 13.000 | Standard | T |  |
| DIMSUM | 16.000 | Standard | T |  |
| DIMSUM GORENG KEJU LUMER | 20.000 | Standard | T |  |
| DIMSUM GORENG KEJU LUMER SAUS MENTAI | 25.000 | Standard | T |  |
| DIMSUM ISI 6 | 23.000 | Standard | T |  |
| DIMSUM MENTAI ISI 4 | 22.000 | Standard | T |  |
| DIMSUM MENTAI ISI 6 | 30.000 | Standard | T |  |
| KENTANG GORENG | 13.000 | Standard | T |  |
| MARTABAK TELUR KORNET MINI | 20.000 | Standard | T |  |
| MIX PLATTER | 20.000 | Standard | T |  |
| OTAK-OTAK GORENG | 13.000 | Standard | S | WJM 3 menaruh item ini di Snacks |
| PANGSIT GORENG ISI 5 | 15.000 | Standard | S | WJM 3 menaruh item ini di Snacks |
| SOSIS BAKAR | 13.000 | Standard | S | WJM 3 menaruh item ini di Snacks |

### TEA → Tea (Bar)

| Item | Harga (Rp) | Varian usulan | Yakin | Catatan |
|---|---|---|---|---|
| LEMON TEA | 7.000 | Varian: Hot, Ice | S | Hot/Ice mengikuti WJM 3 (usulan) |
| TEAJUS ALL VARIANT | 5.000 | TEAJUS: ? | R | Nama varian belum diketahui — pemilik mengisi (halaman 2) |
| TEH MANIS | 5.000 | Varian: Hot, Ice | S | Hot/Ice mengikuti WJM 3 (usulan); halaman 2 tanpa kolom jumlah varian |
| TEH TARIK | 7.000 | Varian: Hot, Ice | S | Hot/Ice mengikuti WJM 3 (usulan); halaman 2 tanpa kolom jumlah varian |
| TEH TAWAR | 5.000 | Varian: Hot, Ice | S | Hot/Ice mengikuti WJM 3 (usulan); halaman 2 tanpa kolom jumlah varian |

### ADD ON → Add on (Dapur)

| Item | Harga (Rp) | Varian usulan | Yakin | Catatan |
|---|---|---|---|---|
| ES BATU | 2.000 | Standard | T |  |
| KEJU | 5.000 | Standard | R | Tambahan untuk mie/roti |
| KORNET | 5.000 | Standard | R | Tambahan untuk mie |
| TELOR | 5.000 | Standard | S | Tambahan untuk mie/roti (halaman 2) |

### LAIN LAIN → Lain Lain (Dapur)

| Item | Harga (Rp) | Varian usulan | Yakin | Catatan |
|---|---|---|---|---|
| SO FRESH (FRESHCARE) | 15.000 | Standard | R | Bukan makanan/minuman? Kategori Lain Lain baru; stasiun Dapur usulan |
| TISSUE TRAVEL | 5.000 | Standard | S | Barang non-menu |
| TOLAK ANGIN | 5.000 | Standard | S | Obat herbal sachet |

### MIE & PANGSIT LEVEL → Mie & Pangsit Level (Dapur)

| Item | Harga (Rp) | Varian usulan | Yakin | Catatan |
|---|---|---|---|---|
| MIE LEVEL 1-2 | 15.000 | Standard | T |  |
| MIE LEVEL 3-4 | 17.000 | Standard | T |  |
| PANGSIT CHILLIE OIL LEVEL 1-2 | 15.000 | Standard | T |  |
| PANGSIT CHILLIE OIL LEVEL 3-4 | 17.000 | Standard | T |  |

### NASI (Dapur) — tanpa item

### ROTI & PISANG → Roti & Pisang (Dapur)

| Item | Harga (Rp) | Varian usulan | Yakin | Catatan |
|---|---|---|---|---|
| PISANG BAKAR COKLAT | 13.000 | Standard | T | Kategori bersama dengan WJM 3 |
| PISANG BAKAR COKLAT KEJU | 15.000 | Standard | T | Kategori bersama dengan WJM 3 |
| PISANG BAKAR KEJU | 13.000 | Standard | T | Kategori bersama dengan WJM 3 |
| PISANG GORENG COKLAT KEJU | 15.000 | Standard | T | Kategori bersama dengan WJM 3 |
| PISANG GORENG ORIGINAL | 13.000 | Standard | T | Kategori bersama dengan WJM 3 |
| PISCOK LUMER COKLAT KEJU | 15.000 | Standard | T | Kategori bersama dengan WJM 3 |
| PISCOK LUMER ORIGINAL | 13.000 | Standard | T | Kategori bersama dengan WJM 3 |
| ROTI BAKAR COKLAT | 13.000 | Standard | T | Kategori bersama dengan WJM 3 |
| ROTI BAKAR COKLAT KEJU | 15.000 | Standard | T | Kategori bersama dengan WJM 3 |
| ROTI BAKAR KEJU | 13.000 | Standard | T | Kategori bersama dengan WJM 3 |

### SIGNATURE COFFEE (Bar) — tanpa item

### SNACK RINGAN → Snack Ringan (Dapur)

| Item | Harga (Rp) | Varian usulan | Yakin | Catatan |
|---|---|---|---|---|
| ASTOR | 5.000 | Standard | T |  |
| BENG BENG | 4.000 | Standard | R | Camilan (bukan Drink Beng Beng) |
| CHACHA | 2.000 | Standard | T |  |
| CHUBA | 2.000 | Standard | T |  |
| CIKI | 3.000 | Standard | T |  |
| GERY | 4.000 | Standard | T |  |
| JELLY | 5.000 | Standard | R | Camilan atau minuman? |
| KACANG KULIT BESAR | 7.000 | Standard | T |  |
| KACANG KULIT KECIL | 5.000 | Standard | T |  |
| KERIPIK PANGSIT BAWANG | 10.000 | Standard | T |  |
| KERIPIK PISANG | 10.000 | Standard | T |  |
| KERIPIK SINGKONG SEDANG | 7.000 | Standard | T |  |
| KERUPUK KALENG | 3.000 | Standard | R | WJM 3 menaruh kerupuk di Add on |
| KERUPUK KULIT | 5.000 | Standard | R | WJM 3 menaruh kerupuk di Add on |
| KERUPUK SEBLAK | 5.000 | Standard | R | WJM 3 menaruh kerupuk di Add on |
| KERUPUK TAHU | 6.000 | Standard | R | WJM 3 menaruh kerupuk di Add on |
| KUACI | 2.000 | Standard | T |  |
| MAKARONI | 5.000 | Standard | T |  |
| MAKARONI BON CABE | 4.000 | Standard | T |  |
| MALKIST ROMA | 2.000 | Standard | T |  |
| MOMOGI | 2.000 | Standard | T |  |
| MONDE | 5.000 | Standard | T |  |
| NABATI | 2.000 | Standard | T |  |
| NORI ROLL | 3.000 | Standard | T |  |
| NYAM NYAM | 3.000 | Standard | T |  |
| OREO MINI | 3.000 | Standard | T |  |
| PEYEK KACANG | 3.000 | Standard | R | WJM 3 menaruh kerupuk/peyek di Add on |
| PILUS IKAN | 5.000 | Standard | T |  |
| POCKY | 6.000 | Standard | T |  |
| REGAL | 2.000 | Standard | T |  |
| ROTI AOKA | 5.000 | Standard | R | Roti kemasan: Snack Ringan atau Roti & Pisang? |
| ROTI BAGELEN KERING | 12.000 | Standard | R | Roti kering kemasan: Snack Ringan atau Roti & Pisang? |
| SARI GANDUM | 4.000 | Standard | T |  |
| SINGKONG BALADO | 5.000 | Standard | T |  |
| STICK KEJU | 5.000 | Standard | T |  |
| SUKRO | 2.000 | Standard | T |  |
| SUKRO OVEN | 2.000 | Standard | T |  |
| SUPERSTAR | 2.000 | Standard | T |  |
| TICTAC | 2.000 | Standard | T |  |

## Item ditunda

Item yang belum dibuat karena nama variannya belum diketahui. Tetap dihitung sebagai produk dikecualikan (alasan "Ditunda: …"). Setelah pemilik mengisi nama varian (ganti `?` di tabel item dan isi `## Nilai atribut`), hapus barisnya dari sini, bangkitkan ulang katalog, lalu jalankan pengisian lagi: hanya item yang baru dibuat.

| Item | Alasan |
|---|---|
| JASJUS ALL VARIANT | nama 4 varian belum diisi pemilik |
| ALL VARIANT MINUMAN DINGIN(SHOWCASE) | nama 19 varian dan harga (Rp0) belum diisi pemilik |
| MIE SEDAP KUAH | nama 5 varian belum diisi pemilik |
| MIE SEDAP DOUBLE | nama 6 varian belum diisi pemilik |
| NUTRISARI ALL VARIANT | nama rasa belum diisi pemilik (halaman 2 tanpa jumlah varian) |
| TEAJUS ALL VARIANT | nama rasa belum diisi pemilik (halaman 2 tanpa jumlah varian) |

## Nilai atribut

Ekspor atribut tidak memuat nilai. Nilai yang dapat disimpulkan ada di sini; atribut yang variannya masih `?` tidak perlu ditulis.

| Atribut | Nilai |
|---|---|
| Varian | Hot, Ice |
| VARIANT INDOMIE | Goreng Original, Rendang, Mie Aceh, Soto, Ayam Bawang, Kari Ayam |

## Harga varian khusus

Varian yang harganya berbeda dari harga item. Tanpa baris berarti semua varian memakai harga item.

| Item | Varian | Harga (Rp) |
|---|---|---|

## Nama item khusus

Item yang namanya diganti dari nama sumber. Kolom "Item sumber" memakai nama sumber yang sudah dirapikan spasinya.

| Item sumber | Nama |
|---|---|
| MARIMAS ALL VARIANT | MARIMAS |

## Nama varian khusus

| Item | Varian | Nama |
|---|---|---|

## Foto pengganti

Foto untuk item yang fotonya terpotong di ekspor XLSX (69 dari 80 item). Jalurnya relatif terhadap folder tabel ini.

| Item | Berkas |
|---|---|

## Grup pilihan

Tanpa baris: tidak ada grup pilihan (tidak ada atribut "opsi saja" di ekspor ini).

| Grup | Item |
|---|---|

## Dikecualikan

| Baris sumber | Alasan |
|---|---|
| Atribut yang tidak dipakai item mana pun (Varian Kentang, Varian Indomie, VARIAN ROTI, Mentai, VARIAN SNACK, NUTRISARI, INDOMIE KUAH, Varian signature coffee) | Dihitung otomatis oleh pembangkit |
| Kategori tanpa item (MAKANAN, NASI, SIGNATURE COFFEE) | Belum ada item di dua halaman ekspor produk |
| Partial Payment (`nf_partial_payment`) | Produk teknis Odoo, bukan menu; dikecualikan otomatis oleh pembangkit |
