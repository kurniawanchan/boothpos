# API Contract: Ganti Bahasa Antarmuka

## `PUT /api/v1/auth/language`

Ganti preferensi bahasa akun yang SEDANG LOGIN (self-service — tidak
digerbang `canAccessMenu('users')`, lihat research.md Decision 4).

**Auth**: `auth:sanctum` wajib. Tidak ada gerbang otorisasi tambahan
selain "harus login sebagai diri sendiri" — setiap akun boleh mengubah
bahasanya sendiri, termasuk kasir tanpa akses menu `users`.

**Request**:
```json
{ "language": "id" }
```

**Validasi**:
- `language`: wajib, string, harus salah satu dari `["id", "en"]`
  (`Rule::in(...)`) — nilai lain ditolak `422`, PESAN VALIDASINYA sendiri
  memakai locale yang SEDANG AKTIF SEBELUM perubahan (locale lama
  pengguna), karena kegagalan validasi berarti locale belum berubah.

**Response `200`**:
```json
{
  "id": 7,
  "name": "Kasir Satu",
  "username": "kasir01",
  "language": "id"
}
```
Bentuk response sama dengan potongan relevan `GET /auth/me` — tidak ada
resource baru, field `language` ditambahkan ke `UserResource`/payload
`auth/me` yang sudah ada (lihat di bawah).

**Efek samping**: permintaan berikutnya dari pengguna ini (memakai token
yang sama) langsung menerima seluruh pesan server dalam bahasa baru —
tidak perlu request ulang token/login ulang, karena locale diresolusi
per-request oleh middleware `SetLocaleFromUser` dari nilai `language`
yang sudah tersimpan di database, bukan dari klaim di dalam token.

## `GET /api/v1/auth/me` (perluasan, endpoint sudah ada)

Response ditambah satu field:
```json
{
  "token": "...",
  "user": {
    "id": 1,
    "name": "Owner Dummy",
    "username": "owner",
    "role": "Owner",
    "menu_keys": [...],
    "is_active": true,
    "language": "en"
  }
}
```
Frontend memakai field ini untuk menyetel `locale.value` vue-i18n saat
aplikasi boot / setelah login berhasil — SATU-SATUNYA titik di frontend
yang membaca nilai ini dari server (bukan disimpan independen di
`localStorage` sebagai sumber kebenaran; `localStorage` boleh dipakai
sebagai cache tampilan sementara sebelum respons `auth/me` datang, tapi
nilai dari server selalu menang).

## Middleware baru: `SetLocaleFromUser`

Bukan endpoint HTTP publik, tapi bagian dari kontrak perilaku API yang
harus didokumentasikan karena mengubah bahasa SELURUH response error
setelah login:

- Didaftarkan pada grup route `auth:sanctum` di `routes/api.php`, SETELAH
  middleware autentikasi (butuh `$request->user()` sudah resolve).
- Memanggil `App::setLocale($request->user()->language)`.
- TIDAK didaftarkan pada rute `POST /auth/login` (tamu, belum ada
  identitas) — rute itu tetap memakai locale default aplikasi (`'id'`,
  lihat research.md Decision 2), memenuhi FR-001.
- **Pengecualian eksplisit**: `GET /orders/{id}/receipt` — meski masuk
  grup `auth:sanctum` dan middleware ini tetap berjalan (locale
  aplikasi berubah ke bahasa kasir), controller `receipt()` TIDAK
  memakai `__()` untuk label-labelnya (lihat research.md Decision 5) —
  jadi perubahan locale global TIDAK mempengaruhi isi respons ini sama
  sekali, sengaja.

## Dampak ke response error yang sudah ada (bukan endpoint baru, tapi kontrak berubah)

Setiap response `422`/`403`/`409` dari endpoint yang memakai
`auth:sanctum` (yaitu HAMPIR SEMUA endpoint API selain
`POST /auth/login`) kini MUNGKIN berbahasa Inggris, tergantung
`language` pengguna yang membuat request. Ini BUKAN perubahan bentuk
JSON (tetap `{"message": "..."}` atau `{"errors": {...}}` seperti
sekarang) — hanya ISI stringnya yang berubah sesuai locale. Konsumen API
(frontend SPA, koleksi Bruno) yang mencocokkan status code tetap bekerja
tanpa perubahan; konsumen yang mencocokkan ISI TEKS pesan error
(misalnya test yang meng-assert `assertJsonPath('message', 'Username
atau password salah.')`) HARUS diperbarui untuk memakai key/locale yang
konsisten, atau membuat request dalam locale yang eksplisit dikontrol
test tersebut (lihat quickstart.md untuk skenario pengujian dua-locale).
