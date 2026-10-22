# Research — 066

## D1. Arti "default server address"
- **Keputusan**: alamat online bawaan yang tertanam di aplikasi Android (ASSUMPTION di spec).
- **Alasan**: permintaan berbunyi "in android app"; mekanisme bawaan build sudah ada (`default_online_url`, 065 D8) dan nilainya kosong sekarang.
- **Alternatif**: menyimpan nilai bawaan di `settings` server → ditolak: mengubah arti "kolom kosong" dan menulis data yang bukan pilihan admin (FR-007).

## D2. Kapan jatuhan dipakai
- **Keputusan**: hanya `selected == online` dengan `url == null`.
- **Alasan**: Local tidak boleh terpengaruh (FR-004); pilihan online tanpa alamat adalah tepat kasus yang kini berhenti.
- **Alternatif**: selalu menambah online bawaan sebagai kandidat penjawab → sudah terjadi lewat daftar kandidat 065; yang kurang hanyalah jatuhan saat `url` null.

## D3. Sumber nilai bawaan
- **Keputusan**: default gradle `pos.defaultOnlineUrl` = alamat ngrok; build lain menimpanya lewat `gradle.properties`/`local.properties`.
- **Alasan**: ngrok gratis dapat berubah; ganti tanpa ubah kode (edge case spec). `local.properties` tidak di-commit sehingga default harus di gradle.
- **Risiko**: dua tempat tahu alamat (gradle dan config server untuk petunjuk web). Mitigasi: komentar silang dan tes yang menjaga kesamaan nilai bawaan (mengikuti preseden `AppName::DEFAULT`).

## D4. Petunjuk web
- **Keputusan**: server mengirim `default_online` read-only pada GET admin; web hanya menampilkan.
- **Alasan**: SPA tidak boleh mengarang alamat; satu sumber di server. Endpoint publik tablet tidak diubah.
- **Alternatif**: teks tetap di locale → ditolak: alamat berubah berarti tiga berkas diubah.

## D5. Pengujian keterjangkauan
- **Keputusan**: probe alamat bawaan sebelum disimpan (aturan 065 sudah ada); tidak terjangkau → `Unreachable`.
- **Alasan**: FR-005; ngrok mati tidak boleh memindahkan tablet ke alamat mati.
