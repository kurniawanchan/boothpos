**Classification**: INTERNAL

# Bukti akhir 046 (2026-10-13)

| Pemeriksaan | Hasil |
|---|---|
| Backend penuh (`docker compose exec -e APP_ENV=testing -e DB_DATABASE=warkop_wjm_test … php artisan test`) | 1575 lulus, 1 dilewati, 0 gagal (baseline sebelum 046: 1869 lulus — selisih = ±300 tes modul Event/Pre-order yang dihapus, ditambah tes baru) |
| Frontend penuh (`npx vitest run`) | 181 berkas, 1615 lulus, 2 dilewati |
| `npm run build` | berhasil; bundel utama 240,38 kB |
| Gladi pemutakhiran | `evidence-upgrade.md` — total identik, rekonsiliasi bersih |
| Dev DB vs `baseline.md` | total identik |
| OpenAPI | YAML valid; jalur event/pre-order dihapus, laporan per periode, sesi tanpa event |
| Browser (owner, EN) | `evidence/reports.png`, `evidence/preorders-redirect-dashboard.png`, `evidence/session.png` |

Terbuka: penelusuran browser semua peran dan kedua bahasa; `app:backup` vs kolom turunan `orders.open_table_key` (045).
