INTERNAL

# BoothPOS Belanja — aplikasi Android storefront pelanggan

Aplikasi Android native (Kotlin, Jetpack Compose, Material 3) untuk pelanggan: daftar/masuk,
katalog, keranjang, checkout ambil-sendiri atau kurir, dan pelacakan pesanan. Ini proyek Gradle
**terpisah** dari `android/` (pemasang Laravel lokal) dan hanya berbicara dengan API pelanggan
(`/api/v1/storefront/*`, `/api/v1/customer/*`), tidak pernah dengan API staf/POS.

Sumber kebenaran: `docs/android-customer-commerce/design.md`, `implementation-prompt.md`, dan
kontrak kawat (bagian 3, 5–8).

Tampilannya mengikuti desain **"Sky Capsule"** (dipilih pemilik produk, 2026-11-14): biru langit,
tombol utama kuning, aksen koral, kartu membulat, maskot ikan, dan gambar produk di dalam bingkai
"kapsul gacha" dua warna. Seluruh token desain didefinisikan sekali di `:core:ui`; lihat bagian
"Desain Sky Capsule" di bawah. Tetap tanpa carousel dan tanpa animasi dekoratif.

## Toolchain (dibuktikan dengan build di mesin ini, 2026-11-11)

| Komponen | Versi | Catatan |
|---|---|---|
| Gradle (wrapper) | 9.6.0 | `distributionSha256Sum` dipasang; AGP 9.5 butuh ≥ 9.6.0 |
| Android Gradle Plugin | 9.5.0 | Kotlin bawaan AGP: **jangan** terapkan `org.jetbrains.kotlin.android` |
| Kotlin / KSP | 2.4.21 / 2.3.12 | KSP 2.3.13 sengaja dihindari (regresi KSP paralel) |
| Hilt | 2.60.1 | `hiltViewModel()` dari `androidx.hilt:hilt-lifecycle-viewmodel-compose` 1.4.0 |
| Compose BOM | 2026.09.00 | Material 3 1.4.0, Compose UI 1.12.1, hanya `material-icons-core`; ikon garis desain digambar di `SkyIcons` |
| Room | 2.8.5 | KSP, skema diekspor ke `core/data/schemas/` |
| Robolectric | 4.17 | satu level SDK (37) untuk semua modul: `gradle/test-resources/robolectric.properties` |
| Espresso (hanya tes) | 3.7.0 | dipaksa lewat bundle `compose-test`; lihat "Jebakan tes" |
| Navigation Compose / Lifecycle / Activity Compose / core-ktx | 2.10.2 / 2.11.0 / 1.13.0 / 1.19.1 | |
| DataStore Preferences | 1.3.0 | data tidak sensitif saja (konfigurasi toko, sambutan, niat pesanan tertunda) |
| Retrofit / OkHttp (+ mockwebserver3, logging hanya debug) | 3.0.0 / 5.5.1 | converter `kotlinx-serialization` |
| kotlinx-serialization-json / kotlinx-coroutines | 1.12.0 / 1.11.0 | |
| Coil 3 (`coil-compose`, `coil-network-okhttp`) | 3.6.3 | |
| JUnit 4 / Turbine / androidx.test core, ext-junit | 4.13.2 / 1.2.1 / 1.7.0, 1.3.0 | |
| compileSdk / targetSdk / minSdk | 37 / 37 / 26 | bytecode JVM 17 lewat `compileOptions` (tanpa `jvmToolchain`) |
| JDK untuk menjalankan Gradle | 21 | `JAVA_HOME=$(/usr/libexec/java_home -v 21)` |

Semua versi ada di satu tempat: `gradle/libs.versions.toml`. Konfigurasi Android bersama untuk
modul library ada di `build.gradle.kts` akar (tanpa included build `build-logic`).

### Jebakan tes yang sudah dibuktikan (jangan dihapus)

- **JDK 17+ dan Robolectric SDK 37:** unit test dijalankan dengan
  `--add-exports=java.base/jdk.internal.access=ALL-UNNAMED` (`robolectricJvmArgs` di
  `build.gradle.kts` akar). Tanpa itu SETIAP tes Robolectric gagal dengan
  `Failed to interact with raw FileDescriptor internals`.
- **Espresso 3.7.0 di classpath tes:** `ui-test-junit4` membawa `espresso-core` lama yang memanggil
  `InputManager.getInstance()` (tidak ada di SDK 34+), sehingga setiap `createComposeRule()` gagal.
  Modul yang punya tes Compose cukup memakai `testImplementation(libs.bundles.compose.test)`.
- **Modul tanpa tes menggagalkan build** (Gradle 9: "did not discover any tests"), karena
  `gradle/test-resources` dipasang sebagai sumber tes di semua modul. Setiap modul punya minimal
  satu tes; jangan mematikan `failOnNoDiscoveredTests`.
- **Target sentuh tombol ikon:** `IconButton` Material 3 digambar 40dp; yang dijamin 48dp adalah
  area sentuhnya. Uji dengan `assertTouchHeightIsEqualTo(48.dp)`, bukan `assertHeightIsAtLeast`.
- **Kolom kata sandi:** properti semantik `Password` mengikuti jenis papan ketik, bukan transformasi
  visual; tampil/sembunyi diuji lewat `SemanticsProperties.EditableText`.
- **Mengganti nama folder resource** (mis. `mipmap-anydpi-v26` → `mipmap-anydpi`) dapat membuat
  penggabungan resource inkremental kehilangan berkasnya; hapus `app/build` lalu bangun ulang.
- Jangan menyunting sumber saat `lint`/`assembleRelease` berjalan: analisis lint gagal dengan
  "Unexpected failure during lint analysis" (terjadi sekali di slice fondasi, hilang saat diulang).

**WorkManager sengaja tidak dipakai.** v1 tidak punya pekerjaan latar yang dapat ditunda:
penyegaran selalu manual (tarik/tombol), tidak ada push, tidak ada antrean kirim offline (checkout
wajib divalidasi server saat itu juga). Menambahkannya hanya membawa dependensi dan komponen
manifest tanpa pemakai. Tambahkan bila kelak ada pekerjaan yang memang dapat ditunda/diulang.

## Perintah

Selalu dari direktori `android-customer/`. `local.properties` (di-gitignore) harus berisi
`sdk.dir=<lokasi Android SDK>`.

```bash
cd android-customer
export JAVA_HOME=$(/usr/libexec/java_home -v 21)

./gradlew :app:assembleDebug  --console=plain   # APK debug
./gradlew testDebugUnitTest   --console=plain   # semua tes JVM (termasuk Compose + Room via Robolectric)
./gradlew :app:lintDebug      --console=plain   # lint semua modul (checkDependencies), galat = gagal
./gradlew :app:assembleRelease --console=plain  # R8 + shrinkResources, APK tanpa tanda tangan
./gradlew --stop
```

Mesin pengembang ini hampir kehabisan disk dan RAM, dan swap macOS memakan sisa disk: daemon Gradle
yang dibiarkan hidup pernah menurunkan ruang kosong dari ±5 GiB ke 2,7 GiB dalam beberapa menit, dan
ruang itu kembali begitu daemon dihentikan. Karena itu di mesin ini:

- sebelum setiap build: `df -m /System/Volumes/Data | tail -1`; **jangan membangun bila sisa < 3 GiB**
  (slice hardening memakai batas 2 GiB atas instruksi, dan selama build memantau ruang kosong tiap
  5 detik serta siap menghentikan Gradle bila turun di bawah 700 MB);
- tambahkan `--no-daemon --max-workers=2` (tes: `--max-workers=1`) pada setiap perintah di atas;
- build cache Gradle sengaja tidak aktif, dan kompilasi Kotlin berjalan di dalam proses Gradle
  (`gradle.properties`).

Tes instrumentasi (satu-satunya, butuh emulator/perangkat — **belum pernah dijalankan**):
`./gradlew :core:data:connectedDebugAndroidTest` (`AndroidKeystoreTokenStoreTest`).

## Mengarahkan aplikasi ke server

Alamat API berasal dari properti Gradle `boothpos.apiBaseUrl` dan menjadi `BuildConfig.API_BASE_URL`.
Tidak ada alamat per lingkungan di dalam kode. Nilainya harus diakhiri `/`.

| Build | Bawaan | Catatan |
|---|---|---|
| debug | `http://10.0.2.2:8010/api/v1/` | emulator → server Laravel di host (`php artisan serve --port=8010`) |
| release | `https://boothpos.invalid/api/v1/` | domain cadangan yang tidak pernah ter-resolve; wajib diganti |

```bash
# Perangkat fisik lewat USB: adb reverse tcp:8010 tcp:8010, lalu
./gradlew :app:assembleDebug -Pboothpos.apiBaseUrl=http://localhost:8010/api/v1/

# Rilis: wajib HTTPS. Tugas checkReleaseApiBaseUrl menggagalkan build bila bukan https://
./gradlew :app:assembleRelease -Pboothpos.apiBaseUrl=https://toko.example/api/v1/
```

Selain `-P`, nilai yang sama dapat ditaruh di `~/.gradle/gradle.properties` (di luar repositori:
`boothpos.apiBaseUrl=https://toko.example/api/v1/`) atau di variabel lingkungan
`ORG_GRADLE_PROJECT_boothpos.apiBaseUrl` (mis. di CI). Jangan menaruhnya di `gradle.properties`
proyek bila alamatnya bersifat internal.

HTTP polos hanya diizinkan pada varian **debug** dan hanya ke `10.0.2.2` dan `localhost`
(`app/src/debug/res/xml/network_security_config.xml`). Alamat LAN (mis. `192.168.x.x`) tidak
diizinkan: pakai `adb reverse` atau server ber-HTTPS. Jangan menaruh kredensial, kunci, atau
keystore di berkas Gradle atau dokumen; `local.properties`, `keystore.properties`, `*.jks`,
`*.keystore` di-gitignore.

## Struktur modul

```
app/                 akar komposisi: Application Hilt, MainActivity, NavHost, bingkai (bilah atas/bawah)
core/ui/             tema, token, komponen stateless bersama, format Rupiah, ImageLoader berbatas
core/network/        Json, OkHttp, interseptor, Retrofit, ApiError/ApiResult/SafeApiCall, UnauthorizedEvents
core/data/           kontrak domain bersama (domain/ = Kotlin murni), Room, DataStore, sesi, keranjang, alamat
feature/{auth,catalog,cart,checkout,orders,account}/   data/ domain/ presentation/
```

- Modul fitur tidak pernah saling bergantung; kontrak lintas fitur ada di
  `core/data/.../domain/` (sesi, keranjang, alamat, konfigurasi toko, uang, waktu).
- Tiap fitur mengekspos rute `@Serializable` dan ekstensi `NavGraphBuilder.<fitur>Graph(...)`;
  navigasi antarfitur dirangkai di `app/.../ui/AppShell.kt`, dan judul/bilah tiap tujuan
  didaftarkan di `app/.../navigation/AppDestinations.kt` (`chromeFor`).
- Repository mengembalikan model domain dalam `ApiResult`; ViewModel (`@HiltViewModel`) memegang
  state immutable di `StateFlow` dan kejadian sekali-jalan lewat `Channel`; layar stateless
  menerima state + lambda. Tidak ada aturan bisnis atau HTTP di composable.
- Galat: satu model `ApiError` (`Http(status, code, detail, fieldErrors, errors, requestId,
  retryAfterSeconds)`, `Network`, `Timeout`, `Unknown`). UI beralih pada `code`
  (`ApiErrorCodes`). Komponen `ApiErrorState` memilih tampilan toko-tidak-tersedia / offline /
  galat umum, semuanya dengan "Coba lagi".
- Fake bersama untuk tes modul lain: `testImplementation(testFixtures(project(":core:data")))`
  (`InMemoryTokenStore`, `FakeSessionRepository`, `FakeCartRepository`, `FakeAddressRepository`,
  `FakeStoreConfigRepository`, `FakeConnectivityObserver`, `TestDispatcherProvider`,
  `MainDispatcherRule`, `TestData`).

