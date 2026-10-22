# Data Model — 066

Tidak ada tabel, kolom, atau baris baru.

- `settings` (`server_address_local`, `server_address_online`, `server_address_selected`): tidak berubah. Kolom online tetap boleh `NULL`.
- **Alamat online bawaan**: konstanta konfigurasi (server: `server_address.default_online`) dan string resource build (Android: `default_online_url`). Bukan data pengguna, tidak tersimpan di database, tidak masuk backup.
- **Alamat tersimpan di tablet** (065): tidak berubah; jatuhan hanya menentukan nilai yang disimpan saat penyiapan.
