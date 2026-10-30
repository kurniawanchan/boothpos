# Kontrak UI: beranda login Android (layar PIN)

**Klasifikasi**: INTERNAL · Tidak ada perubahan API.

## Bentuk (lebar ≥ 720 dp)

```
┌──────────────────────────────────┬───────────────────────┐
│  INFO OUTLET (foto latar+scrim)  │                       │
│  [logo] Nama Outlet   [DEMO/LIVE]│   PIN <nama staf>     │
│  Terminal: <nama>                │                       │
│  Kamis, 30 Oktober 2026          │      ● ● ● ○ ○ ○      │
│  14:05:09            ● Online    │                       │
├──────────────────────────────────┤   [1] [2] [3]         │
│  PILIH PENGGUNA                  │   [4] [5] [6]         │
│  ┌─────┐ ┌─────┐ ┌─────┐         │   [7] [8] [9]         │
│  │ (o) │ │ (o) │ │ (o) │         │   [ ] [0] [⌫]         │
│  │Nama │ │Nama │ │Nama │         │                       │
│  │Peran│ │Peran│ │Peran│         │  [ Ganti outlet /     │
│  └─────┘ └─────┘ └─────┘         │    terminal ]         │
└──────────────────────────────────┴───────────────────────┘
```

Lebar < 720 dp: satu kolom, urutan sama (Info Outlet, Pilih Pengguna, PIN).

## Aturan

| Elemen | Aturan |
|--------|--------|
| Info Outlet | logo, nama outlet, lencana mode, nama terminal (baris sendiri), tanggal, jam berdetak tiap detik, chip online/offline; teks putih di atas foto + scrim gelap |
| Kartu pengguna | ukuran, jarak, dan perataan sama untuk semua; baris atas avatar, baris bawah nama + jabatan (satu baris, elipsis); terpilih = cincin tebal; tanpa PIN = redup + "Belum ada PIN", tidak bisa diketuk |
| PIN | keypad lebih besar dari sebelumnya (tombol 88 dp, titik 22 dp, lebar maks ≈ 440 dp); galat PIN hanya di kolom ini |
| Tombol | "Ganti outlet / terminal" = `OutlinedButton` jelas, memanggil `store.goToSetup()` |
| Dihapus | ikon roda gigi |
| Tidak berubah | roster tanpa token, `POST /auth/pin-sessions`, kunci PIN 5 menit, probe koneksi, mode DEMO/LIVE |

## Aset

Foto latar bawaan dari `specs/085-login-homepage-tax-fix/outlet-background-reference.png`, dikecilkan (JPEG ±1280 px) ke `res/drawable-nodpi` saat implementasi. Bukan PII; tidak ada orang dikenali dalam foto (ASSUMPTION: pemilik menyetujui foto itu dibundel di APK).