Keenam modul fitur sudah berisi layar sebenarnya (lihat bagian berikut); tidak ada lagi layar awal
fondasi.

## Desain "Sky Capsule" (2026-11-14)

Perubahan TAMPILAN saja: tidak ada perubahan API, navigasi, teks (`strings.xml`), ViewModel, atau
aturan bisnis. Mockup sumbernya (tiga gambar: alur utama, keranjang + checkout, akun + pesanan)
tidak disimpan di repositori; token di bawah ini adalah salinan resminya.

### Token dan tempatnya (`core/ui/.../theme/`)

| Token desain | Nilai (terang) | Di kode |
|---|---|---|
| Latar halaman / kartu | `#F4F9FF` / `#FFFFFF` | `colorScheme.background` / `AppCard` (`surfaceContainerLow`) |
| Tinta teks | `rgb(20,40,74)` | `onSurface`; teks sekunder `onSurfaceVariant` |
| Aksi utama | `#FFD84D` isi, `#3B2A00` teks | `primaryContainer` / `onPrimaryContainer` (`PrimaryButton`) |
| Terpilih (lembut) | `#FFF3C4` + garis kuning | `secondaryContainer` (`AppCard(selected = true)`, `SelectionMark`) |
| Tautan | `#1F6FD0` di desain, `#1A66C2` di aplikasi | `primary` (tombol teks Material) |
| Hero / status | `#DCEEFF` | `BoothTheme.sky.hero`, `IconTile`, `HeroBackdrop()` |
| Progres | `#4DA3FF` | `BoothTheme.sky.progress`, `SegmentedProgress` |
| Catatan koral | `#FFE3DD` / `#7A2A1A` | `tertiaryContainer` / `onTertiaryContainer` |
| Tag positif | `#D6F5E8` / `#0F5B45` | `BoothTheme.statusColors`, `StatusBadge(tone = Success)` |
| Lencana keranjang | `#E8472F` / putih | `BoothTheme.sky.badge` |
| Ubin produk/kategori | `#DCEEFF #FFE3DD #FFF3C4 #D6F5E8 #E7E3FF` | `BoothTheme.sky.tint(n)` |
| Belahan bawah kapsul | `#FF8E7C #7FDDB8 #FFD84D #8FC8FF` | `BoothTheme.sky.capsuleLower(n)`, `ProductImage`, `ProductCapsule` |
| Bayangan | biru `rgb(46,110,190)` 12 % | `Modifier.softShadow(...)`; tidak pernah abu/hitam |
| Bentuk | kartu 28, kolom isian/ubin 16, catatan 14, tombol/chip membulat penuh | `MaterialTheme.shapes`, `PillShape`, `SheetShape`, `DockShape` |
| Tipografi | 28 / 20 / 16 / 12 sp, tebal 400 / 600, Fredoka | `Type.kt` (semua peran Material dipetakan ke empat ukuran itu) |

Keputusan yang tidak terlihat dari tabel (jangan "dirapikan"):

- **Kuning BUKAN `colorScheme.primary`.** `primary` dipakai Material untuk tombol teks, tautan, dan
  fokus kolom isian, dan `ThemeContrastTest` mensyaratkan `primary` di atas `surface` ≥ 4,5 : 1;
  kuning di atas putih hanya ±1,4 : 1. Kuning ada di `primaryContainer`, `primary` = biru tautan.
- **Teks sekunder lebih gelap daripada desain.** Desain memakai tinta 60 % (±4,0 : 1 di atas latar,
  gagal AA); `onSurfaceVariant` memakai padanan tinta ±70 % (`#5B6980`, 5,3 : 1 di atas latar,
  4,7 : 1 di atas warna hero). Warnanya yang disesuaikan, bukan tesnya.
- **Biru tautan lebih gelap daripada desain** (`#1A66C2`, bukan `#1F6FD0`): tautan juga tampil di
  atas warna hero (layar sambutan), dan di sana `#1F6FD0` hanya ±4,2 : 1. `ThemeContrastTest`
  kini juga menguji pasangan token "Sky Capsule" yang membawa teks (hero, ubin, tombol sekunder).
- **Lencana keranjang** (putih di atas `#E8472F`) ±3,9 : 1: token desain apa adanya. Angkanya juga
  disampaikan lewat deskripsi konten ("3 barang di keranjang"), jadi bukan satu-satunya pembawa makna.
- **Skema gelap belum didesain.** Mockup hanya terang; `DarkColors` adalah warna gelap LAMA, dan
  `DarkSkyColors` hanya merujuk warna yang sudah ada di sana (tidak ada warna baru yang dikarang).
  Bentuk, tipografi, dan komponen tetap sama di kedua skema. **Diputuskan 2026-11-14: warna gelap
  lama dipertahankan untuk saat ini.** Palet gelap Sky Capsule baru dibuat hanya bila diminta, dan
  saat itu `ThemeContrastTest` harus tetap hijau untuk skema gelap juga.
- **Bayangan digambar sendiri** (`Modifier.dropShadow` lewat `softShadow`), bukan `Modifier.shadow`:
  bayangan elevasi berwarna baru ada di API 28, sedangkan minSdk 26, dan di sana ia jatuh ke abu-abu.
  `softShadow` harus dipasang SEBELUM clip/latar; karena itu modifier klik sebuah kartu diteruskan
  lewat parameter `interaction` milik `AppCard`, bukan lewat `modifier`.
- **Latar hero sampai ke bilah atas:** layar yang seluruh latarnya berwarna hero (sambutan, isi
  detail produk, "Pesanan dibuat") memanggil `HeroBackdrop()`; bingkai aplikasi (`AppScaffold`)
  lalu mewarnai bilah atasnya sama. Layar tetap mewarnai dirinya sendiri, jadi tetap benar di luar
  bingkai (tes, pratinjau).
- **Warna ubin/kapsul sebuah produk** dipilih dari `paletteSeed(namaProduk)`: nama adalah
  satu-satunya data yang ada di katalog, keranjang, checkout, DAN pesanan, sehingga produk yang sama
  berwarna sama di semua layar.
- **Ikon dan ilustrasi** (`SkyIcons`, `Mascot`, `Confetti`, `CapsuleFrame`) digambar dari data path
  mockup dengan `ImageVector`/`Canvas`. `material-icons-extended` tetap tidak dipakai.

### Fredoka (lisensi)

Dua berkas statis di `core/ui/src/main/res/font/` (`fredoka_regular.ttf` 400, `fredoka_semibold.ttf`
600; 92 KB berdua), diambil dari repositori hulu yang dirujuk Google Fonts
(`hafontia-zz/Fredoka-One`, commit `35c584ff`, `fonts/ttf/`). Lisensinya **SIL Open Font License
1.1** tanpa Reserved Font Name: boleh dibundel dan didistribusikan bersama perangkat lunak asalkan
pemberitahuan hak cipta dan lisensinya ikut serta. Keduanya ada di tabel `name` berkas font, dan
teks lisensi lengkap ikut di APK (`core/ui/src/main/assets/licenses/Fredoka-OFL.txt`). Font tidak
boleh dijual tersendiri dan versi yang DIUBAH harus tetap berlisensi OFL. Belum ada layar
"Lisensi" di aplikasi (butuh teks baru). Berkas statis dipilih, bukan berkas variabel: instans
bawaan berkas variabelnya Light 300.

### Yang sengaja BERBEDA dari mockup

| Mockup | Di aplikasi | Alasan |
|---|---|---|
| "Halo, Hana", "Semua produk · 31 produk", "3 barang" di bawah judul | tidak ada | butuh teks baru; perubahan ini tidak menyentuh `strings.xml` |
| Detail produk: tombol kembali dan keranjang melayang di atas hero | bilah atas bingkai (kembali + judul), tanpa tombol keranjang | navigasi tidak berubah; hero tetap menyambung ke bilah atas lewat `HeroBackdrop()` |
| Varian sebagai chip | kartu pilihan per varian (semantik radio) | harga dan ketersediaan TIAP varian harus tetap tampil; chip tidak memuatnya |
| Panel total keranjang ikut menggulir | panel tetap disematkan di bawah | "Lanjut ke checkout" harus selalu terlihat (diuji pada huruf 200 %) |
| Grid katalog dua kolom | kolom adaptif: 2 di ponsel ≥ 360 dp, 1 di ponsel sempit atau huruf besar | nama dan harga harus terbaca pada huruf 200 % di 320 dp |
| Tombol urutkan dan muat ulang tidak ada | tetap ada, bulat putih di samping kolom cari | fungsi yang sudah ada tidak dihapus |
| Kartu produk tanpa ketersediaan; tag tanpa ikon | "Tersedia"/"Stok habis" dan ikon status tetap ada | status tidak pernah disampaikan dengan warna saja |
| Seni produk digambar di belahan atas kapsul | foto produk tampil sebagai jendela bulat di tengah kapsul | produk sungguhan berupa foto, bukan ikon |
| Lingkaran kategori berisi gambar khusus | gambar kategori dari server bila ada, selain itu huruf awal namanya | API hanya memberi nama + `image_url` opsional |
| Checkout langkah 2 dan 3 punya judul besar sendiri | baris "Langkah n dari 3 · …" tampil sebagai judulnya | teks judul terpisah hanya ada untuk langkah 1 |
| "Pesanan dibuat": kartu berisi label, cara menerima, subtotal | kartu berisi nomor pesanan saja | state `Placed` hanya membawa nomor pesanan |
| Detail pesanan: daftar lima tahap termasuk yang belum terjadi | riwayat dari server (terbaru dulu) digambar sebagai daftar langkah; batang lima segmen di kartu status | tahap yang belum terjadi butuh teks baru dan mengubah urutan yang diuji |
| "Dibatalkan" berlencana abu-abu | nada bahaya (merah muda), isi kartunya memudar | abu-abu adalah nada status yang belum dikenal (`OrderStatusUiTest`) |
| Aksi di bawah layar sambutan, masuk, daftar, akun selalu tersemat | di bawah bila isi muat, ikut menggulir bila tidak | harus tetap terjangkau pada huruf 200 % di 320 dp |

## Auth dan akun (kontrak §5)

| Layar | Rute | Isi |
|---|---|---|
| Sambutan / pilihan | `AuthChoiceRoute` | Masuk (aksi utama), Daftar, "Lihat toko tanpa akun". Muncul sendiri **sekali** pada pembukaan pertama tanpa sesi. |
| Masuk | `SignInRoute` | E-mail + kata sandi (tombol tampil/sembunyi). `invalid_credentials` → pesan generik; 429 → petunjuk tunggu dari `Retry-After`; sesi berakhir → "Sesi berakhir, silakan masuk lagi." di atas formulir. |
| Daftar | `RegisterRoute` | Nama, e-mail, telepon (opsional), kata sandi. Validasi di kolom mencerminkan aturan server; galat 422 per kolom dipetakan ke kolomnya; `registration_failed` tampil sebagai pesan formulir netral dan **tidak pernah** menandai kolom e-mail. |
| Akun | `AccountRoute` | Belum masuk: ajakan Masuk/Daftar. Sudah masuk: profil dari sesi (langsung tampil, juga offline), disegarkan sekali dari `GET /customer/me`, dengan pesan + "Coba lagi" bila gagal; Ubah profil; Keluar (dengan konfirmasi). |
| Ubah profil | `EditProfileRoute` | Nama + telepon lewat `PUT /customer/me`; e-mail hanya ditampilkan (tidak dapat diubah di v1). |

- **Alur navigasi** dirangkai di `app/.../ui/AppShell.kt`. Setelah berhasil masuk/daftar (atau
  memilih "lihat toko tanpa akun") `leaveAuth()` menutup SEMUA layar auth di puncak back stack,
  sehingga pelanggan kembali ke layar asalnya (Akun, Belanja, atau layar yang sedang dibuka saat
  sesi berakhir). Pindah Masuk ↔ Daftar mengganti layar, tidak menumpuknya.
- **Sesi berakhir:** 401 pada panggilan bertoken → sesi dihapus (`SessionRepository`) →
  `AppEvent.SessionExpired` → layar Masuk dibuka dengan pemberitahuan di dalam layarnya (bukan
  snackbar yang hilang). Layar Akun di bawahnya beralih ke ajakan masuk.
