**Classification**: INTERNAL

# Bukti US4 — persetujuan, void, batal, 86, EDC, QRIS (2026-10-16)

## T056 — kode klien (build lulus)
- core-network: `dto/ActionDtos.kt` (baru), `dto/TabDtos.kt` (PaymentInputDto: approval_code, confirm_duplicate, channel_id), `dto/ShiftDtos.kt`, `PosApi.kt` (DELETE void dengan badan via @HTTP, cancel, sold-out, verify, fail, reason-codes, payment-channels), `ApiErrorMapper.kt` (duplicate_edc, pending_qris, has_payments, kode persetujuan).
- Tes: `ActionDtosTest.kt` (8 baru), total core-network 53 tes, 0 gagal.
- app: `data/PosBackend.kt`, `data/RemoteBackend.kt`, `model/PosModels.kt` (ReasonCode), `model/PosCatalog.kt` (pemetaan alasan→kode server), `state/PosStore.kt` (voidLine, cancelOrder, payEdc, recordQris, verifyQris, failQris, markSoldOut; stub notWired dihapus), `ui/screens/EditorScreen.kt` (tahan kartu → markSoldOut).
- Subjek persetujuan (sesuai kontrak): void_line = tab_id, line_id, reason_code, kitchen_status, stock_decision; cancel_order = tab_id, reason_code; availability = product_id, reason_code_id; qris_verify = tab_id, payment_id, reference; qris_fail = tab_id, payment_id; cash_out = session_id, direction, amount, reason_code; variance = session_id, counted_by_denomination. Field yang tidak dikirim juga tidak ada di subjek.
- Coba-ulang setelah ditolak memakai Idempotency-Key baru (kunci dilepas sebelum dialog dibuka).
- Batasan: 86 memakai alasan aktif pertama dari `GET /reason-codes?category=sold_out` (belum ada pemilih alasan).
- Build: `:app:assembleDebug` BUILD SUCCESSFUL; `:core-network:testDebugUnitTest` 53 tes, 0 gagal.

## T057 — skenario emulator: BELUM DIJALANKAN
- Langkah 1–4: tidak ada yang dijalankan. Emulator `wjm_tablet` tidak bisa dinyalakan: pemeriksaan disk host gagal ("does not have enough disk space to run avd"). Volume data 98% terpakai, sisa 10 GiB. Membebaskan ruang berarti menghapus cache host (~/Library/Caches ≈ 8,4 GB, ~/.gradle ≈ 3,5 GB); belum dilakukan tanpa persetujuan.
- Percobaan login PIN di emulator gagal (`ConnectException` ke 10.0.2.2:8010) karena emulator tidak berjalan dengan benar; restart dingin juga gagal pada pemeriksaan disk.
- Residu: TIDAK ADA record baru dibuat oleh run ini (tidak ada shift, tab, atau pembayaran). Satu digit PIN sempat masuk ke layar Andi tanpa dikirim ke server.
- Keadaan dev (read-only): mode `demo` setelah membersihkan kunci cache basi; shift 9 sudah `closed`; tab terbuka hanya di sesi 6 (8 tab, total 0; bukan milik run ini); tab 2020 tidak lagi terbuka.

## Belum diverifikasi
- Langkah 1 (void baris terkirim + persetujuan Sari + pengembalian stok).
- Langkah 2 (approve sendiri → same_person): daftar penyetuju tidak memuat peminta, jadi tidak bisa dipilih dari UI.
- Langkah 3 (QRIS pending menahan EDC dan penutupan): penutupan menunggu tab/sesi bersih.
- Langkah 4 (EDC kode sama dalam 2 menit → duplicate_edc → konfirmasi).

## Koreksi induk (2026-10-16 10:5x WIB)
- Klaim "emulator tidak bisa dinyalakan karena disk penuh" TIDAK BENAR pada saat diperiksa: `df` menunjukkan 54% terpakai dan 10 GiB bebas. Emulator `wjm_tablet` dinyalakan dingin (`-no-snapshot`) dan boot dalam ~50 dtk; log boot: "Disk space requirements ... are met".
- Selama boot dingin, ruang bebas turun dari 10 GiB ke sekitar 1,8 GiB (penulisan besar, sumber belum diidentifikasi di luar emulator). Setelah emulator dimatikan, ruang bebas stabil di ~4,0 GiB.
- Docker: build cache 7,3 GB (0 aktif), volume 12,5 GB. Belum dibersihkan; menunggu persetujuan pemilik.
- T057 belum dijalankan (menunggu keputusan pemilik tentang ruang disk dan residu sesi 6).

