# Research: Hubungkan Aplikasi Android POS ke Data Server

**Classification**: INTERNAL — keputusan Phase 0. Label **ASSUMPTION** = tidak dapat dibuktikan dari kode/spec; **unverified** = versi/nama paket belum dicek.

## D1 — HTTP stack Android
- **Keputusan**: Retrofit + OkHttp + kotlinx.serialization.
- **Dasar**: Retrofit 2.11.0 sudah di katalog (`libs.versions.toml`); konverter gson yang ada tidak memberi keamanan tipe pada kolom yang bisa hilang (pelajaran proyek: kolom hilang diam-diam — `relationLoaded` trap). kotlinx.serialization dengan `ignoreUnknownKeys` + nilai bawaan eksplisit membuat kolom hilang terlihat saat uji. Versi kotlinx.serialization/OkHttp: **unverified** — dipilih dan dicatat di T-NET-01.
- **Alternatif**: gson (sudah ada; ditolak: null-tak-terduga), Ktor client (dependensi baru tanpa keuntungan).

## D2 — Injeksi dependensi
- **Keputusan**: kontainer manual (`AppContainer`) dibuat di `Application`, bukan Hilt.
- **Dasar**: Hilt butuh KSP/kapt; build penuh di mesin ini sudah ±15 menit pada resolusi pertama, dan graf dependensinya kecil (±8 objek). Katalog memuat hilt tetapi tidak dipakai kode mana pun.
- **Alternatif**: Hilt (ditolak: biaya build > manfaat pada skala ini; dapat ditambahkan nanti tanpa mengubah layar).

## D3 — Batas data vs UI
- **Keputusan**: antarmuka `PosBackend` di `core-domain`; `RemoteBackend` (HTTP) dan `SampleBackend` (data contoh). `PosStore` memanggil antarmuka; bentuk API publik `PosStore` yang dipakai layar dipertahankan.
- **Dasar**: FR-020 — data contoh hanya pada build debug/preset; layar sudah diverifikasi terhadap desain dan tidak boleh berubah perilaku saat sumber data berganti.

## D4 — Penyimpanan token & konfigurasi
- **Keputusan**: AES-GCM dengan kunci Android Keystore, ciphertext di SharedPreferences (kelas `TokenVault`). Menyimpan: URL server, token penyiapan (admin), id outlet, token sesi PIN + kedaluwarsa.
- **Dasar**: tanpa dependensi baru (`androidx.security:security-crypto` sudah ditandai usang — status **unverified** di sini, dihindari). `DataStoreEncryptor` yang ada di `core-domain` hanyalah stub.
- Token **tidak pernah** masuk log; PIN tidak pernah disimpan.

## D5 — Penyiapan terminal (sampai B14 penuh)
- **Keputusan**: layar penyiapan: URL server → login admin/owner (`POST /auth/login`) → pilih outlet (`GET /me/scope`) → daftarkan terminal (`POST /devices/pairings`, kode T01) → simpan. Token admin hanya dipakai untuk `GET /staff/roster` dan `POST /auth/pin-sessions` (bentuk yang sudah dibangun di B1).
- **Risiko yang diterima (ASSUMPTION)**: pemegang token admin dapat menebak PIN dibatasi kunci 5-salah + throttle. Pendaftaran perangkat (token perangkat terpisah dari token admin) mengurangi ini; ditandai sebagai peningkatan S4.

## D6 — Idempotensi
- **Keputusan**: UUID per niat pengguna, dibuat sekali dan dipakai ulang saat coba-ulang: `local_ref` (buka tab), `client_ref` (pembayaran), header `Idempotency-Key` (tambah/ubah baris, kirim). Status "tidak diketahui" setelah timeout pembayaran → kueri tab (`GET /tabs/{id}`) mencari pembayaran ber-`client_ref` sebelum mengulang.
- **Dasar**: server sudah mendukung semuanya (B15 selesai). Pembayaran: `payments.client_ref` unik; ulangan → 200 replay.

## D7 — Polling denah dan menu
- **Keputusan**: denah `GET /floor?version=` tiap ±3 dtk saat layar Meja/Order terlihat; ketersediaan `GET /menu/availability?pos=1` tiap ±5 dtk (etag/304); menu penuh dimuat saat masuk dan saat penyegaran manual/rilis berubah. Polling berhenti saat layar tidak terlihat atau aplikasi di latar.
- **Dasar**: kontrak server sudah ada (fitur 028/043); SC-008 ≤ 10 dtk terpenuhi.

