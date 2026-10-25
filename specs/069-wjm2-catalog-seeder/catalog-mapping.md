# Tabel pemetaan katalog WJM 2 (usulan)

**Klasifikasi**: CONFIDENTIAL — katalog nyata milik pelanggan (Warkop WJM).

**Status**: Disetujui — semua usulan di tabel ini diterima apa adanya, kecuali empat item yang ditunda karena nama variannya belum diketahui (lihat `## Item ditunda`). Termasuk: harga Rp0 untuk ABC MOCCA dan KAPAL API KOPI SUSU, Hot/Ice berharga sama untuk 22 item, rasa Indomie mengikuti WJM 3, kategori baru "Mie" dan "Snack Ringan", nama item huruf kapital seperti Odoo, stasiun "Dapur Minuman"/"Dapur Makanan", dan stok awal 100 (spec FR-015).

**Disetujui oleh (peran)**: Pemilik · **Tanggal**: 2026-10-25

**Outlet**: Warkop WJM 2 · **Kode usulan**: WJ2, WD2 · **Buat outlet**: DEMO bila belum ada

**Tarik item lain dari menu outlet**: LIVE ya, DEMO ya

**Stasiun**: Bar = Dapur Minuman, Bar; Dapur = Dapur Makanan, Dapur

**Stok awal**: 100

**Sumber**: tiga ekspor Odoo WJM 2 ([source-analysis.md](./source-analysis.md)). Kolom kategori dan varian di bawah adalah **inferensi** dari nama item, jumlah varian Odoo, dan pola WJM 3. Inferensi ini belum diverifikasi. Ekspor produk berhenti di 80 baris (A–M); item lain ditambahkan kelak dengan jalan ulang.

## Ringkasan usulan

| Kategori sumber | Kategori tujuan | Stasiun | Item | Varian |
|---|---|---|---|---|
| MAKANAN | — (tanpa item) | Dapur | 0 | 0 |
| MIE | Mie | Dapur | 6 | 24 |
| COFFEE | Coffee | Bar | 16 | 31 |
| NON-COFFEE | Non Coffee | Bar | 19 | 46 |
| SNACKS | Snacks | Dapur | 13 | 13 |
| TEA | Tea | Bar | 1 | 2 |
| ADD ON | Add on | Dapur | 3 | 3 |
| LAIN LAIN | — (tanpa item) | Dapur | 0 | 0 |
| MIE & PANGSIT LEVEL | Mie & Pangsit Level | Dapur | 2 | 2 |
| NASI | — (tanpa item) | Dapur | 0 | 0 |
| ROTI & PISANG | — (tanpa item) | Dapur | 0 | 0 |
| SIGNATURE COFFEE | — (tanpa item) | Bar | 0 | 0 |
| SNACK RINGAN | Snack Ringan | Dapur | 20 | 20 |
| **Total** | | | **80** | **141** |

- Kategori tujuan yang namanya sudah ada di mode itu (Coffee, Non Coffee, Tea, Snacks, Add on, Mie & Pangsit Level) **dipakai bersama** dengan WJM 3 dan tidak diubah. "Snack Ringan" dan "Mie" baru. Tiket item WJM 2 tetap masuk ke stasiun WJM 2 karena stasiun diset per item.
- Lima kategori sumber tidak mendapat item karena ekspor berhenti di huruf M: MAKANAN, LAIN LAIN, NASI, ROTI & PISANG, SIGNATURE COFFEE.
- Harga setiap varian = harga item, kecuali tertulis di `## Harga varian khusus`.
- Nama varian bertanda `?` belum diketahui (34 varian: JASJUS ×4, MINUMAN DINGIN SHOWCASE ×19, MIE SEDAP KUAH ×5, MIE SEDAP DOUBLE ×6). Pembangkit memberi nama sementara "(belum diisi n)" selama status DRAF dan **menolak** status Disetujui bila masih ada.

## Pertanyaan untuk pemilik

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

### TEA → Tea (Bar)

| Item | Harga (Rp) | Varian usulan | Yakin | Catatan |
|---|---|---|---|---|
| LEMON TEA | 7.000 | Varian: Hot, Ice | S | Hot/Ice mengikuti WJM 3 (usulan) |

### ADD ON → Add on (Dapur)

| Item | Harga (Rp) | Varian usulan | Yakin | Catatan |
|---|---|---|---|---|
| ES BATU | 2.000 | Standard | T |  |
| KEJU | 5.000 | Standard | R | Tambahan untuk mie/roti |
| KORNET | 5.000 | Standard | R | Tambahan untuk mie |

### LAIN LAIN (Dapur) — tanpa item

### MIE & PANGSIT LEVEL → Mie & Pangsit Level (Dapur)

| Item | Harga (Rp) | Varian usulan | Yakin | Catatan |
|---|---|---|---|---|
| MIE LEVEL 1-2 | 15.000 | Standard | T |  |
| MIE LEVEL 3-4 | 17.000 | Standard | T |  |

### NASI (Dapur) — tanpa item

### ROTI & PISANG (Dapur) — tanpa item

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

## Item ditunda

Item yang belum dibuat karena nama variannya belum diketahui. Tetap dihitung sebagai produk dikecualikan (alasan "Ditunda: …"). Setelah pemilik mengisi nama varian (ganti `?` di tabel item dan isi `## Nilai atribut`), hapus barisnya dari sini, bangkitkan ulang katalog, lalu jalankan pengisian lagi: hanya item yang baru dibuat.

| Item | Alasan |
|---|---|
| JASJUS ALL VARIANT | nama 4 varian belum diisi pemilik |
| ALL VARIANT MINUMAN DINGIN(SHOWCASE) | nama 19 varian dan harga (Rp0) belum diisi pemilik |
| MIE SEDAP KUAH | nama 5 varian belum diisi pemilik |
| MIE SEDAP DOUBLE | nama 6 varian belum diisi pemilik |

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
| Kategori tanpa item (MAKANAN, LAIN LAIN, NASI, ROTI & PISANG, SIGNATURE COFFEE) | Ekspor produk berhenti di huruf M |