- **Keluar:** `SessionRepository.signOut()` selalu membersihkan perangkat dulu, lalu
  `POST /customer/auth/logout` upaya-terbaik (juga saat offline).
- **Kata sandi** hanya hidup di memori ViewModel: tidak di `SavedStateHandle`, tidak di
  `rememberSaveable`, tidak di log; state layar dan DTO yang memuat kata sandi/token/data pribadi
  tidak membocorkannya lewat `toString()` (ada tesnya). Nama, e-mail, dan telepon formulir ikut
  saved state supaya tidak hilang bila proses dimatikan sistem.
- **Semua kelas yang memegang token atau data pribadi** menimpa `toString()` supaya hanya
  mencetak nama tipe dan id tidak sensitif: `StoredSession`, `Account`, `Address`, `AddressInput`,
  `AccountDto`, `AddressDto`, `AddressRequestDto`, `AesGcmTokenStore.PersistedSession`, `OrderContact`,
  `OrderAddress`, `OrderDetail` (catatan bebas pelanggan ikut disembunyikan), `CheckoutRequest`,
  `AddressDraft`, dan state layar. DTO yang memuat kata sandi/token/kontak yang berupa kelas biasa
  (`LoginRequestDto`, `RegisterRequestDto`, `AuthResponseDto`, `CheckoutRequestDto`, …) memakai
  `toString()` bawaan `Object`. Tesnya: `PiiRedactionTest` (core:data) plus tes `toString` di modul
  fitur.
- **Token** tidak pernah sampai ke lapisan presentasi: `AuthRepositoryImpl` langsung
  menyerahkannya ke `SessionRepository.startSession()` (penyimpanan terenkripsi Keystore).
- **Sesi yang gagal disimpan di perangkat bukan crash.** Server sudah menjawab sukses tetapi
  penyimpanan token bisa gagal (disk penuh, berkas atau kunci Keystore bermasalah).
  `AuthRepositoryImpl` menangkapnya (pembatalan coroutine tetap diteruskan) dan mengembalikan
  `ApiError.SessionStorage`, yang tampil sebagai "Tidak bisa menyimpan sesi di perangkat ini. Coba
  lagi."; formulir tetap utuh dan dapat dikirim lagi. Token yang tidak sempat tersimpan dicabut di
  server (`POST /customer/auth/logout` dengan token itu, di scope aplikasi, upaya-terbaik, tanpa log
  dan tanpa menahan layar), karena kalau tidak ia menjadi kredensial yatim. Ini berlaku untuk
  pendaftaran DAN masuk (keputusan implementasi: tokennya yatim sama saja).
- **Pemulihan kunci Keystore** (`KeyRecovery`): bila kunci tersimpan tak dapat dipakai
  (`UnrecoverableKeyException`, `KeyStoreException`, `InvalidKeyException`), entri dibuang dan satu kunci
  baru dibuat SEKALI. Akibat yang diterima: sesi yang terenkripsi dengan kunci lama tak dapat dibuka
  lagi, jadi pelanggan masuk ulang; itu lebih baik daripada gagal menyimpan sesi selamanya. Keputusan
  pemulihannya diuji di JVM (`KeyRecoveryTest`, `AesGcmTokenStoreTest`); AndroidKeyStore sungguhan
  tidak teruji (lihat "Yang BELUM diverifikasi").
- Aturan bentuk masukan: `ContactRules` (nama/telepon, `:core:data`, dipakai pendaftaran dan ubah
  profil) dan `CredentialRules` (e-mail/kata sandi, `:feature:auth`). Pesan formulir bersama:
  `InlineMessage` di `:core:ui` (ikon + teks, diumumkan pembaca layar).

## Katalog (kontrak §6)

Tidak memerlukan sesi: ketiga endpoint-nya publik dan tidak pernah membawa token (ada tesnya).

| Layar | Rute | Isi |
|---|---|---|
| Daftar produk | `ShopRoute` | Kolom cari (ditunda 400 ms; tombol "Cari" papan ketik dan mengosongkan kolom berlaku seketika), lingkaran kategori (gambar kategori atau huruf awalnya) dari `/storefront/categories`, menu urutan (`newest`, `name`, `price_asc`, `price_desc` saja), tarik-untuk-segarkan + tombol "Muat ulang", kartu produk (gambar, nama, penjual, harga, ketersediaan) yang seluruhnya satu aksi "buka detail". Harga `price_min`; "Mulai dari …" bila `price_min != price_max`. |
| Detail produk | `ProductDetailRoute(slug)` | Gambar, nama, penjual, kategori, harga varian terpilih, kartu pilihan varian dengan semantik radio (harga + ketersediaan per varian), pengatur jumlah `1..max_qty`, deskripsi, dan satu aksi utama "Tambah ke keranjang" yang selalu terlihat. Konfirmasi berupa snackbar dengan aksi "Lihat keranjang". |

- **Halaman demi halaman:** 20 produk per permintaan (`per_page=20`); halaman berikutnya diminta
  ketika kartu di dekat akhir daftar (4 terakhir) mulai disusun. Katalog tidak pernah dimuat
  seluruhnya. Halaman berikutnya yang gagal menampilkan pesan + "Coba lagi" di kaki daftar dan
  tidak diulang otomatis. Produk yang muncul lagi di halaman berikutnya (daftar server bergeser)
  dibuang berdasarkan `slug`.
- **Pencarian/filter/urutan** selalu mulai lagi dari halaman 1 dan MEMBATALKAN permintaan yang
  sedang berjalan; jawaban lama yang tetap tiba dibuang (`ensureActive`), jadi hasil lama tidak
  pernah menimpa yang baru. Teks cari dibatasi 100 karakter (batas `q` server). Pencarian,
  kategori, dan urutan ikut saved state.
- **Keadaan layar:** memuat, kosong (toko belum punya produk → "Muat ulang"; pencarian/filter
  tanpa hasil → "Hapus pencarian dan filter"), galat + "Coba lagi", tanpa koneksi, toko tidak
  tersedia (503 `customer_commerce_disabled` / 423), dan offline dengan data cache.
- **Cache offline (Room, berbatas):** hanya DAFTAR BAWAAN (tanpa pencarian/filter, urutan
  `newest`) yang menjadi daftar offline, dan hanya 3 halaman pertamanya (60 produk); halaman 1
  yang baru menggantikan daftar lama. Hasil pencarian/filter tidak pernah disimpan. Detail produk
  yang pernah dibuka disimpan dan dapat dibuka lagi saat offline. Semuanya dipangkas ke 200 produk
  (yang paling lama di-cache dibuang dulu) dan 100 kategori pada setiap penulisan. 404 `not_found`
  membuang cache produk itu. Kegagalan menulis/membaca cache tidak pernah menggagalkan layar.
  Begitu pula cache konfigurasi toko: `StoreConfigRepositoryImpl` menelan kegagalan DataStore saat
  MENULIS (non-pembatalan, tanpa log) dan tetap mengembalikan konfigurasi dari server.
- **Offline:** bila halaman pertama daftar bawaan gagal karena jaringan/batas waktu, daftar dari
  cache tampil dengan pita "Anda sedang offline…" + "Coba lagi"; saat koneksi kembali
  (`ConnectivityObserver`) daftar dimuat ulang sendiri. Detail: sama, dari cache detail; menambah ke
  keranjang tetap diizinkan (harga/stok dipastikan lagi saat checkout).
- **Detail:** varian pertama yang dapat dibeli (`in_stock` dan `max_qty ≥ 1`) terpilih otomatis;
  varian yang habis tetap tampil, ditandai teks "Stok habis", dan tidak dapat dipilih. Berganti
  varian menurunkan jumlah ke batas varian baru. Pilihan varian + jumlah ikut saved state. Ketukan
  ganda pada "Tambah ke keranjang" hanya menambah satu kali. 404 → "Produk tidak lagi tersedia" +
  "Kembali ke daftar produk".
- **Harga yang tidak terbaca** (bukan desimal polos) menggagalkan panggilan sebagai galat umum;
  aplikasi tidak pernah menampilkan harga karangan. Field yang tidak dikenal diabaikan.
- **Navigasi:** `catalogGraph(navController, onViewCart)`; `onViewCart` dirangkai `:app` ke tab
  Keranjang (`navigateToTopLevel`). `ProductDetailRoute(slug)` publik, sehingga slice keranjang
  dapat membuka detail dari baris keranjang lewat `:app`. ViewModel detail membaca slug dari
  `SavedStateHandle["slug"]` (bukan `toRoute()`; lihat "Jebakan tambahan" di hasil verifikasi);
  `CatalogNavigationTest` menjaga kunci itu sama dengan nama argumen navigasi.

## Keranjang, alamat, dan checkout (kontrak §7, §8)

| Layar | Rute | Isi |
|---|---|---|
| Keranjang | `CartRoute` (`:feature:cart`) | Baris: gambar (membuka detail produk), nama, varian, harga satuan, pengatur jumlah `1..max_qty`, jumlah harga, tombol hapus. Hapus memunculkan snackbar "Urungkan". Bilah bawah selalu terlihat: "Total sementara", jumlah barang, keterangan **"Harga dan stok akan dikonfirmasi saat checkout."**, dan satu aksi utama "Lanjut ke checkout". Keranjang kosong: jalan kembali ke Belanja. |
| Checkout | `CheckoutRoute` (`:feature:checkout`) | Satu rute, tiga langkah (tombol kembali mundur satu langkah): (1) ambil di toko / kurir + teks petunjuk dari `/storefront/config`; (2) alamat (kurir) + kontak dari profil + catatan; (3) ringkasan dari quote server + "Buat pesanan". |
| Kelola alamat | `AddressListRoute` | Daftar (alamat utama dulu), ubah, hapus (dengan konfirmasi), jadikan utama, tambah. Dibuka dari Akun lewat `:app`. |
| Formulir alamat | `AddressFormRoute(addressId)` | Tambah (`addressId = 0`) / ubah; galat langsung di kolomnya; "Jadikan alamat utama". Dipakai checkout dan kelola alamat. |

Yang dijaga (dan ada tesnya):

- **Keranjang bertahan** di Room melewati matinya proses (`CartPersistenceTest` menutup dan membuka
  lagi basis data berkas). Total di Keranjang adalah jumlah **cuplikan** harga tersimpan (BigDecimal),
  bukan total pesanan.
- **Checkout butuh sesi.** Tanpa sesi, checkout membuka layar Masuk satu kali; setelah berhasil
  masuk/daftar alur auth ditutup (`leaveAuth()`) dan checkout berlanjut. Sesi yang berakhir di tengah
  checkout (401) tidak menghapus keranjang maupun isian; setelah masuk lagi checkout kembali ke
  langkah yang sama dengan quote baru.
- **Angka di ringkasan selalu dari quote server** (`POST /customer/orders/quote`), diminta ulang
  setiap kali langkah 3 dibuka dan setiap kali isi keranjang berubah. Aplikasi tidak mengirim harga
  apa pun; satu-satunya angka uang di permintaan adalah `expected_total` (= total quote) saat
  membuat pesanan. Kurir: baris ongkos kirim bertuliskan "Ongkos kirim dikonfirmasi toko" bila
  `shipping_cost_pending`, dan total diberi keterangan "belum termasuk ongkos kirim".
- **`Idempotency-Key` dibuat sekali per niat checkout** (`UuidIdempotencyKeyGenerator`, UUID),
  disimpan di `SavedStateHandle` bersama sidik isi pesanan (SHA-256 dari metode, alamat, kontak,
  barang terurut, catatan; tanpa `expected_total`, sama dengan cakupan hash server). Selama sidiknya
  sama kunci dipakai ulang; isi yang berubah, pesanan berhasil, atau `idempotency_key_reused`
  menghasilkan kunci baru. `price_changed` TIDAK mengganti kunci (isi pesanan sama). Selama hasil
  pengiriman belum pasti, niatnya juga tersimpan di luar layar (`PendingOrderIntentStore`), sehingga
  kunjungan checkout berikutnya dengan isi yang sama memakai kunci yang sama (lihat ASUMSI).
