# Data Model: Aplikasi Android Manajemen (094)

**Klasifikasi**: INTERNAL

Fitur ini TIDAK menambah entitas bisnis. Satu kolom server baru dan beberapa entitas lokal di perangkat.

## Server

### `roles.manager_app_enabled` (kolom baru)

| Kolom | Tipe | Bawaan | Aturan |
|---|---|---|---|
| `manager_app_enabled` | boolean NOT NULL | `false` | Ditulis hanya lewat `POST/PUT /roles` (`RolePolicy`). Masuk `$fillable` + `casts` boolean. |

Migrasi (satu arah aman, `down()` menghapus kolom):
- Tambah kolom bawaan `false`.
- Isi `true` untuk: peran sistem bawaan bernama Owner dan Admin; peran bernama `Outlet Manager` (hasil `WarkopPosStaffSeeder`). Pencocokan nama tanpa beda huruf besar/kecil, termasuk peran terhapus lunak (tetap false bila tidak cocok).
- Seeder (`DatabaseSeeder`, `WarkopPosStaffSeeder`, `UserFactory`) ikut mengisi nilai yang sama agar instalasi baru konsisten.

Tidak memakai `HasDataMode` (peran bukan data bisnis per mode, sama seperti kolom peran lain).

### Token Sanctum bernama `manager-app`

Tidak ada tabel baru; baris `personal_access_tokens` dengan `name = 'manager-app'`. Dipakai `EnsureManagerAppAccess` untuk membedakan sesi aplikasi manajemen dari sesi web (`pos-session`) dan PIN (`pos-pin`).

## Perangkat (lokal, `:manager`)

### ServerProfile

| Field | Tipe | Aturan |
|---|---|---|
| `kind` | `LOCAL` \| `ONLINE` | tepat dua profil |
| `url` | string? | dinormalkan seperti `ApiClient.normalise`; kosong = belum diatur |
| `active` | boolean | tepat satu aktif |
| `lastVerifiedAt` | instant? | diisi saat uji sambungan berhasil |

Disimpan terenkripsi (AES-GCM, kunci Android Keystore — pola `TokenVault`). Mengganti `active` atau `url` profil aktif → hapus `ManagerSession`.

### SelectedOutlet

| Field | Tipe |
|---|---|
| `outletId` | long |
| `code`, `name` | string (tampilan) |
| `mode` | `DEMO` \| `LIVE` (dari branding) |

Disimpan per profil server. Dikirim sebagai `X-Outlet-Id` setelah login.

### ManagerSession

| Field | Tipe | Aturan |
|---|---|---|
| `token` | string | terenkripsi; tidak pernah dicatat log |
| `userName`, `roleName` | string | tampilan saja |
| `menuKeys` | set<string> | dari `GET /auth/me`; menentukan menu (FR-012) |
| `permissions` | set<string> | dari `GET /auth/me`; menyembunyikan aksi tanpa izin (mis. `menu.recipe.edit`, `stock.transfer.*`) |

Dimuat ulang dari `GET /auth/me` saat aplikasi kembali ke depan. Respons 401 atau 403 `manager_app_denied` → hapus sesi, ke layar login dengan pesan.

Kata sandi tidak pernah disimpan.

### Menu yang tampil (turunan, bukan disimpan)

| Menu aplikasi | Kunci menu |
|---|---|
| Dashboard | `dashboard` |
| Dapur & Bar | `kitchen` |
| Pengaturan Restoran | `dining_setup` |
| Kategori | `categories` |
| Produk | `products` |
| Stok | `stock` |
| Vendor | `vendors` |
| Bahan | `materials` |
| Purchase Order | `purchase_orders` |

Satu daftar (`NavSpecs`) memetakan menu → kunci; tes unit memastikan menu tanpa kunci tidak tampil. Pengguna yang lolos login tetapi tanpa satu pun kunci di atas melihat layar kosong "Tidak ada menu yang bisa diakses" (bukan galat).

### Status formulir (per layar)

`FormState<T>`: nilai isian, `fieldErrors: Map<String, String>` (dari 422, kunci = nama field server), `conflict: String?` (409), `saving`, dan untuk produk `completedSteps` (research D15). Disimpan di `SavedStateHandle` supaya rotasi tidak menghilangkan isian (FR-029).

## Transisi status yang ditampilkan (dari server, tidak diubah)

- Tiket dapur: new → preparing → ready → served.
- Purchase Order: draft → ordered | cancelled; ordered → received | cancelled; received → paid.
- Pindah stok: mengikuti status server (dikirim, diterima sebagian/penuh, dibatalkan).