## D8 — Pemetaan galat
- **Keputusan**: satu penangan: 422 → galat kolom; 409 → konflik aturan (pesan server ditampilkan); 403 → ditolak izin/outlet; 401 → sesi habis → perpanjang atau layar PIN; 423 → perangkat belum diaktivasi lisensi; 429 → terkunci/terlalu sering (`code: pin_locked`); 503 `schema_outdated` → pesan "server perlu diperbarui"; jaringan → "offline".
- **Dasar**: konvensi API proyek (CLAUDE.md "API conventions") dan Konstitusi III.

## D9 — Pajak eksklusif (B8)
- **Keputusan**: pengaturan `tax_rate` (persen, default 0 agar instalasi lama tidak berubah) dan `tax_label`; dihitung **setelah diskon**, dibulatkan per order (bukan per baris) ke rupiah; `orders.tax_amount` disimpan sebagai snapshot dan `total_amount = subtotal − diskon + pajak`.
- **Dasar**: desain (S06/S11/S15) dan Konstitusi IV (snapshot historis). **Dampak**: `PaymentSummary`, `TabService::recalculate`, laporan penjualan, dan struk harus memakai total yang sama — satu `TaxCalculator`. Laporan lama tidak berubah (pajak 0).
- **ASSUMPTION**: pembulatan per order; angka final ditetapkan pemilik produk.

## D10 — Penomoran (B12)
- **Keputusan**: `receipt_no` = `<kode terminal>-<urut 6 digit>` per terminal (urut di tabel `terminal_counters`, dikunci per baris); `queue_no` = `A-<urut 3 digit>` per outlet per hari bisnis (counter order saja). Keduanya dialokasikan saat dibutuhkan (queue saat order counter dibuka, receipt saat order lunas) di transaksi yang sama dengan perubahan status.
- **Dasar**: desain 17/18; unik antar mode data (belajar dari `order_number`).

## D11 — Persetujuan di tempat (B2)
- **Keputusan**: `POST /overrides` menerima `{action, subject_hash, approver_user_id, pin}` dan mengembalikan `override_token` sekali pakai berumur pendek (120 dtk) yang terikat ke `subject_hash` = hash kanonik dari aksi + parameter penting. Aksi yang dilindungi menerima `override_token`; server memverifikasi: belum dipakai, belum kedaluwarsa, hash cocok, penyetuju ≠ peminta, penyetuju memegang izin aksi itu, penyetuju satu outlet.
- **Dasar**: desain S10 ("Persetujuan berlaku untuk aksi ini saja"); mencegah token dipakai untuk aksi lain. PIN penyetuju dihitung pada kunci 5-salah yang sama dengan login.
- **Alternatif**: mengirim PIN penyetuju langsung bersama aksi (ditolak: PIN lewat jalur aksi menyulitkan pencatatan dan pembatasan percobaan).

## D12 — Shift, kas, X/Z-report (B3/B4/B5)
- **Keputusan**: tabel `cash_movements` (masuk/keluar, alasan, jumlah, persetujuan bila > ambang); `session_opening_denominations`; X-report dihitung langsung dari pembayaran `session_id` (aturan "uang diterima di shift mana" dari fitur 028), bukan disimpan; tutup shift menyimpan `counted_by_denomination`, `edc_batch_total`, `variance`, `variance_approval_id` dan menghasilkan Z-report tersimpan.
- **Dasar**: fitur 028 (`payments.session_id`), `CashierSessionController::summary()` sudah menghitung sebagian. Tidak membuat jalur kedua: `ShiftReportService` dipakai oleh `summary()`, X-report, dan penutupan.

## D13 — EDC & QRIS (B6/B7)
- **Keputusan**: metode `edc` (wajib `approval_code` ^[A-Z0-9]{6,12}$, tidak menyimpan nomor kartu) dan `qris` (disimpan `verification=pending`, verifikasi lewat endpoint yang sudah ada dengan `reference` ≥ 4 karakter). Aturan blok (QRIS tertunda memblokir non-tunai lain dan penutupan shift) ditegakkan di `PaymentService`/penutupan shift, bukan di klien.
- **Dasar**: desain 14–16; `markVerified()` sudah ada dan satu arah (fitur 032).

## D14 — Dokumentasi kontrak
- **Keputusan**: `docs/openapi-pos-mvp.yaml` diperbarui per irisan di commit yang sama dengan rute; uji otomatis membandingkan daftar rute dengan OpenAPI (SC-010).
- **Dasar**: Konstitusi "Documentation & Change Discipline"; api-gap mencatat berkas itu belum memuat `/tabs`, `/floor`, `/tickets` dll.

## Hal yang sengaja tidak diputuskan di sini
- Mode offline penuh/outbox (B16), pelaporan antrean cetak (B20), printer fisik — di luar cakupan.
- Pustaka foto menu (gambar item): server sudah mengirim `image_url`; pemuat gambar (Coil) **unverified** — keputusan ditunda ke tugas visual S1 bila diperlukan.