- **Tidak ada pengiriman ulang otomatis** (OkHttp `retryOnConnectionFailure(false)`, repository satu
  panggilan = satu permintaan). Tombol "Buat pesanan" nonaktif + indikator selama dikirim; ketukan
  kedua tidak mengirim apa pun.
- **Hasil `POST /customer/orders`:**

  | Jawaban | Perilaku |
  |---|---|
  | 201 / 200 (putar ulang) | keranjang dikosongkan, layar "Pesanan dibuat", lalu `:app` membuka Pesanan |
  | 409 `price_changed` | quote baru dari `errors.quote` (atau quote ulang bila tak terbaca) + dialog total lama/baru; pesanan baru dikirim setelah "Buat pesanan" ditekan lagi |
  | 409 `cart_invalid` | baris ditandai di keranjang lokal (`CartRepository.refresh`) dan dijelaskan per barang; "Perbaiki keranjang" kembali ke Keranjang |
  | 409 `idempotency_key_reused` | niat baru: kunci dibuang, quote ulang, pelanggan mengonfirmasi lagi |
  | 401 | alur sesi berakhir; keranjang dan isian tetap |
  | 503 `customer_commerce_disabled` / 423 (`store_locked` atau `installation_locked`) | keadaan "toko tidak tersedia" + coba lagi |
  | 422 `validation_failed` untuk alamat/kontak/catatan | kembali ke langkah 2 dengan galat di kolomnya |
  | 4xx lain (mis. 403, 404, 413 `payload_too_large`, 429) | pesan di ringkasan; kunci tetap, pelanggan menekan lagi sendiri. Kode yang belum dikenal diperlakukan umum menurut statusnya (pesan `detail` server bila ada, selain itu pesan umum) |
  | timeout, koneksi putus, jawaban tak terbaca, 5xx | **"Status pesanan belum diketahui"**: tidak pernah disebut gagal; satu-satunya aksi "Periksa status pesanan" mengirim ulang dengan kunci YANG SAMA (server memutar ulang pesanan yang sudah tercatat) |

- **Proses mati saat pesanan dikirim:** penanda "sedang dikirim" (total yang diharapkan) disimpan di
  `SavedStateHandle` sebelum permintaan berangkat, jadi setelah dipulihkan layar masuk ke "status
  belum diketahui" dengan kunci tersimpan, bukan menawarkan "Buat pesanan" baru.
- **Data pribadi tidak bocor lewat `toString`:** state formulir, `CheckoutRequest`, `AddressDraft`,
  dan DTO permintaan tidak mencetak nama/telepon/alamat (ada tesnya).

## Pesanan dan pelacakan (kontrak §8, hanya baca)

`:feature:orders` hanya MEMBACA: pelanggan tidak punya aksi yang mengubah status pesanan. Tidak ada
yang disimpan di perangkat (pesanan memuat kontak dan alamat, dan statusnya hanya bermakna bila
terbaru). Tampilannya mengikuti desain "Sky Capsule" (lihat bagian desain di atas).

- **Rute:** `OrdersRoute` (tab Pesanan) dan `OrderDetailRoute(orderNumber)`; graf
  `ordersGraph(navController, onSignIn)`. Detail tidak digerbangi toko (kontrak §2: pesanan yang
  sudah dibuat tetap dapat diikuti) dan tidak menampilkan navigasi bawah.
- **Daftar** (`GET /customer/orders?page=&per_page=20`): terbaru lebih dulu, halaman demi halaman
  (dipicu saat baris dekat akhir daftar disusun; halaman yang gagal hanya diulang atas permintaan),
  tiap baris satu tombol berisi nomor, status (ikon + teks), tanggal, ringkasan barang
  ("X dan N barang lainnya"; `item_count` = jumlah BARIS, bukan unit), dan total. Keadaan: memuat,
  kosong, galat + coba lagi, tanpa koneksi, toko terkunci (423), belum masuk (ajakan masuk, dengan
  pemberitahuan bila sesi berakhir), dan "mungkin belum terbaru" di atas daftar yang sudah dimuat.
  Muat ulang: tarik-untuk-segarkan DAN tombol "Muat ulang" yang terlihat.
- **Daftar disegarkan setiap kali layarnya tampil lagi** (kembali dari detail, pindah tab, selesai
  checkout). Halaman yang sudah dimuat tidak dibuang: halaman pertama yang baru digabung di depan
  (status terbaru menang, tanpa baris ganda). "Muat ulang" yang ditekan pelanggan mengganti daftar
  dengan halaman pertama dan mengulang paging dari awal.
- **Detail** memuat `GET /customer/orders/{nomor}` dan `GET …/{nomor}/tracking` BERSAMAAN. Rincian
  adalah sumber utama; pelacakan menimpa pengiriman + linimasa hanya bila status dan nomornya sama
  dengan rincian (kalau toko mengubah status di antara dua permintaan, rincian dipakai apa adanya
  sampai penyegaran berikutnya). Pelacakan gagal tidak menggagalkan layar: rincian tampil dengan
  pemberitahuan "mungkin belum terbaru".
- **Urutan isi detail:** status sekarang (besar: ikon + nama + waktu + penjelasan sehari-hari; alasan
  pembatalan tepat di bawahnya), nomor pesanan, barang, petunjuk ambil/kirim (teks server apa
  adanya), alamat (kurir), kontak, catatan, biaya (ongkos kirim "Dikonfirmasi toko" selama
  `shipping_cost_pending`; baris ongkos kirim tidak ada untuk ambil di toko), pelacakan, riwayat
  (terbaru dulu; `tracking_updated` dan `shipping_cost_set` punya kalimatnya sendiri).
- **"Diperbarui oleh toko"** tampil hanya bila jawaban pelacakan membawa `updated_by == "store"`;
  aplikasi tidak pernah mengaku menampilkan data langsung dari kurir.
- **"Lacak paket"** tampil HANYA bila `tracking_url` lolos `TrackingLink.safeOrNull` (satu-satunya
  aturan: awalan `https://` persis, tanpa spasi/karakter kendali, host ada, tanpa `user@`, panjang
  ≤ 2048). Menekannya menampilkan pemberitahuan "Anda akan membuka situs kurir di luar aplikasi";
  setelah disetujui tautan dibuka dengan Intent `ACTION_VIEW` di peramban luar. Tidak ada WebView,
  dan tautannya tidak pernah ditampilkan sebagai teks. Nomor resi dapat disalin (konfirmasi snackbar
  hanya di Android ≤ 12L; Android 13+ menampilkan konfirmasi salin milik sistem).
- **Kosakata status:** satu tempat (`OrderStatusUi.kt`) untuk label, penjelasan, kalimat riwayat,
  ikon, dan nada warna kedelapan status. Ikon tiap status berbeda BENTUK (jam, centang, kotak, toko,
  truk, rumah, lingkaran bercentang, silang, info), bukan hanya warna. Status, jenis peristiwa, atau
  metode yang belum dikenal versi aplikasi ini ditampilkan netral ("Status diperbarui"), tidak
  pernah membuat layar gagal.
- **Galat:** 404 `not_found` → "Pesanan tidak ditemukan" (sama untuk pesanan akun lain; tidak
  dicoba ulang); 401 → rincian/daftar langsung dibuang dari layar, alur sesi berakhir milik `:app`
  membuka layar masuk, dan setelah masuk lagi layar memuat ulang sendiri; jaringan/timeout → keadaan
  tanpa koneksi + coba lagi; 423 → toko tidak tersedia.
- **Sesi:** daftar dan detail mengikuti `SessionRepository.state`. Keluar (atau sesi berakhir)
  langsung mengosongkan layar, jawaban yang terlambat dibuang, dan akun lain yang masuk tidak pernah
  melihat pesanan akun sebelumnya.
- **Data pribadi:** `OrderContact`/`OrderAddress` dan DTO-nya tidak mencetak isinya lewat
  `toString` (ada tesnya), dan pesanan tidak pernah ditulis ke Room/DataStore/log.

## Postur keamanan

| Area risiko | Kontrol |
|---|---|
| Manajemen sesi / rahasia | Token + data akun dienkripsi AES-256-GCM dengan kunci Android Keystore (`AesGcmTokenStore`, `noBackupFilesDir`); tidak pernah di SharedPreferences, Room, DataStore, log. `security-crypto` tidak dipakai (semua API-nya deprecated). |
| Sesi berakhir | 401 pada panggilan `…/customer/…` yang membawa token → `UnauthorizedEvents` → `SessionRepository` menghapus sesi (hanya bila token yang ditolak masih token aktif) → bingkai aplikasi mengarahkan ke layar masuk dengan pesan. Keluar: perangkat dibersihkan dulu, pencabutan di server upaya-terbaik. |
| Transport | Rilis: HTTPS saja (`usesCleartextTraffic=false`, network security config, CA sistem saja), pemeriksaan build + runtime. Timeout 10 s sambung, 30 s baca/tulis, dan batas TOTAL satu panggilan 60 s (`callTimeout`; terlampaui → `ApiError.Timeout`, untuk checkout jalur "status belum diketahui"). `retryOnConnectionFailure(false)`: checkout tidak pernah diulang otomatis. |
| Kebocoran token | `AuthInterceptor` menempelkan Bearer hanya ke `…/customer/…` pada host API yang dikonfigurasi, tidak ke login/register, storefront, atau host lain. Klien HTTP gambar terpisah dan tanpa token. |
| Logging | Interseptor logging hanya ada di source set `debug` (`BASIC`, `Authorization`/`Idempotency-Key` teredaksi, tanpa body); APK rilis tidak memuat pustakanya. `ApiError` tidak membawa Throwable/body mentah. |
| Data di perangkat | `allowBackup=false` + aturan ekstraksi yang mengecualikan semuanya. Room hanya berisi keranjang dan cache katalog publik berbatas (200 produk, 100 kategori). Alamat/pesanan tidak disimpan lokal. Cache gambar dibatasi (15 % memori, 50 MB disk). |
| Tautan keluar | Satu-satunya tautan yang dibuka aplikasi adalah `tracking_url` pesanan, dan hanya setelah lolos `TrackingLink` (https, host wajar, tanpa `user@`) serta pemberitahuan serah terima; dibuka di peramban luar (`ACTION_VIEW`), tidak pernah di WebView. Jawaban server tetap diperlakukan sebagai data tak tepercaya. |
| Permukaan serangan | Hanya activity peluncur yang diekspor (receiver `ProfileInstallReceiver` bawaan pustaka dibuang lewat manifest merger). Tidak ada deep link, WebView, analitik, atau SDK iklan. Varian **debug** saja memuat `PreviewActivity` dan `ComponentActivity` tes dari `ui-tooling`/`ui-test-manifest`. |
| Otoritas | Harga, stok, total, kepemilikan, dan status selalu dari server; aplikasi hanya menampilkan dan memvalidasi bentuk masukan. Uang diurai dengan `BigDecimal` (`Money`), ditampilkan `Rp15.000`. |
| Build rilis | R8 + `shrinkResources`; `proguard-rules.pro` kosong (aturan keep hanya bila build membuktikan perlu). |

## Audit hardening (2026-11-12)

### Persyaratan keamanan/performa → tempat yang memenuhinya

Daftar dari `implementation-prompt.md` ("Android security/performance requirements"), satu per satu:

