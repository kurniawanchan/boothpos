Klasifikasi: INTERNAL

# Dokumentasi aplikasi web BoothPOS (Mintlify)

Sumber panduan pengguna aplikasi web BoothPOS. 43 halaman MDX (32 aplikasi web, 11 aplikasi Android), navigasi di `docs.json`.

## Pratinjau

Mintlify memerlukan Node 20.17 atau lebih baru.

```bash
npm i -g mint
cd docs-site
mint dev
mint validate
mint broken-links
```

## Status

- Ditulis dari kode, locale, dan spesifikasi. Belum ada halaman yang dirender oleh Mintlify atau diperiksa di browser.
- `mint validate` belum dijalankan (CLI belum terpasang dan Node masih v18 saat halaman-halaman ini ditulis).
- Pemeriksaan lewat skrip mengonfirmasi: setiap halaman ada di `docs.json`, tautan internal terselesaikan, frontmatter lengkap, dan kurung kurawal di prosa sudah di-escape untuk MDX.
- Halaman menandai poin yang belum terverifikasi sebagai `ASUMSI:`. Cari kata itu sebelum dipublikasikan.

## Cakupan

Mencakup modul aplikasi web yang dipakai toko dan aplikasi tablet Android POS (label UI di tablet berbahasa Indonesia dan dikutip apa adanya). Tidak dicakup: administrasi sisi penyedia (Company, Jenis Bisnis, Lisensi, Invoice), dan modul yang sudah dihapus (Event, Pre-order).
