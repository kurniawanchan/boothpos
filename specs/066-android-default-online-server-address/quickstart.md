# Quickstart — 066

1. **Android unit**: `cd android-pos && ./gradlew :app:testDebugUnitTest --tests '*ServerDiscoveryTest'` — kasus tabel kontrak di atas (online+null+bawaan terjangkau → Found; tak terjangkau → Unreachable; local+null → NotConfigured; isian admin menang).
2. **Server**: tes feature `GET /settings/server-address` memuat `default_online`; `PUT` dengan `default_online` tidak mengubah apa pun; endpoint publik tidak memuatnya. Jalankan di kontainer dengan flag `-e` yang diwajibkan CLAUDE.md.
3. **Web**: `npm test` — petunjuk tampil dengan alamat; hilang bila `default_online` null; `localeKeys.test.js` hijau.
4. **Manual (tablet/emulator)**: kosongkan kolom online, pilih Online, simpan; hapus data aplikasi; "Siapkan terminal" → tersambung lewat alamat bawaan. Isi kolom online dengan alamat lain → tablet baru memakai alamat itu.