| Persyaratan | Dipenuhi oleh | Dikunci oleh tes |
|---|---|---|
| HTTPS saja lewat Network Security Config, tanpa teks biasa di produksi | `app/src/main/res/xml/network_security_config.xml` (teks biasa dilarang, hanya CA sistem), `AndroidManifest.xml` (`usesCleartextTraffic=false`), tugas `checkReleaseApiBaseUrl` (`app/build.gradle.kts`); pengecualian `10.0.2.2`/`localhost` hanya di `app/src/debug/res/xml/` | `SecurityConfigurationTest` (mengurai XML rilis, XML debug, dan manifest) |
| Validasi TLS/sertifikat, timeout, tanpa pengulangan otomatis yang tidak aman untuk checkout | `HttpClientFactory` (tanpa TrustManager/HostnameVerifier/pinning buatan sendiri, 10 s sambung / 30 s baca-tulis / 60 s total per panggilan, `retryOnConnectionFailure(false)`, `followSslRedirects(false)`); `CheckoutViewModel` (tidak pernah mengirim ulang sendiri; "status belum diketahui" + kunci yang sama) | `HttpClientFactoryTest`, `CheckoutRepositoryImplTest`, `CheckoutViewModelTest` |
| Redaksi otorisasi/PII di log; logging jaringan hanya debug; tanpa body berisi kredensial/alamat | `core/network/src/debug/.../BuildTypeInterceptors.kt` (`BASIC`, `Authorization` dan `Idempotency-Key` teredaksi), versi `src/release` = `emptyList()`, pustaka logging hanya `debugImplementation`; `toString` state/DTO yang memuat data pribadi ditimpa; tidak ada `Log.`/`println` di kode utama | `DebugLoggingRedactionTest`, `SecurityConfigurationTest`, tes `toString` di modul fitur |
| Hapus token/sesi saat keluar, akun dinonaktifkan, dan 401 yang tak terpulihkan | `SessionRepositoryImpl` + `AuthInterceptor` + `UnauthorizedEvents`, `AesGcmTokenStore.clear()`; akun yang dinonaktifkan dijawab server 401 `unauthenticated`, jadi lewat jalur yang sama | `SessionRepositoryImplTest`, `AuthInterceptorTest`, `AesGcmTokenStoreTest` |
| Tidak mengekspor komponen tanpa kebutuhan; hindari WebView | `AndroidManifest.xml` (hanya `MainActivity`; `ProfileInstallReceiver` dibuang); tautan pelacakan dibuka di peramban luar (`ACTION_VIEW`), tidak ada WebView | `SecurityConfigurationTest`, `OpenInBrowserTest` |
| Batasi ukuran gambar/cache, jangan memuat seluruh katalog, tangani bandwidth rendah/offline | `BoundedImageLoader` (15 % memori, 50 MB disk, klien tanpa token), `CatalogCacheDao` (200 produk, 100 kategori), halaman 20, daftar offline 60 produk, `OfflineBanner` + `ConnectivityObserver` | `CatalogCacheDaoTest`, `CatalogRepositoryImplTest`, `ShopViewModelTest` |

Tidak ada SDK analitik, iklan, atau pelaporan crash di katalog versi; tidak ada `TODO`/`FIXME`/teks
contoh, literal `Text("…")` di kode utama, `Log.`/`println`, WebView, atau `usesCleartextTraffic`
selain `false` (diperiksa dengan grep pada seluruh sumber).

### Aksesibilitas dan UX (diperiksa di kode, diuji di Robolectric)

- **Judul halaman:** judul bilah atas kini `semantics { heading() }`. Sebelumnya delapan layar
  (Masuk, Daftar, Belanja, Keranjang, Pesanan, Ubah profil, daftar dan formulir alamat) tidak punya
  judul sama sekali bagi navigasi per judul TalkBack. `AppScaffoldTest` menguncinya.
- **Huruf 200 % pada ponsel 320 dp:** `*LargeFontLayoutTest` di tujuh modul merender layar utama
  (sambutan, masuk, daftar, belanja, detail produk, keranjang, tiga langkah checkout, formulir alamat,
  daftar dan detail pesanan, akun, ubah profil, bingkai aplikasi) dengan skala huruf linear 2,0 dan
  memastikan aksi utamanya tampil (aksi yang disematkan di bawah: tanpa menggulir; aksi di dalam
  konten: setelah digulir). Sebagian dirender dengan tema gelap.
- **Perbaikan yang ditemukan:** keadaan kosong/galat/toko tidak tersedia (`MessageState` di
  `StateViews.kt`) adalah kolom tetap di tengah layar; pada layar pendek dengan huruf besar tombol
  aksinya terdorong keluar layar TANPA jalan untuk mencapainya. Sekarang kolom itu menggulir bila
  isinya tidak muat (tetap di tengah bila muat, dan tanpa gulir bersarang di dalam induk yang sudah
  menggulir). Tes regresi di `SharedComponentsTest` (320 × 320 dp, huruf 200 %).
- Target sentuh: tombol utama `heightIn(min = 56.dp)`, tombol sekunder `heightIn(min = 48.dp)`,
  tombol bulat digambar 44 dp dengan area sentuh 48 dp, tombol ikon M3 punya area sentuh
  48 dp, baris pilihan dan kartu seluruhnya dapat diketuk; diuji dengan `assertHeightIsAtLeast` /
  `assertTouchHeightIsEqualTo`.
- Setiap tombol ikon punya `contentDescription`; gambar produk di daftar/keranjang dekoratif (nama di
  sebelahnya), di detail berdeskripsi nama produk; gambar keranjang yang dapat diketuk berlabel
  "Lihat produk …". Status selalu ikon + teks (+ waktu di pesanan), tidak pernah warna saja.
- Formulir: jenis papan ketik, aksi IME, dan petunjuk isi-otomatis (`ContentType`) ada di masuk,
  daftar, ubah profil, kontak checkout, dan formulir alamat (nama, telepon, jalan, kota, provinsi,
  kode pos).
- Layar lebar: konten dibatasi 640 dp di tengah (`AppShell`), bukan dasbor. Ukuran teks memakai
  tipografi Material (sp); tidak ada ukuran huruf dalam dp.

### Rekonsiliasi dengan backend

`BACKEND-AS-BUILT.md` tidak ada, jadi setiap DTO, path, metode, dan header dicocokkan dengan kode
Laravel yang sudah dibangun (hanya dibaca): `routes/api.php`, `app/Http/Controllers/Api/{Customer,Storefront}`,
`app/Http/Resources/{Customer,Storefront}`, `app/Http/Requests/{Customer,Storefront}`,
`CustomerCheckoutService`, `CustomerCommerceProblem`, `CustomerCommerceException`.

- **Tidak ada ketidakcocokan bentuk kawat** yang perlu diperbaiki: semua path/metode, nama field,
  amplop `data`/`meta`, bentuk `Quote`, `errors.items` (`cart_invalid`), `errors.quote`
  (`price_changed`), header `Idempotency-Key` (pola `[A-Za-z0-9_-]{16,64}`), `X-Request-Id` (UUID
  memenuhi pola server), `Accept-Language: id`, dan kode galat cocok dengan aplikasi.
- Catatan (tidak mengubah aplikasi): backend punya `GET /customer/addresses/{id}` yang tidak ada di
  kontrak; aplikasi tetap memuat alamat dari daftar. Backend dapat mengirim kode `method_not_allowed`
  (405) dan `request_failed` (cadangan) yang tidak ada di kontrak; aplikasi menampilkannya sebagai
  galat umum. `shipping_cost_pending` pada pesanan hanya `true` selama `awaiting_confirmation`, dan
  aplikasi memakai nilai server apa adanya.

### Pertanyaan senior per fitur

| Pertanyaan | Penanganan |
|---|---|
| Jaringan gagal | Setiap layar data punya keadaan offline/galat + "Coba lagi"; katalog jatuh ke cache berbatas; keranjang lokal. |
| Ketukan ganda | ViewModel mengabaikan aksi kedua selama permintaan berjalan dan tombol nonaktif (masuk, daftar, simpan, tambah ke keranjang, buat pesanan, periksa status). |
| Timeout di tengah checkout | "Status pesanan belum diketahui", kirim ulang hanya atas permintaan dengan kunci yang sama. **Celah nyata yang diperbaiki:** meninggalkan checkout dalam keadaan itu lalu memesan isi yang sama dulu membuat kunci baru; sekarang `PendingOrderIntentStore` memakai kunci yang sama lintas kunjungan (lihat ASUMSI). |
| Token kedaluwarsa di tengah alur | 401 → sesi dihapus → layar masuk; keranjang, isian checkout, dan kunci dipertahankan; setelah masuk kembali ke tempat semula. |
| Proses mati dengan keranjang | Keranjang di Room; isian checkout dan penanda "sedang dikirim" di `SavedStateHandle`; niat tertunda di DataStore. |
| Cache basi | Harga/stok di keranjang dan cache hanya cuplikan tampilan; checkout selalu meminta quote server dan server memeriksa ulang saat membuat pesanan (`price_changed`, `cart_invalid`). |
| Field jawaban hilang/tak terduga | `ignoreUnknownKeys`, field opsional ber-nilai bawaan, status/peristiwa tak dikenal tampil netral; field wajib yang hilang menjadi galat umum, tidak pernah crash. |
| Katalog besar | Halaman 20 per permintaan, dipicu saat mendekati akhir daftar; cache dibatasi. |
| Memori rendah | Cache gambar Coil 15 % memori + 50 MB disk; gambar dimuat sesuai ukuran tampilan; tidak ada daftar penuh di memori selain halaman yang sudah dimuat. |

## ASUMSI (belum diputuskan pemilik produk; dibuat sesederhana mungkin)

- **Keranjang saat keluar:** dikosongkan ketika pelanggan menekan "Keluar" (perangkat mungkin
  dipakai orang lain), tetapi dipertahankan bila sesi berakhir sendiri (401/kedaluwarsa), supaya
  ia dapat masuk lagi dan melanjutkan checkout. Satu kelas: `CartSignOutCleaner`.
- **Toko tidak tersedia:** `enabled=false`, 503 `customer_commerce_disabled`, atau 423 menutup
  tujuan yang membuat pesanan baru (Belanja, Keranjang, Checkout) dengan keadaan tenang + "Coba
  lagi"; Pesanan dan Akun tetap dapat dibuka (kontrak §2). 423 dulu tidak punya body problem+json
  (dipetakan ke kode sisi klien `store_locked`); backend kini dapat menjawab problem+json dengan kode
  `installation_locked`. Keduanya tetap "toko tidak tersedia" menurut STATUS 423, dan `detail`
  server SELALU dibuang untuk 423 (urusan pemilik toko, bukan pelanggan).
- **Kode galat baru dari server** (mis. 413 `payload_too_large`) tidak membutuhkan perubahan aplikasi:
  pesan dipilih menurut kode yang dikenal, jatuh ke `detail` server bila ada, selain itu pesan
  umum; keputusan perilaku (mis. checkout) memakai status HTTP.
- **Pesan field berupa kunci terjemahan mentah** (`validation.*`, server yang kehilangan berkas
  bahasanya) dibuang di `ProblemJsonParser`; kolomnya tetap tercatat (daftar kosong) dan layar
  memakai teks lokalnya atau pesan umum.
- **Kode galat tanpa body problem+json** diturunkan dari status HTTP (401 → `unauthenticated`,
  404 → `not_found`, 429 → `too_many_requests`, ≥ 500 → `server_error`, lainnya `http_error`).
- **`AddressRepository` diimplementasikan di `:core:data`** (bukan hanya kontraknya), karena
  dipakai dua fitur (akun dan checkout) yang tidak boleh saling bergantung.
- **Sesi kedaluwarsa saat aplikasi baru dibuka** tidak memaksa ke layar masuk; hanya 401 saat
  aplikasi dipakai yang mengarahkan ke sana.
- **Kolom alamat opsional selalu dikirim** (`label`, `postal_code`, `notes`), sebagai `null`
  eksplisit bila dikosongkan. Kontrak §7 tidak menyebut apakah kunci yang hilang pada `PUT` berarti
  "kosongkan" atau "biarkan"; `null` eksplisit benar untuk kedua tafsiran. Satu tempat:
  `AddressRequestDto`.
- Cuplikan harga/batas di keranjang hanya untuk tampilan; `CartRepository.refresh()` menyegarkannya
  dari jawaban quote.
- **Tidak ada atur-ulang kata sandi.** Kontrak §5 tidak punya endpoint-nya, jadi layar Masuk hanya
  menulis dengan jujur bahwa fitur itu belum tersedia dan menyarankan menghubungi toko. Perlu
  keputusan pemilik produk (alur reset + verifikasi e-mail).
