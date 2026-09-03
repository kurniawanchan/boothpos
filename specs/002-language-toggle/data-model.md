# Data Model: Ganti Bahasa Antarmuka (Indonesia/English)

## Entity: User (perluasan)

Tidak ada tabel baru — fitur ini menambah SATU kolom ke `users` (tabel
yang sudah ada, sudah diperluas sekali oleh fitur `001-user-store-settings`
dengan `role_id`/`photo_path`/`last_access_at`).

| Kolom | Tipe | Constraint | Catatan |
|---|---|---|---|
| `language` | `ENUM('id','en')` | `NOT NULL DEFAULT 'en'` | Preferensi bahasa antarmuka akun ini setelah login. Default `'en'` memenuhi FR-006/FR-007 tanpa migrasi data terpisah — kolom baru otomatis terisi `'en'` untuk akun lama maupun baru. |

**Migration**: satu tahap (bukan pola dua-tahap `role_id` yang dipakai
fitur sebelumnya) — kolom ini bersifat aditif murni, tidak mengganti atau
menghapus kolom lain, dan default value sudah menjawab kebutuhan
backward-compatibility tanpa langkah backfill terpisah.

```php
// database/migrations/2026_10_10_000001_add_language_to_users_table.php
Schema::table('users', function (Blueprint $table) {
    $table->enum('language', ['id', 'en'])->default('en')->after('photo_path');
});
```

**Validasi**: nilai HARUS salah satu dari `['id', 'en']` — divalidasi
lewat `Rule::in(['id', 'en'])` di request endpoint ganti-bahasa (lihat
`contracts/api.md`), bukan hanya mengandalkan constraint `ENUM` di
database (constraint DB adalah pengaman terakhir, bukan pengganti
validasi 422 yang informatif — konsisten dengan pola validasi FormRequest
di seluruh kodebase ini).

**Relasi**: tidak ada — `language` adalah atribut skalar langsung pada
`User`, bukan entitas terpisah, karena hanya dua nilai tetap yang
didukung (lihat spec.md "Assumptions" — tidak ada bahasa ketiga di
cakupan ini, jadi tabel `languages` terpisah adalah over-engineering).

**Tidak ada state machine** — nilai `language` berubah langsung dari satu
nilai tetap ke nilai tetap lain (toggle dua-arah), tidak ada urutan
transisi yang harus dijaga (beda dari, misalnya, status pre-order yang
punya urutan sah/tidak sah).

## Berkas katalog terjemahan (bukan baris database)

Bukan "entitas data" dalam pengertian model Eloquent, tapi bagian dari
data model fitur ini yang perlu didaftarkan eksplisit karena keduanya
adalah SATU-SATUNYA sumber kebenaran string per sisi (Prinsip I
Konstitusi — satu jalur per concern):

- **Backend**: `lang/id/*.php`, `lang/en/*.php` — array asosiatif kunci
  ke string, dikelompokkan per domain (`lang/id/auth.php`,
  `lang/id/roles.php`, `lang/id/users.php`, `lang/id/validation.php`,
  dst., mengikuti struktur `lang/` bawaan Laravel).
- **Frontend**: `resources/js/locales/id.json`, `en.json` — objek
  bersarang per domain/screen (mis. `{"pos": {"cart_empty": "..."},
  "roles": {"delete_guard": "..."}}`), dimuat oleh `vue-i18n` saat boot
  aplikasi.

Kedua berkas ini WAJIB punya kunci yang identik satu sama lain di semua
locale (`id`/`en` untuk masing-masing sisi) — kunci yang ada di satu
berkas tapi tidak di pasangannya adalah bug (string yang lupa
diterjemahkan), bukan variasi yang sah.
