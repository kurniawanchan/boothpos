**Classification**: INTERNAL

# Bukti US4 — persetujuan, void, batal, 86, EDC, QRIS (2026-10-16)

## T056 — kode klien (build lulus)
- core-network: `dto/ActionDtos.kt` (baru), `dto/TabDtos.kt` (PaymentInputDto: approval_code, confirm_duplicate, channel_id), `dto/ShiftDtos.kt`, `PosApi.kt` (DELETE void dengan badan via @HTTP, cancel, sold-out, verify, fail, reason-codes, payment-channels), `ApiErrorMapper.kt` (duplicate_edc, pending_qris, has_payments, kode persetujuan).
- Tes: `ActionDtosTest.kt` (8 baru), total core-network 53 tes, 0 gagal.
- app: `data/PosBackend.kt`, `data/RemoteBackend.kt`, `model/PosModels.kt` (ReasonCode), `model/PosCatalog.kt` (pemetaan alasan→kode server), `state/PosStore.kt` (voidLine, cancelOrder, payEdc, recordQris, verifyQris, failQris, markSoldOut), `ui/screens/EditorScreen.kt` (tahan kartu → markSoldOut).
- Subjek persetujuan (sesuai kontrak): void_line = tab_id, line_id, reason_code, kitchen_status, stock_decision; cancel_order = tab_id, reason_code; availability = product_id, reason_code_id; qris_verify = tab_id, payment_id, reference; qris_fail = tab_id, payment_id; cash_out = session_id, direction, amount, reason_code; variance = session_id, counted_by_denomination. Field yang tidak dikirim juga tidak ada di subjek.
- Coba-ulang setelah ditolak memakai Idempotency-Key baru (kunci dilepas sebelum dialog dibuka).
- 86 (pemilih alasan, belum di-commit): `ui/Sheets.kt` `SoldOutDialog` memuat `GET /reason-codes?category=sold_out` (4 alasan aktif di dev). Daftar kosong menampilkan pesan dan tidak mengirim apa pun; tidak ada id alasan cadangan. Tombol "Tandai habis" aktif hanya setelah alasan dipilih.
- Build: `:app:assembleDebug` BUILD SUCCESSFUL; `:core-network:testDebugUnitTest` 53 tes, 0 gagal.

## T057 — skenario emulator (2026-10-16, sekitar 11:16–11:21 WIB, outlet 4)
Status: **BELUM LULUS** — T057 tetap `[ ]`. Emulator `wjm_tablet` berjalan dan terhubung ke server (10.0.2.2:8010); disk bebas ~4,8 GiB selama run.

- **Langkah 1 — void baris terkirim: LULUS sebagian.**
  - Shift 10 dibuka kasir (saldo awal 150.000). Tab 2039 (Counter A-017): baris 6039 Kopi Tubruk Rp 8.000 dan 6040 Kopi Susu Rp 12.000, dikirim ke dapur.
  - Void 6040 ("Salah input", "Belum dibuat"): DELETE → 409 override_required → penyetuju Sari (PIN dimasukkan di pad) → POST /overrides 201 → DELETE item 200.
  - DB: baris 6040 hilang; `overrides` id 1 = void_line, requested_by 8, approved_by 11, consumed_at terisi, consumed_by_ref `order:2039:line:6040`, data_mode demo.
  - Pengembalian stok: **TIDAK TERVERIFIKASI.** Kopi Susu (variant 35) dan Kopi Tubruk (variant 36) `track_stock=false` (dibuat sesuai pesanan), tidak ada `stock_movements`. Tidak ada varian menu POS yang dilacak stoknya. Mengubah `track_stock` adalah perubahan data dev dan butuh persetujuan.
- **Langkah 2 — approve sendiri → same_person:** dicakup tes `tests/Feature/OverrideGateTest.php`; tidak dijalankan di emulator.
- **Langkah 3 — QRIS pending menahan EDC dan penutupan: GAGAL (diblokir).**
  - Tidak ada channel `qr_ewallet` di `payment_channels` (hanya BCA dan Mandiri, keduanya `bank_transfer`). Klien menampilkan "Kanal QRIS belum diatur di server"; tidak ada pembayaran dibuat.
  - Belum dicoba: QRIS pending, EDC ditahan (pending_qris), penutupan shift (pending_payments), verifikasi QRIS.
- **Langkah 4 — EDC kode sama → duplicate_edc → konfirmasi: GAGAL (diblokir).**
  - Pembayaran EDC ditolak server: "A payment channel is required for non-cash methods." `PosStore.payEdc` mengirim `channel_id = null` (`b.pay(..., code, confirmDuplicate, null)`), dan dev DB tidak punya channel EDC. Akibatnya uji duplicate_edc belum sampai ke logika duplikat.
  - Dicatat: kolom kode approval sempat menerima karakter tambahan dari keyboard. Dibersihkan manual, tidak tersimpan.