- **Layar sambutan tampil sekali** pada pembukaan pertama tanpa sesi (penanda tidak sensitif di
  DataStore, `OnboardingRepository`); ditandai "sudah tampil" sebelum ditampilkan, jadi tidak
  pernah berulang. Sesudah itu pilihan Masuk/Daftar ada di layar Akun.
- **Bentuk e-mail di perangkat** mensyaratkan domain bertitik (`nama@domain.tld`). Ini sedikit
  lebih ketat daripada aturan `email` Laravel (yang menerima `a@b`); tujuannya menangkap salah
  ketik. Satu tempat: `CredentialRules`.
- **Layar Masuk tidak menerapkan aturan panjang kata sandi** (hanya "wajib diisi"), supaya tidak
  membocorkan kebijakan dan tidak menolak akun lama bila aturan berubah.
- **`PUT /customer/me` selalu mengirim `phone`**, sebagai `null` eksplisit bila dikosongkan (alasan
  yang sama dengan kolom alamat opsional). Satu tempat: `UpdateProfileRequestDto`.
- **Petunjuk tunggu 429 bersifat statis** ("coba lagi dalam N detik / sekitar N menit" dari
  `Retry-After`), bukan hitung mundur; tombol tetap dapat ditekan dan server tetap penentu.
- **Konfirmasi keluar menyebut keranjang dikosongkan**, sesuai asumsi "Keranjang saat keluar" di atas.
- **Daftar offline hanya untuk daftar bawaan.** Saat offline dengan pencarian, filter kategori,
  atau urutan lain yang aktif, layar menampilkan "Tidak ada koneksi" + "Coba lagi", bukan hasil
  yang disaring sendiri dari cache: cache hanya berisi 60 produk pertama, jadi hasil saringan lokal
  akan tampak lengkap padahal tidak. Chip "Semua" / mengosongkan pencarian mengembalikan daftar
  offline. Batas 3 halaman adalah satu konstanta (`CatalogRepositoryImpl.OFFLINE_LIST_PAGES`).
- **Galat server (5xx) tidak jatuh ke cache**; hanya galat jaringan/batas waktu. Toko tidak tersedia
  (503/423) selalu mengganti daftar/detail dengan keadaan "toko tidak tersedia", juga bila ada cache.
- **Kategori terpilih yang tidak lagi dikirim server dilepas** (filter kembali ke "Semua"), karena
  chip-nya tidak lagi terlihat untuk dimatikan. Kegagalan memuat kategori tidak menjadi galat
  layar: baris chip hanya tidak tampil (atau memakai cache saat offline).
- **Kartu produk memakai `in_stock` dari server apa adanya** (kontrak §6 hanya mendaftar produk yang
  punya varian terjual, jadi praktis selalu "Tersedia"); varian yang dapat dibeli = `in_stock` dan
  `max_qty ≥ 1`.
- **Jumlah di detail tidak dikurangi isi keranjang.** Pengatur jumlah dibatasi `max_qty`; bila
  penambahan membuat baris keranjang melampaui batas, `CartRepository` memangkasnya dan snackbar
  menyebut "Jumlah di keranjang disesuaikan ke batas N barang".
- **Gambar varian** dipakai bila ada, selain itu gambar produk (di detail dan cuplikan keranjang).
- **Kelola alamat tinggal di `:feature:checkout`** (rute `AddressListRoute` / `AddressFormRoute`),
  karena checkout yang memakainya; layar Akun membukanya lewat `:app` (tombol "Alamat pengiriman"),
  tanpa ketergantungan antarfitur.
- **Semua galat tanpa jawaban pasti saat membuat pesanan dianggap "status belum diketahui"**:
  timeout, galat jaringan apa pun (termasuk yang mungkin terjadi sebelum permintaan terkirim, karena
  dari sisi klien keduanya tidak dapat dibedakan dengan aman), jawaban 2xx yang tak terbaca, dan
  5xx. Mengirim ulang dengan kunci yang sama aman di semua kasus itu. Hanya 4xx, 423, dan 503
  `customer_commerce_disabled` yang dianggap PENOLAKAN (permintaan ini tidak membuat pesanan);
  penolakan belum berarti NIATNYA selesai, lihat butir berikutnya.
- **Niat pesanan yang hasilnya belum pasti disimpan di luar layar checkout** (slice hardening;
  sebelumnya kunci hanya hidup di `SavedStateHandle`, sehingga meninggalkan checkout saat status
  belum diketahui lalu memesan isi yang sama membuat kunci baru dan dapat menggandakan pesanan).
  Sekarang `PendingOrderIntentStore` (DataStore, satu entri: id akun + sidik SHA-256 + kunci acak,
  tanpa data pribadi) dicatat SEBELUM permintaan berangkat dan dilupakan HANYA bila HANDLER server
  menjawab pasti: pesanan dibuat, 409 (`price_changed`, `cart_invalid`, `idempotency_key_reused`),
  atau 422 (`validation_failed`, `idempotency_key_required`)
  (`CheckoutRules.settlesPendingIntent`). **Penolakan middleware (401, 403, 404, 413, 423, 429, 503)
  dan status/kode apa pun di luar daftar itu TIDAK melupakannya**: middleware menolak SEBELUM server
  mencari kunci idempotensi, jadi jawabannya tidak mengatakan apa pun tentang percobaan sebelumnya
  yang jawabannya hilang. Dulu setiap 4xx menghapusnya; skenarionya: pesanan tercatat tetapi
  jawabannya hilang → "Periksa status pesanan" → 401/503 → catatan terhapus → pelanggan keluar dari
  checkout dan kembali dengan isi yang sama → kunci BARU → pesanan kedua. Tampilan per kasus tidak
  berubah (sesi berakhir menjaga keranjang dan isian, toko tidak tersedia tampil sebagai
  keadaannya); yang dibersihkan hanya penanda "sedang dikirim" milik layar itu. Menyimpan lebih lama
  tidak merugikan (kunci yang sama untuk isi yang sama aman); melupakan terlalu cepat berbahaya.
  Kunjungan checkout berikutnya dengan isi yang SAMA dari akun yang SAMA memakai kunci itu lagi,
  sehingga server memutar ulang pesanan pertama (200) alih-alih membuat yang kedua. Akibat yang
  diterima: bila pesanan pertama memang tercatat, pelanggan yang memesan isi yang sama lagi melihat
  pesanan pertama itu ("Pesanan dibuat" dengan nomor lamanya); percobaan berikutnya mendapat kunci
  baru. Tidak ada batas umur entri (ASUMSI: server menyimpan kunci tanpa batas waktu, jadi memutar
  ulang tetap aman kapan pun). "Keluar" melupakan entri; sesi yang berakhir (401) tidak. Kegagalan
  penyimpanan tidak menghalangi pemesanan (perlindungan `SavedStateHandle` tetap berlaku). Konfirmasi
  di tombol kembali pada keadaan "status belum diketahui" tetap ada.
- **Kunci baru juga dibuat bila kontak atau catatan berubah**, bukan hanya keranjang/alamat/metode,
  karena server menghitung hash payload dari semuanya; memakai kunci lama akan dijawab
  `idempotency_key_reused`.
- **`contact` selalu dikirim** (`name` + `phone` dari langkah 2, diisi awal dari profil), dan nomor
  telepon wajib di perangkat karena kontrak §8 mensyaratkannya "in the end result".
- **Baris keranjang yang ditandai memblokir "Lanjut ke checkout"** (jumlah di atas batas terakhir
  dari server, atau batas 0). Batas itu cuplikan; quote yang berhasil menyegarkannya lagi.
- **Alasan `cart_invalid` yang belum dikenal** diperlakukan menurut `max_qty`-nya (0 = tidak
  tersedia, selain itu stok kurang), supaya alasan baru di server tidak merusak aplikasi lama.
- **Urungkan hapus menaruh baris di urutan terakhir** (keranjang diurutkan menurut waktu masuk) dan
  tidak ditawarkan untuk barang yang sudah tidak dijual.
- **Mengubah alamat memuat datanya dari daftar** (`GET /customer/addresses`): kontrak §7 tidak punya
  "ambil satu alamat". "Jadikan utama" di daftar mengirim `PUT` dengan seluruh kolom alamat itu dan
  `is_default: true`.
- **Alamat utama tidak dapat dicabut dari formulirnya sendiri** (kotak centang terkunci): kontrak
  tidak menyebut apa yang terjadi bila satu-satunya alamat utama dikirim dengan `is_default: false`.
  Gantinya dengan menjadikan alamat lain utama.
- **Alamat baru diisi awal dengan nama dan telepon dari profil**; alamat yang baru ditambahkan dari
  checkout langsung terpilih saat kembali (dikenali sebagai satu-satunya id baru di daftar).
- **Akun lain yang masuk di tengah checkout** memulai formulir dari awal (isian, alamat terpilih,
  dan kunci akun sebelumnya dibuang); keranjang tetap.
- **Setelah pesanan dibuat aplikasi membuka detail pesanan itu di atas tab Pesanan** (Kembali
  menuju daftar pesanan) dan menampilkan nomornya di snackbar. Satu fungsi:
  `openOrderAfterCheckout(orderNumber)` di `AppShell.kt`. Detail lama yang tersimpan di tab Pesanan
  dibuang dengan `popUpTo<OrdersRoute>`, bukan `launchSingleTop`: `launchSingleTop` memakai ulang
  entri back stack (dan ViewModel) yang sama, sehingga pesanan lama yang akan tampil.
- **Detail pesanan memanggil dua endpoint** (`…/{nomor}` dan `…/{nomor}/tracking`) pada setiap
  muat/penyegaran, karena hanya jawaban pelacakan yang membawa `updated_by`. Bila pelacakan gagal
  pada muatan pertama, label "Diperbarui oleh toko" tidak ditampilkan (aplikasi tidak mengaku tahu
  sumbernya) dan pemberitahuan "mungkin belum terbaru" tampil dengan "Coba lagi".
- **Awalan `https://` harus huruf kecil persis** agar "Lacak paket" tampil (bentuk yang dijanjikan
  kontrak §8); `HTTPS://…` dianggap tidak aman. Host bergaris bawah juga ditolak (`java.net.URI`
  tidak mengenalinya sebagai host).
- **Alasan pembatalan ditaruh tepat di bawah status**, bukan di bawah rincian biaya: itulah
  pertanyaan pertama pelanggan saat melihat "Dibatalkan".
- **SKU barang tidak ditampilkan** di detail pesanan (kontrak mengirimnya; pelanggan mengenali
  barang dari namanya). Nilainya tetap ada di model domain bila kelak diperlukan.
- **Tanggal pesanan diformat dengan lokal id-ID pada zona waktu perangkat** ("12 Nov 2026, 14.05"),
  sama seperti Rupiah yang selalu id-ID; satu tempat (`OrderDateFormatter`) bila terjemahan lain
  ditambahkan.
- **Pesanan di halaman 2 dst. tidak ikut disegarkan saat layar daftar tampil lagi** (hanya halaman
  pertama yang diambil ulang, supaya posisi gulir dan halaman yang sudah dimuat tidak hilang);
  "Muat ulang" mengambil semuanya dari awal, dan detail selalu mengambil status terbaru.
- **Jawaban pesanan dengan uang yang bukan desimal polos menggagalkan panggilan** (`ApiError.Unknown`,
  galat umum), sedangkan tanggal yang tidak terbaca hanya menghilangkan tanggal itu dari layar.
- **Panah kembali di bilah atas = tombol kembali sistem** (lewat `OnBackPressedDispatcher`), supaya
  checkout mundur satu langkah di kedua jalan itu.

## Yang BELUM diverifikasi (pemeriksaan manual yang TIDAK dilakukan)

Lihat bagian "Hasil verifikasi" di bawah untuk apa yang benar-benar dijalankan. Jangan menganggap
hal-hal berikut sudah diperiksa; semuanya butuh perangkat/emulator atau server sungguhan:

- **Urutan baca TalkBack di perangkat.** Label, peran, judul (`heading`), dan target sentuh diuji
  lewat pohon semantik Compose di Robolectric, bukan dengan TalkBack yang menyala.
