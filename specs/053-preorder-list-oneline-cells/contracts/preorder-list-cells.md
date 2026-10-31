**Klasifikasi**: INTERNAL

# UI Contract: Sel Daftar Pre-order (053)

Tidak ada kontrak API baru/berubah — `docs/openapi-pos-mvp.yaml` TIDAK disentuh. Kontrak di bawah adalah perilaku UI
yang dikunci oleh tes komponen (`qa-tests/component/PreordersView.test.js`, `BaseTooltip.test.js`).

## Sel `preorder_number`

- Pembungkus sel memuat kelas `whitespace-nowrap`.
- Isi: [tombol bendera bila `needsShippingAttention(row)`] + tombol nomor (`@click="openDetail(row)"`) — tidak berubah.

## Sel `total_amount`

- `<span class="whitespace-nowrap">{{ formatIDR(row.total_amount) }}</span>`; teks persis sama dengan sebelumnya.

## Sel `dispatch_status`

- Satu baris: `inline-flex items-center gap-1.5 whitespace-nowrap`.
- `StatusPill` dengan label/varian seperti sebelumnya.
- TIDAK ada lagi `<span>` teks tanggal di dalam sel (baris `<tr>` tidak memuat "Invoice terkirim:" / "Pengiriman mulai:").
- Bila `dispatchDatesLines(row).length > 0`:
  - `BaseTooltip :text="dispatchDatesLines(row).join('\n')"` membungkus
    `<button type="button" data-testid="dispatch-dates-info" :aria-label="…">` berisi `<i class="ph-duotone ph-info" aria-hidden="true">`.
  - `aria-label` = baris-baris tanggal digabung `. `.
  - Fokus/hover → elemen `role="tooltip"` (di `<body>`) memuat baris invoice lalu pengiriman, dipisah `\n`.
  - Escape / blur / mouseleave → tooltip tertutup (perilaku `BaseTooltip` yang ada).
- Bila array kosong → tidak ada tombol/ikon.

## `BaseTooltip` (komponen bersama)

- Gelembung mendapat kelas `whitespace-pre-line`: `\n` di `text` menjadi baris baru; teks tanpa `\n` tampil sama
  seperti sebelumnya. Prop/emit/slot tidak berubah.

## Tidak berubah

Panel detail (tanggal tetap teks), ekspor Excel, dokumen cetak, kolom Outstanding, pengurutan, filter, seleksi, aksi
baris, navigasi sebelumnya/berikutnya (052).
