# Temuan keamanan 073 (tinjauan otomatis, 2026-10-26)

**Klasifikasi: CONFIDENTIAL** — memuat kelemahan yang belum diperbaiki. Jangan di-commit atau di-push ke remote bersama (PR #59) tanpa keputusan pemilik.

Sumber: dua tinjauan keamanan otomatis (plugin security-guidance) atas commit `e8802c99` dan `4c0c4d20`. Temuan menyangkut desain 073, bukan 075. Status: **diketahui, belum diperbaiki**; konsekuensi pengecualian Konstitusi IV yang tercatat di CLAUDE.md (073).

## Temuan

| # | Area risiko | Lokasi | Fakta | Tingkat (menurut peninjau) |
|---|---|---|---|---|
| 1 | Kontrol akses, pendaftaran perangkat | `PosSetupController::registerTerminal`, `DeviceService::findOrRegister` | Terminal baru terdaftar tanpa token, tanpa persetujuan admin, tanpa rahasia per perangkat. `device_id` bukan rahasia. | HIGH / MEDIUM |
| 2 | Autentikasi, brute force dan penguncian | `POST /auth/pin-sessions` (`routes/api.php`, `throttle:30,1`), `PinAuthService::LOCK_MINUTES = 5` | PIN login terikat `device_id` publik. Lima PIN salah mengunci staf 5 menit di semua tablet; kunci dipakai bersama PIN approver, jadi pemanggil tanpa token dapat memicu penguncian. Jendela kunci datar. | HIGH / MEDIUM |
| 3 | Paparan data, roster | `GET /staff/roster` | Tanpa token; mengembalikan staf (non owner/admin). ASSUMPTION: status kunci ikut dikembalikan — belum diverifikasi pada sesi ini. | MEDIUM |

## Perbaikan yang disarankan peninjau
- **Besar (spec tersendiri):** kode pairing sekali pakai dari owner/admin di back office, ditukar dengan rahasia per perangkat; PIN login dan roster mensyaratkan rahasia itu. Ini membalik keputusan 073 "tanpa login owner/admin saat setup".
- **Kecil (tetap dalam desain 073):**
  - kunci bertingkat (jendela memanjang tiap kali terkunci ulang), bukan datar;
  - batas kegagalan per perangkat dan per outlet, selain per pengguna;
  - kegagalan tanpa token tidak boleh mengunci PIN approver;
  - hilangkan status kunci dari roster; kembalikan hanya yang dibutuhkan layar PIN.

## Keputusan pemilik
Pada 2026-10-26 pemilik memilih: biarkan PR #59 apa adanya dan catat temuan di berkas ini, bukan di deskripsi PR. Perbaikan belum dijadwalkan.
