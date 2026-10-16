# Kontrak sisi klien (Android)

**Classification**: INTERNAL.

## Konfigurasi & rahasia
- URL server dan semua token disimpan terenkripsi (Keystore AES-GCM); **tidak ada** URL/kredensial di `BuildConfig` atau repositori. PIN tidak pernah disimpan atau dicatat. Log (Timber) tidak mencetak header `Authorization`, badan login, PIN, atau nomor kartu (interceptor log disaring).
- Build rilis: `SampleBackend` tidak dapat dipilih; preset debug (`--es preset`) hanya aktif pada build debuggable.

## Permintaan
- Header tetap: `Authorization: Bearer`, `Accept: application/json`, `X-Outlet-Id` (hanya untuk menyempit; server tetap menurunkan outlet), `X-Device-Id`.
- Tiap **niat pengguna yang menulis** membuat satu UUID dan memakainya pada semua percobaan ulang niat itu: `local_ref` (POST /tabs), `client_ref` (pembayaran), `Idempotency-Key` (baris, kirim). Niat baru = UUID baru.
- Batas waktu: 10 dtk sambung, 20 dtk baca. Tulis yang timeout → status **tidak diketahui** → klien menanyakan keadaan (GET tab) sebelum mengulang; tidak pernah menampilkan "gagal" untuk tulis yang mungkin berhasil.

## Respons & galat
| HTTP | Perilaku klien |
|---|---|
| 200/201 | timpa keadaan lokal dengan jawaban server |
| 401 | coba perpanjang sesi PIN (`/auth/token-refresh`) sekali; gagal → layar PIN, order terbuka tetap di server |
| 403 | pesan izin/outlet; kontrol serupa disembunyikan setelah peran dimuat |
| 409 | tampilkan `message` server; `code` menentukan alur (mis. `override_required` → dialog persetujuan, `duplicate_edc` → konfirmasi) |
| 422 | tampilkan galat per kolom |
| 423 | "perangkat belum diaktivasi" |
| 429 | kunci PIN/terlalu sering; tampilkan sisa waktu bila ada `locked_until` |
| 503 `schema_outdated` | "Server perlu diperbarui" + coba lagi |
| jaringan/timeout | keadaan offline jujur; aksi tulis dinonaktifkan dengan penjelasan |
Keadaan gagal memuat adalah **keadaan galat** (pesan + Coba lagi), tidak pernah ditampilkan sebagai daftar kosong.

## Polling
- Denah: `GET /floor?version=` tiap ±3 dtk saat layar Meja/Order terlihat; `{unchanged:true}` tidak mengubah UI.
- Ketersediaan: `GET /menu/availability?pos=1` tiap ±5 dtk dengan `If-None-Match`; 304 diabaikan.
- Berhenti saat layar tidak terlihat/aplikasi di latar; lanjut saat kembali.

## Uang
- Angka dari server berupa string desimal → `Long` rupiah lewat satu fungsi `Money.parse`; format tampilan lewat satu fungsi `rp()`. Klien tidak menjumlah pajak/diskon sebagai sumber kebenaran; angka sementara hanya untuk umpan balik instan dan ditimpa jawaban server.

## Model peran
- Izin tampilan (`permissions[]`, `menu_keys[]`) dari `GET /auth/me`; klien hanya menyembunyikan. Server menolak tetap dengan 403.
