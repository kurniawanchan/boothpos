# Data Model: 072-store-identity-branding

Tanpa perubahan skema atau migrasi. Memakai baris `settings` yang sudah ada:

| Kunci | Cakupan | Dipakai untuk |
|---|---|---|
| `store_name` / `store_name_demo` | per mode LIVE/DEMO | judul sidebar, judul tab |
| `store_logo_path` | satu untuk instalasi (disk `public`) | logo sidebar, favicon |
| `app_name` | satu untuk instalasi, default `BoothPOS` | judul tab, fallback sidebar |

State SPA baru: `settings.storeLogoUrl` (string|null), diisi dari `features.store_logo_url`.

Aturan turunan (di `utils/documentTitle.js`): `title = storeName ? (sama(storeName, appName) ? appName : "{storeName} – {appName}") : appName`.
