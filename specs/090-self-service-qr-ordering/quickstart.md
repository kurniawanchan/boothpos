# Quickstart & Skenario Verifikasi: Pesan Mandiri via QR Meja

**Klasifikasi**: INTERNAL

Dipakai untuk membuktikan fitur berjalan (Konstitusi II). Hasil dicatat di `specs/090-self-service-qr-ordering/evidence.md`. Yang belum dijalankan manusia harus ditulis **belum diverifikasi**, bukan dianggap lulus.

## Prasyarat

```bash
# Database dev & data contoh (hanya di mesin dev ini; compose project warkop_wjm, port app 8010)
docker compose up -d
docker compose exec app php artisan migrate
```
Jangan menjalankan tes tanpa flag aman (CLAUDE.md):
```bash
docker compose exec -e APP_ENV=testing -e DB_DATABASE=warkop_wjm_test \
  app php artisan test --filter=SelfService -e CACHE_STORE=array -e SESSION_DRIVER=array
npm test            # Vitest, tanpa backend
cd android-pos && ./gradlew test
```
Pengaturan: isi **Pengaturan → Self-Service → Alamat dasar** dengan alamat yang bisa dijangkau ponsel (IP LAN laptop atau domain). `localhost` ditolak (FR-023). Pastikan outlet punya **satu kanal QRIS aktif dengan gambar**, dan satu pengguna (kasir) punya **shift terbuka** di outlet itu.

## Skenario A — Jalur utama (US1, US2, US3, US6)

1. Web staf → **Restaurant Setup** → pada Meja 5 pilih **Buat QR** → QR tampil berlabel outlet + meja → unduh.
2. Dari **ponsel** (Wi-Fi yang sama) pindai QR → halaman terbuka berbahasa Indonesia, tertulis outlet dan "Meja 5".
3. Pilih 2 item (satu bermodifier), pilih Dine-In, tekan **Bayar** → ada QRIS + nominal, tanpa diskon.
4. Coba **Kirim tanpa foto** → ditolak dengan pesan bukti wajib. Lalu ambil foto lewat kamera, pratinjau muncul, kirim.
5. Struk tampil dengan nomor `SS-…`, status **Menunggu verifikasi** (bukan Lunas). Simpan sebagai **gambar** dan **PDF**; isi sama, logo tampil.
6. Web staf → **Penjualan → tab Self-Service**: pesanan tampil, **belum ada tiket di layar Dapur**, stok belum berkurang.
7. Buka detail: lihat item, pembayaran, foto bukti → **Verifikasi** (isi referensi ≥ 4 karakter) → tombol **Kirim ke dapur** aktif → tekan.
8. Layar **Dapur** menampilkan tiket dengan meja dan Dine-In; stok berkurang; di Penjualan ada order `self_service`.
9. Buka ulang tautan struk di ponsel → status **Diproses di dapur**.

## Skenario B — Tolak (US6)
Kirim pesanan baru → di tab Self-Service pilih **Tolak** dengan alasan → tidak ada tiket, stok tetap, struk pelanggan: **Ditolak** + alasan.

## Skenario C — Keamanan (SC-005, SC-010)
- Ubah angka `table`/`outlet` pada alamat QR → halaman menolak, tidak menampilkan menu.
- Pakai QR sesudah **Buat ulang QR** pada meja itu → ditolak.
- Panggil rute publik tanpa token QR dan coba mengambil HPP, stok, data pelanggan → tidak ada di respons.
- Kirim berulang cepat → kena 429 berbahasa Indonesia.
- Klik **Kirim** dua kali / kirim ulang dengan `local_ref` sama → satu entitas.

## Skenario D — Kasus tepi dispatch
- Meja punya tab kasir terbuka → dispatch tetap berhasil dan tab kasir tidak berubah.
- Ubah harga item antara kirim dan dispatch → dispatch ditolak `total_changed`.
- Staf tanpa shift terbuka → `no_open_shift`.
- Dua staf menekan Kirim bersamaan → satu berhasil.

## Skenario E — Dine-In / Take Away (US5)
POS web: transaksi Dine-In dan Take Away → tampil di detail dan struk. Android: sama (tab meja tetap Dine-In). Transaksi lama tetap tampil.

## Skenario F — Restaurant Setup (US7)
Outlet dengan > 1 halaman meja: cari, saring area/status, ganti halaman, pilih **semua hasil filter** → QR massal memuat jumlah yang benar, label benar.

## Skenario G — Notifikasi Android (US8) — **perlu tablet nyata**
Tablet di outlet yang sama dalam keadaan terbuka: kirim pesanan Meja 5 dari ponsel → terdengar "Pesanan dari meja 5" ≤ 10 detik dan spanduk tampil. Tiga pesanan beruntun → berurutan. Tablet outlet lain diam. Matikan suara di pengaturan → hanya spanduk. Tutup dan buka aplikasi → hanya jumlah menunggu, tanpa memutar ulang.

## Pemeriksaan build
```bash
npm run build   # pastikan pustaka QR berada di chunk terpisah (Konstitusi V)
```
Konsol peramban bersih pada halaman publik dan halaman staf yang diubah (Konstitusi II).

## Belum diverifikasi saat rencana ini ditulis
Kamera/unggah di iOS Safari dan Android Chrome; HEIC; suara Indonesia di tablet; SC-003 (3 detik) dan SC-012 (10 detik); angka pembatas laju; `KitchenService::send`/`settle` pada order tanpa `table_id` (dibuktikan tes di awal US6).