- **Skala huruf di perangkat nyata.** Tes `*LargeFontLayoutTest` merender layar utama pada 320 dp
  dengan skala huruf LINEAR 2,0 (lebih berat daripada skala non-linear Android 14+) di Robolectric;
  tampilan nyata, pemotongan teks, dan perilaku papan ketik tidak dilihat.
- **Tes instrumentasi Keystore** (`AndroidKeystoreTokenStoreTest`) hanya dikompilasi, tidak pernah
  dijalankan; penyimpanan token AES-GCM diuji di JVM dengan kunci tiruan. Pemulihan kunci
  (`KeyRecovery`) diuji di JVM dengan pengecualian `java.security` yang sama; yang TIDAK teruji:
  bahwa AndroidKeyStore sungguhan melempar pengecualian itu pada kunci yang dibatalkan dan bahwa
  `deleteEntry` + pembuatan ulang bekerja di perangkat (`AndroidKeystoreKeyProvider`).
- **Menjalankan aplikasi terhadap server Laravel sungguhan.** Semua tes HTTP memakai MockWebServer
  dengan jawaban yang ditulis dari kontrak; bentuk kawatnya dicocokkan dengan kode Laravel dengan
  membaca kodenya (lihat "Rekonsiliasi dengan backend"), bukan dengan panggilan nyata.
- **APK rilis saat dijalankan.** APK rilis dibangun (R8 + `shrinkResources`), tetapi tidak
  ditandatangani (belum ada keystore/konfigurasi signing) dan belum pernah dipasang, jadi perilaku
  Retrofit + kotlinx.serialization setelah R8 belum terbukti.
- **Tampilan "Sky Capsule" di perangkat atau emulator.** Semua layar hanya dirender di JVM
  (Robolectric, grafik native, `robolectric.pixelCopyRenderMode=hardware`) dan dibandingkan dengan
  tiga gambar mockup. Yang TIDAK dilihat di perangkat: bayangan `dropShadow` di GPU sungguhan dan
  biayanya pada daftar panjang di ponsel lemah, warna bilah status/navigasi sistem (edge-to-edge),
  foto produk sungguhan dari server di dalam kapsul (dirender dengan gambar buatan), tema gelap,
  Fredoka untuk huruf di luar Latin (jatuh ke font sistem), riak sentuhan, dan papan ketik.
- Kontras tema diuji dengan rumus WCAG di tes unit, bukan dengan alat aksesibilitas di perangkat.
- Tidak ada CI di repositori ini; gerbang kualitas adalah perintah lokal di atas.

## Hasil verifikasi (slice fondasi)

Dijalankan 2026-11-11 di mesin pengembang (macOS, JDK 21 JBR 21.0.11, `--no-daemon`), berurutan,
setelah perubahan kode terakhir:

| Perintah | Hasil |
|---|---|
| `./gradlew testDebugUnitTest` | BUILD SUCCESSFUL — 116 tes, 24 suite, 0 gagal, 0 dilewati (app 18, core:data 44, core:network 27, core:ui 21, enam modul fitur masing-masing 1) |
| `./gradlew :app:assembleDebug` | BUILD SUCCESSFUL — `app-debug.apk` |
| `./gradlew :app:lintDebug` | BUILD SUCCESSFUL — "No issues found." (semua modul, `checkDependencies`) |
| `./gradlew :app:assembleRelease` | BUILD SUCCESSFUL — R8 + `shrinkResources`, `app-release-unsigned.apk` ±1,5 MB, tanpa aturan keep tambahan |
| `./gradlew :app:preReleaseBuild -Pboothpos.apiBaseUrl=http://…` | GAGAL sesuai harapan di `checkReleaseApiBaseUrl` (rilis menolak alamat non-HTTPS) |
| `./gradlew :core:data:compileDebugAndroidTestKotlin` | BUILD SUCCESSFUL — tes instrumentasi Keystore hanya DIKOMPILASI, tidak dijalankan |

Diperiksa pada keluaran rilis: manifest gabungan hanya mengekspor `MainActivity`
(`InitializationProvider` dan `MultiInstanceInvalidationService` Room `exported=false`), izin hanya
`INTERNET` + `ACCESS_NETWORK_STATE`, `classes.dex` tidak memuat `HttpLoggingInterceptor` maupun
`10.0.2.2`.

Belum dibuktikan oleh build: perilaku APK rilis saat dijalankan (Retrofit + kotlinx.serialization
setelah R8), karena tidak ada emulator/perangkat yang dinyalakan.

## Hasil verifikasi (slice auth-account)

Dijalankan 2026-11-11 di mesin pengembang (macOS, JDK 21 JBR 21.0.11, `--no-daemon`), berurutan,
setelah perubahan kode terakhir:

| Perintah | Hasil |
|---|---|
| `./gradlew testDebugUnitTest` | BUILD SUCCESSFUL — 238 tes, 39 suite, 0 gagal, 0 dilewati (app 24, core:data 50, core:network 27, core:ui 23, feature:auth 65, feature:account 45, empat modul fitur lain masing-masing 1) |
| `./gradlew :app:assembleDebug` | BUILD SUCCESSFUL — `app-debug.apk` |
| `./gradlew :app:lintDebug` | BUILD SUCCESSFUL — "No issues found." (semua modul, `checkDependencies`) |
| `./gradlew :app:assembleRelease` | BUILD SUCCESSFUL — R8 + `shrinkResources`, `app-release-unsigned.apk` ±1,9 MB, tanpa aturan keep tambahan |

Tetap BELUM diverifikasi untuk slice ini: tidak ada yang dijalankan di emulator/perangkat (isi-otomatis
pengelola kata sandi, TalkBack, skala font, papan ketik sungguhan, tampilan nyata), tidak ada
panggilan ke server Laravel sungguhan (semua tes HTTP memakai MockWebServer), dan perilaku APK rilis
saat dijalankan. Tidak ada mockup desain, jadi tidak ada layar yang dibandingkan dengan desain.

Jebakan tes tambahan: kolom isian yang dinonaktifkan (`enabled = false`) tidak lagi punya aksi
semantik `SetText`; cari lewat labelnya (`onNodeWithText("E-mail")`), bukan `hasSetTextAction()`.

## Hasil verifikasi (slice katalog)

Dijalankan 2026-11-12 di mesin pengembang (macOS, JDK 21 JBR 21.0.11, `--no-daemon`), berurutan,
setelah perubahan kode terakhir:

| Perintah | Hasil |
|---|---|
| `./gradlew testDebugUnitTest` | BUILD SUCCESSFUL — 321 tes, 44 suite, 0 gagal, 0 dilewati (app 25, core:data 52, core:network 27, core:ui 24, feature:auth 65, feature:account 45, feature:catalog 80, tiga modul fitur lain masing-masing 1) |
| `./gradlew :app:assembleDebug` | BUILD SUCCESSFUL — `app-debug.apk` |
| `./gradlew :app:lintDebug` | BUILD SUCCESSFUL — "No issues found." (semua modul, `checkDependencies`) |
| `./gradlew :app:assembleRelease` | BUILD SUCCESSFUL — R8 + `shrinkResources`, `app-release-unsigned.apk` ±2,0 MB, tanpa aturan keep tambahan |

Tes `:feature:catalog` (80): ViewModel daftar (muat awal, halaman berikutnya, penundaan dan
pembatalan pencarian, filter/urutan mengulang halaman, galat + coba lagi, cache offline, koneksi
kembali, saved state), ViewModel detail (varian, batas jumlah, tambah ke keranjang lewat
`CartRepository`, ketukan ganda, 404, 503/423, offline), repository terhadap MockWebServer + Room
in-memory (parameter kueri, pemetaan DTO termasuk field tak dikenal, aturan cache, pemangkasan ke
200), tes Compose Robolectric untuk semua keadaan daftar dan detail, serta argumen navigasi `slug`.

Tetap BELUM diverifikasi untuk slice ini: tidak ada yang dijalankan di emulator/perangkat (gestur
tarik-untuk-segarkan, guliran nyata dan pemuatan gambar Coil, TalkBack, skala font, papan ketik,
tampilan nyata), tidak ada panggilan ke server Laravel sungguhan (semua tes HTTP memakai
MockWebServer dengan jawaban yang ditulis dari kontrak §6), `AndroidConnectivityObserver` tidak
punya tes unit, dan perilaku APK rilis saat dijalankan. Tidak ada mockup desain, jadi tidak ada
layar yang dibandingkan dengan desain.

Jebakan tambahan:

- **Semantik gabungan bersarang tidak ikut tergabung.** Komponen yang memakai
  `semantics(mergeDescendants = true)` (mis. `StatusBadge`) di dalam kartu yang dapat diklik dibaca
  pembaca layar sebagai node TERPISAH dari kartu. Kartu produk karena itu memakai baris ikon + teks
  biasa untuk ketersediaan, supaya ikut terbaca sebagai bagian kartu.
- **Kunci item `LazyColumn` dari data server harus diberi awalan** (`product:<slug>`): slug bisa
  sama dengan kunci item tetap (kepala/kaki daftar), dan kunci ganda membuat daftar crash.
- **Pemicu halaman berikutnya adalah penyusunan kartu di dekat akhir daftar**, bukan pembacaan
  `layoutInfo.totalItemsCount` di `LaunchedEffect` yang dikunci jumlah item. ASUMSI (penalaran,
  tidak diamati di sini): nilai tata letak di efek seperti itu masih milik frame lama dan dapat
  memuat halaman berantai tanpa digulir. Tes `halaman berikutnya baru diminta saat guliran
  mendekati akhir daftar` menjaga perilaku yang dipilih.
- ViewModel detail membaca argumen rute lewat kuncinya (nama properti rute), bukan
  `SavedStateHandle.toRoute()`. ASUMSI (pengetahuan pustaka, tidak diuji di sini): `toRoute()`
  bergantung pada Bundle Android sehingga tidak dapat dipakai di tes JVM polos. Satu tes Robolectric
  (`CatalogNavigationTest`) menjaga kunci itu tetap sama dengan argumen navigasi.


## Hasil verifikasi (slice cart-checkout)

Dijalankan 2026-11-12 di mesin pengembang (macOS, JDK 21 JBR 21.0.11, `--no-daemon`), berurutan,
setelah perubahan kode terakhir:

| Perintah | Hasil |
|---|---|
| `./gradlew testDebugUnitTest` | BUILD SUCCESSFUL — 429 tes, 53 suite, 0 gagal, 0 dilewati (app 28, core:data 52, core:network 27, core:ui 24, feature:auth 65, feature:account 46, feature:catalog 80, feature:cart 19, feature:checkout 87, feature:orders 1). Pada pemanggilan terakhir Gradle menjalankan ulang `app`, `feature:account`, dan `feature:checkout`; tugas tes modul lain UP-TO-DATE (masukannya tidak berubah; `feature:cart` dijalankan pada pemanggilan sebelumnya di slice ini, setelah perubahan terakhirnya). |
| `./gradlew :app:assembleDebug` | BUILD SUCCESSFUL — `app-debug.apk` |
| `./gradlew :app:lintDebug` | BUILD SUCCESSFUL — "No issues found." (semua modul, `checkDependencies`) |
| `./gradlew :app:assembleRelease` | BUILD SUCCESSFUL — R8 + `shrinkResources`, `app-release-unsigned.apk` ±2,2 MB, tanpa aturan keep tambahan |

Tes `:feature:cart` (19): ViewModel (total BigDecimal, batas jumlah, ketukan beruntun, hapus +
urungkan, baris bermasalah memblokir checkout, ViewModel baru di atas keranjang yang sama),
persistensi Room berkas (tutup lalu buka lagi), dan layar Compose Robolectric (total selalu
terlihat, keadaan kosong, tombol jumlah 48 dp berlabel, snackbar urungkan saat keranjang kosong).