- **Residu** (dibuat run ini):
  - Shift 10 (kasir Rina) — OPEN.
  - Tab 2039 (Counter A-017) — OPEN; baris 6039 terkirim Rp 8.000; `paid_amount` 0; tidak ada baris `payments`.
  - `overrides` id 1 — void_line, consumed.
  - Tidak dibuat: pembayaran, QRIS, tab kedua, channel pembayaran.
  - Cache: kunci `setting:system_mode` dihapus sekali (hanya cache, bukan data).
  - Tidak disentuh: sesi 6 (8 tab terbuka, bukan milik run ini).

## Belum diverifikasi
- Pengembalian stok void (butuh varian `track_stock=true` di menu POS, atau persetujuan mengubah data dev).
- Langkah 3 seluruhnya dan langkah 4 seluruhnya (butuh channel `qr_ewallet` dan channel EDC di dev DB, serta keputusan apakah `payEdc` harus mengirim `channel_id`).
- Pemilih alasan 86 belum diuji di emulator.

## Koreksi induk (2026-10-16 10:5x WIB) — usang
- Bagian ini mencatat pemeriksaan disk sebelum run. Status terbaru ada di bagian T057 di atas.
- Docker: build cache 7,3 GB (0 aktif), volume 12,5 GB. Tidak dibersihkan; volume dan file DB tidak disentuh.

## Verifikasi induk (2026-10-16, siang WIB)
- **Langkah 3 (server):** penutupan shift 11 ditolak 409 `pending_payments` (count 1) saat QRIS 2029 (tab 2043) tertunda; shift tetap open. Permintaan EDC pada tab 2044 ditolak 409 `pending_qris` (tidak ada baris EDC). Di perangkat, tombol tutup dan tombol EDC dinonaktifkan oleh klien saat QRIS tertunda, jadi pesan server tidak tampil di layar.
- **QRIS 2029** diverifikasi dengan REF9876 lewat persetujuan `qris_verify` (penyetuju Sari, id 11; override terbit 201, verifikasi 200). Shift 11 tetap open.
- **Langkah 4** (EDC ganda): payment 2026 dan 2027, kode approval ABC123 pada dua tab; yang kedua dikonfirmasi setelah 409 `duplicate_edc`.
- **Langkah 1** stok kembali: BELUM terverifikasi (varian dev tidak terlacak stok).
- **Langkah 2**: tercakup OverrideGateTest (`same_person`).
- Tab 2044 tanpa nomor struk: dibuka lewat API tanpa header X-Device-Id, jadi tidak mendapat nomor terminal. Ini artefak pengujian, bukan cacat aplikasi.
- Residu: tab 2039–2044 selesai (tidak ada yang dibatalkan), shift 11 open, QRIS channel id 3 (dibuat untuk tes).

## Penutupan T057 (2026-10-16, siang WIB)
- **Langkah 1, stok kembali (lewat API, izin Sari):** varian 30 (WJMK1-001) diaktifkan pelacakan stok dan ditambah 10 lewat StockService di outlet 4 (dev, disetujui). Tab 2045: +2 varian 30 dan +1 varian 31; kirim dapur; void baris 30 dengan persetujuan void_line (stock_decision=return) → 200; tunai Rp 12.000 → tab selesai. Ledger varian 30 outlet 4: adjustment +10 → 10; sale −2 (order_item 6048) → 8; return +2 (order_item 6048) → 10. Stok kembali ke angka sebelum penjualan.
- **Langkah 3:** QRIS 2029 (tab 2043) diverifikasi REF9876 dengan persetujuan Sari. Shift 11 kini tanpa tab terbuka dan tanpa QRIS tertunda.
- **Penutupan shift 11** (buta, izin Rina): kas seharusnya 170.000 (modal 150.000 + tunai 8.000 + 12.000), hitungan 170.000, selisih 0; status closed.
- Salinan label: teks layar tender diperbaiki sesuai aturan per tab untuk pembayaran dan penutupan shift untuk tutup (keputusan pemilik).
- Status akhir: langkah 1 LULUS (void di perangkat, stok kembali lewat API); langkah 2 tercakup OverrideGateTest (disetujui); langkah 3 LULUS di server (pending_payments, pending_qris lewat API; tombol di perangkat dinonaktifkan oleh klien, disetujui); langkah 4 LULUS (perangkat).

