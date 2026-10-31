**Klasifikasi**: INTERNAL

# Research: Sel Satu Baris di Daftar Pre-order (053)

Semua temuan di bawah berasal dari membaca kode di branch `084-preorder-list-oneline-cells` (ditumpuk di atas
`083-preorder-detail-prev-next`), bukan asumsi. Tidak ada NEEDS CLARIFICATION yang tersisa.

## R1 — Mengapa nomor PO dan Total bisa patah baris

**Temuan**: `DataTable.vue` merender setiap sel sebagai `<td class="px-4 py-3.5 align-middle text-[13.5px]">` TANPA
`whitespace-nowrap` (hanya `<th>` yang punya). Akibatnya:
- Nomor pre-order `PO-20260927-0010` boleh dipatah peramban **setelah tanda hubung** (peluang patah baris standar).
- `formatIDR()` (`utils/money.js`) menghasilkan `` `Rp ${...}` `` dengan **spasi biasa**, jadi `Rp` dan angkanya bisa
  terpisah ke dua baris.

**Decision**: Tambahkan `whitespace-nowrap` di isi slot sel `preorder_number` dan `total_amount` di `PreordersView.vue`
saja. `white-space: nowrap` meniadakan SEMUA peluang patah baris lunak, termasuk setelah tanda hubung, jadi satu kelas
menyelesaikan keduanya. Tidak ada elipsis/`truncate` (FR-001/FR-003: isi tidak boleh terpotong); bila tabel kurang
lebar, `DataTable` sudah `overflow-auto` sehingga menggulir horizontal.

**Alternatives considered**:
- *Ganti spasi `formatIDR` menjadi spasi tak-putus (U+00A0)* — ditolak: fungsi ini dipakai di seluruh aplikasi
  (struk, invoice, ekspor PDF, tes yang mencocokkan teks `Rp 1.000`); perubahan global untuk masalah satu kolom.
- *Opsi `nowrap` per kolom di `DataTable`* — ditolak untuk fitur ini: `DataTable` dipakai di banyak layar, dan dua sel
  yang sudah punya slot kustom cukup diberi kelas langsung (pola yang sama dengan sel `created_at`/`updated_at` di file
  yang sama, yang sudah `whitespace-nowrap`).
- *`truncate` + tooltip nomor lengkap* — ditolak: spec meminta nomor utuh, dan nomor adalah tombol pembuka detail.

## R2 — Tooltip tanggal: pakai `BaseTooltip` yang ada

**Temuan**: `components/ui/BaseTooltip.vue` (037/038) sudah memenuhi FR-007/FR-008: muncul saat hover DAN fokus
keyboard, Escape menutup, gelembung di-teleport ke `<body>` dengan `position: fixed` sehingga tidak terpotong
`overflow-auto` milik `DataTable`, tidak muncul untuk teks kosong. Dipakai di `ProductsView`, `SettlementDetailView`,
dan `PreordersView` (tombol navigasi 052).

**Decision**: Sel `dispatch_status` menjadi satu baris `inline-flex`: `StatusPill` + (bila ada minimal satu tanggal)
`BaseTooltip` yang membungkus `<button type="button">` berikon `ph-info`. Atribut `title` native tidak dipakai (tidak
bisa dicapai keyboard — aturan CLAUDE.md 037).

**Alternatives considered**: popover klik (lebih berat, butuh state buka/tutup sendiri); `title` native (tidak
aksesibel keyboard).

## R3 — Dua tanggal di baris terpisah dalam satu tooltip

**Temuan**: `BaseTooltip` menerima satu prop `text` (String) dan merendernya sebagai `{{ text }}` di `<span>` tanpa
`white-space: pre-line`, jadi `\n` dirender sebagai spasi.

**Decision**: Tambahkan kelas `whitespace-pre-line` pada gelembung `BaseTooltip`; teks dari daftar digabung dengan
`\n`. Aman untuk pemanggil lain: `pre-line` hanya mengubah tampilan bila teks memuat baris baru (tidak ada teks
pemanggil yang ada yang memuatnya) dan tetap menggabungkan spasi berderet seperti `normal`. Satu tes `BaseTooltip`
baru mengunci perilaku ini.

**Alternatives considered**: slot konten di `BaseTooltip` (perubahan API komponen bersama + dua jalur render untuk
dipertahankan); dua tooltip terpisah (dua ikon — bertentangan dengan "ikon info di samping status").

## R4 — Label aksesibel

**Decision**: Tombol ikon memakai `aria-label` = teks tanggal yang sama (digabung `. `), sehingga pembaca layar
mengumumkan isi tanggal saat tombol difokuskan (FR-007). `aria-describedby` milik `BaseTooltip` ada di pembungkus
`<span>`, bukan elemen yang difokus, jadi tidak diandalkan sendirian. Teks memakai kunci locale yang SUDAH ada
(`preorders.dispatch_invoice_sent_on` / `dispatch_shipping_on`) dan `formatDateTime` yang sama → **tidak ada kunci
locale baru** (FR-011 terpenuhi karena kedua bahasa sudah ada).

## R5 — Satu sumber teks tanggal

**Decision**: Satu fungsi kecil di `PreordersView.vue`, `dispatchDatesLines(row)`, mengembalikan baris-baris tanggal
yang ada sebagai array (urutan invoice → pengiriman), `[]` bila tidak ada. Dipakai untuk `v-if` ikon, prop
`text` tooltip (gabung `\n`), dan `aria-label` (gabung `. `). Panel detail tetap memakai teksnya sendiri (FR-010: tidak
berubah).

## R6 — Dampak pada tes yang ada

`qa-tests/component/PreordersView.test.js` — tes *"shows the invoice-sent and shipping dates inside the existing status
cell (no extra column)"* mengharapkan teks tanggal ada di dalam `<tr>`. Setelah perubahan, teks itu berada di gelembung
yang di-teleport ke `<body>`, jadi tes ini **sengaja ditulis ulang** (bukan dihapus) untuk memverifikasi perilaku baru:
baris tidak memuat teks tanggal, ikon info ada dengan `aria-label` berisi kedua tanggal, dan fokus pada ikon
menampilkan `role="tooltip"` dengan kedua tanggal di baris terpisah. Tes panel detail tidak berubah.

## R7 — Verifikasi di peramban nyata

Konstitusi II mewajibkan pemeriksaan di peramban nyata. Yang dicek: lebar 1280 px semua kolom default, nomor/total
satu baris, tinggi baris seragam, tooltip muncul via hover dan Tab, tidak terpotong di baris terakhir, konsol bersih.
Batasan "lebar nowrap" hanya bisa dibuktikan di peramban nyata (jsdom tidak melakukan layout), jadi tes komponen hanya
mengunci kelas/struktur.