Tes `:feature:checkout` (87): ViewModel checkout (masuk dulu, konfigurasi gagal, alamat, quote
segar + penyegaran cuplikan keranjang, kunci dibuat sekali dan dipakai ulang, kunci baru setelah
keranjang/metode/alamat berubah, ketukan ganda = satu permintaan, `price_changed` dengan dan tanpa
quote, `cart_invalid`, `idempotency_key_reused`, timeout/jaringan/5xx/jawaban tak terbaca = status
belum diketahui dengan kunci yang sama, proses mati saat dikirim, 401 mempertahankan keranjang,
503/423, akun lain, keranjang hanya dikosongkan saat berhasil), repository terhadap MockWebServer
(header `Idempotency-Key`, bentuk body, tidak ada angka uang selain `expected_total`, 200 putar
ulang, pemetaan tiap kode, koneksi putus tidak diulang), aturan domain (sidik niat, aturan
alamat/kontak), ViewModel daftar dan formulir alamat (CRUD, alamat utama, galat validasi, 404/409),
layar Compose Robolectric (tiga langkah, tombol nonaktif saat dikirim, pencegahan ketukan ganda,
dialog `price_changed`, status belum diketahui, `cart_invalid`, daftar dan formulir alamat), dan
argumen navigasi `addressId`.

Tetap BELUM diverifikasi untuk slice ini: tidak ada yang dijalankan di emulator/perangkat (papan
ketik dan IME di formulir alamat, tombol kembali sistem lewat `BackHandler` pada perangkat nyata,
TalkBack, skala font, tampilan nyata), tidak ada panggilan ke server Laravel sungguhan (semua tes
HTTP memakai MockWebServer dengan jawaban yang ditulis dari kontrak §7–§8), layar ber-Hilt
(`*ScreenRoute`) tidak diuji langsung (yang diuji layar stateless + ViewModel), dan perilaku APK
rilis saat dijalankan. Tidak ada mockup desain, jadi tidak ada layar yang dibandingkan dengan desain.

Catatan ruang disk: selama slice ini ruang kosong naik-turun antara 2,2 dan 5,6 GiB karena berkas
swap macOS (17–19 GiB) membesar saat Gradle berjalan dan menyusut lagi sesudahnya. Setiap
pemanggilan Gradle dimulai dengan ruang kosong di atas 3 GiB; setelah pemanggilan tes penuh ruang
kosong sempat 2,2 GiB dan pekerjaan menunggu sampai kembali di atas 3 GiB sebelum melanjutkan.

Jebakan tambahan:

- **Baris ringkasan yang digabung (`mergeDescendants`)** diberi `testTag` pada barisnya, bukan pada
  teks di dalamnya. ASUMSI (penalaran dari cara semantik gabungan bekerja, tidak diamati sebagai
  kegagalan di sini): teks anak tidak menjadi node tersendiri di pohon semantik gabungan.
- **`MockResponse.Builder().onResponseStart(SocketEffect.CloseSocket())`** (mockwebserver3 5.5.1)
  adalah cara meniru koneksi yang putus setelah permintaan terkirim.

## Hasil verifikasi (slice hardening)

Dijalankan 2026-11-12 di mesin pengembang (macOS, JDK 21 JBR 21.0.11, `--no-daemon`), berurutan,
setelah perubahan kode terakhir (sesudahnya hanya README yang diubah):

| Perintah | Hasil |
|---|---|
| `./gradlew testDebugUnitTest --continue --max-workers=1` | BUILD SUCCESSFUL — 596 tes, 69 suite, 0 gagal, 0 galat, 0 dilewati (rincian per modul di bawah). Pemanggilan terakhir menjalankan ulang `app` dan `feature:checkout`; modul lain UP-TO-DATE karena masukannya tidak berubah sejak pemanggilan sebelumnya di slice ini (yang menjalankan `core:ui` dan keenam modul fitur setelah perubahan terakhirnya) atau sejak slice sebelumnya (`core:data`, `core:network`, yang tidak diubah di slice ini). |
| `./gradlew :app:lintDebug --max-workers=2` | BUILD SUCCESSFUL — "No issues found." (0 galat, 0 peringatan; semua modul lewat `checkDependencies`) |
| `./gradlew :app:assembleDebug :app:assembleRelease --max-workers=2` | BUILD SUCCESSFUL — `app/build/outputs/apk/debug/app-debug.apk` (16 233 429 byte) dan `app/build/outputs/apk/release/app-release-unsigned.apk` (2 322 261 byte, ±2,2 MiB, R8 + `shrinkResources`, **tidak ditandatangani**, tanpa aturan keep tambahan). Dibangun tanpa `-Pboothpos.apiBaseUrl`, jadi berisi alamat cadangan `https://boothpos.invalid/api/v1/`. |

Tes per modul (dari XML hasil tes; "UI Compose" = kelas yang memakai `createComposeRule`, semuanya
Robolectric di JVM; "JVM lain" = ViewModel, repository/MockWebServer, Room/DataStore, domain):

| Modul | Tes | UI Compose | JVM lain |
|---|---|---|---|
| `app` | 38 | 20 | 18 |
| `core:ui` | 26 | 14 | 12 |
| `core:network` | 27 | 0 | 27 |
| `core:data` | 52 | 0 | 52 |
| `feature:auth` | 68 | 27 | 41 |
| `feature:catalog` | 82 | 33 | 49 |
| `feature:cart` | 21 | 11 | 10 |
| `feature:checkout` | 97 | 28 | 69 |
| `feature:orders` | 136 | 50 | 86 |
| `feature:account` | 49 | 21 | 28 |
| **Jumlah** | **596** | **204** | **392** |

Tes baru di slice ini: `SecurityConfigurationTest` (7), satu `*LargeFontLayoutTest` per modul
fitur (auth 3, catalog 2, cart 2, checkout 4, orders 3, account 3) plus satu tes huruf besar + tema
gelap di `AppScaffoldTest`, dua tes `MessageState` di `SharedComponentsTest`, tiga tes niat tertunda
di `CheckoutViewModelTest`, dan `DataStorePendingOrderIntentStoreTest` (3).

Diperiksa pada keluaran rilis: manifest gabungan hanya mengekspor `MainActivity`
(`InitializationProvider` dan `MultiInstanceInvalidationService` `exported=false`); izin `INTERNET`,
`ACCESS_NETWORK_STATE`, dan izin receiver dinamis bertanda tangan milik androidx; `classes.dex`
tidak memuat `HttpLoggingInterceptor`, `10.0.2.2`, maupun kelas WebView.

Tidak ada mockup desain untuk aplikasi ini, jadi tidak ada layar yang dibandingkan dengan desain.
Pemeriksaan manual yang TIDAK dilakukan tercantum di "Yang BELUM diverifikasi".

Catatan ruang disk: setiap pemanggilan Gradle dimulai dengan ruang kosong di atas batas slice ini
(2 GiB): 3,0 / 2,7 / 3,0 / 4,4 / 5,4 GiB. Selama dua pemanggilan tes pertama (yang pertama gagal
kompilasi karena galat di tes baru, yang kedua menemukan satu label tes yang salah) ruang kosong
sempat turun ke ±720 MB, karena swap macOS membesar bersamaan dengan rangkaian tes PHP tim backend;
penjaga yang siap menghentikan Gradle di bawah 700 MB tidak terpicu. Pemanggilan berikutnya baru
dimulai setelah ruang kosong kembali di atas 2,3 GiB. Tes dijalankan dengan `--max-workers=1` (lebih
hemat memori daripada `2`) untuk menekan pertumbuhan swap.

## Hasil verifikasi (slice perbaikan tinjauan)

Dijalankan 2026-11-12 (macOS, JDK 21, `--no-daemon --max-workers=2`). Perbaikan: niat pesanan tertunda
tidak lagi dilupakan oleh penolakan middleware (M1), kegagalan menyimpan token tidak lagi crash + pemulihan
kunci Keystore (M2), kunci terjemahan `validation.*` dibuang (L1), `toString` data pribadi (L2), kegagalan
menulis cache konfigurasi toko tidak fatal (L3), `callTimeout` 60 detik (L4), tes izin manifest yang benar (L5).

| Perintah | Hasil |
|---|---|
| `./gradlew testDebugUnitTest --continue` | BUILD SUCCESSFUL — 699 tes, 0 gagal, 0 dilewati (app 40, core:data 80, core:network 30, core:ui 28, feature:auth 82, account 54, cart 21, catalog 90, checkout 128, orders 146); sebelumnya 647 |
| `./gradlew :app:lintDebug` | BUILD SUCCESSFUL — 0 isu (`lint-results-debug.xml` tanpa `<issue>`) |
| `./gradlew :app:assembleRelease` | BUILD SUCCESSFUL — R8 + `shrinkResources`, `app-release-unsigned.apk` ±2,3 MB |

Pemeriksaan mutasi (kode diubah sementara lalu dikembalikan): dengan perilaku lama
(`clearPending()` untuk setiap penolakan) 13 tes baru di `CheckoutViewModelTest` gagal; dengan
ekspektasi izin dipersempit menjadi hanya INTERNET, `MergedManifestPermissionsTest` gagal. Manifest rilis
gabungan diperiksa langsung: `INTERNET`, `ACCESS_NETWORK_STATE`, dan izin bertanda tangan milik androidx
(`<paket>.DYNAMIC_RECEIVER_NOT_EXPORTED_PERMISSION`).

Tidak diverifikasi: perilaku AndroidKeyStore sungguhan (lihat "Yang BELUM diverifikasi"), APK rilis
saat dijalankan, dan jawaban nyata backend untuk 413/423 `installation_locked` (belum ada rekaman;
bentuknya diambil dari uraian tugas, diuji dengan MockWebServer).

## Hasil verifikasi (desain "Sky Capsule")

Dijalankan 2026-11-14 (macOS, JDK 21, `--no-daemon --max-workers=2`). Perubahan tampilan saja; lihat
bagian "Desain Sky Capsule" untuk token, keputusan, dan yang sengaja berbeda dari mockup.

| Perintah | Hasil |
|---|---|
| `./gradlew testDebugUnitTest :app:lintDebug :app:assembleDebug` | BUILD SUCCESSFUL — 699 tes, 0 gagal, 0 dilewati (jumlah per modul sama dengan slice sebelumnya: tidak ada tes yang dihapus atau dilewati); lint 0 galat, 2 peringatan `NewerVersionAvailable` (kotlinx-serialization-json 1.13.0, Robolectric 4.18; bukan dari perubahan ini); `app-debug.apk` ±16,1 MB berisi `res/font/fredoka_*.ttf` dan `assets/licenses/Fredoka-OFL.txt` |
| `./gradlew :app:assembleRelease` | BUILD SUCCESSFUL — R8 + `shrinkResources`, `app-release-unsigned.apk` ±2,4 MB (sebelumnya ±2,3 MB); kedua berkas Fredoka dan `assets/licenses/Fredoka-OFL.txt` ada di dalamnya |

Tes yang diubah: hanya `ThemeContrastTest`, dan hanya DITAMBAH (pasangan token "Sky Capsule" yang
membawa teks: hero, ubin, tombol sekunder, tautan di atas hero); tidak ada pernyataan lama yang
dilonggarkan. Tes layar lain lulus tanpa diubah, termasuk `*LargeFontLayoutTest` (huruf 200 % pada
320 dp).

Perbandingan dengan mockup: 30 tangkapan layar dirender lewat tes Robolectric sementara (tidak
di-commit) pada 375 dp dan dilihat berdampingan dengan tiga gambar mockup: bingkai aplikasi
(Belanja, Keranjang, detail, bilah atas berwarna hero), Belanja (terang, gelap, kosong), detail
produk, Keranjang (isi, bermasalah, kosong), Checkout langkah 1-3 dan "Pesanan dibuat", daftar dan
detail Pesanan (dikirim, dikemas, dibatalkan, belum masuk), Selamat datang, Masuk, Daftar, Akun
(profil, gelap, belum masuk). Yang TIDAK dirender: daftar dan formulir alamat, Ubah profil, dialog
(harga berubah, keluar, hapus alamat, serah terima pelacakan), snackbar, keadaan memuat/galat/offline
tiap layar (dijaga tes semantik yang sudah ada, bukan dilihat), dan huruf 200 % (dijaga tes tata
letak, bukan dilihat).

Pemeriksaan manual yang TIDAK dilakukan tercantum di "Yang BELUM diverifikasi".
