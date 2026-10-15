**Classification**: INTERNAL

# Quickstart — membangun & menjalankan app Android POS

1. **Prasyarat (belum ada di mesin ini)**: Android Studio (atau `cmdline-tools`) + Android SDK platform 34 + Gradle (wrapper dibuat oleh Studio). JDK 17+ (terpasang: 24.0.1).
2. Buka folder `android-pos/` di Android Studio → *Sync*. Versi pustaka dipatok pada sinkron pertama (`gradle/libs.versions.toml`).
3. Backend lokal: `docker compose up` (app `http://localhost:8010`). Dari emulator alamat host = `http://10.0.2.2:8010`; dari tablet nyata = IP LAN mesin. Atur lewat `local.properties` → `api.baseUrl=` (tidak di-commit).
4. Masuk dengan akun seed lokal (`kasir01` dst., kata sandi lihat `CLAUDE.md` repo backend; PIN belum ada — menunggu B1).
5. Uji domain tanpa SDK: `./gradlew :core-domain:test`. Uji lain: `./gradlew testDebugUnitTest`.
6. Printer: LAN 80 mm, port 9100; atur IP di Pengaturan → Printer.
7. Skenario validasi cepat: login → buka shift → buka meja 3 → tambah 2 menu (satu bermodifier wajib) → Kirim → Bayar tunai → struk.
