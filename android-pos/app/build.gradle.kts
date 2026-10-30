import java.time.LocalDate
import java.util.Properties

plugins {
    alias(libs.plugins.androidApplication)
    alias(libs.plugins.kotlinAndroid)
    alias(libs.plugins.composeCompiler)
    alias(libs.plugins.kotlinSerialization)
}

// 065 / US6 (research D8) — alamat bawaan yang ditanam di aplikasi, hanya untuk BERTANYA ke server alamat mana yang
// dipilih admin. Diisi per pembangunan lewat gradle.properties atau local.properties (tidak di-commit):
//   pos.defaultLocalUrl=http://192.168.1.219:8010
//   pos.defaultOnlineUrl=https://pos.example.com
// Tanpa isian, alamat lokal memakai alamat emulator dan alamat online memakai bawaan di bawah (066). Bawaan online
// dipakai juga sebagai jatuhan bila admin memilih Online tetapi kolom alamat online di web kosong; nilainya HARUS sama
// dengan `server_address.default_online` di config/pos.php (petunjuk di halaman web membacanya dari sana).
val localProps = Properties().apply {
    rootProject.file("local.properties").takeIf { it.exists() }?.inputStream()?.use { load(it) }
}
fun posProp(key: String, default: String): String =
    ((project.findProperty(key) as String?) ?: localProps.getProperty(key) ?: default).trim()

// 085 / US1 (research D5) — penanda pembangunan (sha git singkat + tanggal) yang tampil di layar Koneksi. versionCode
// tetap 1 pada setiap pembangunan, jadi tanpa ini pemilik tidak bisa membedakan APK lama (sebelum 083, dengan cadangan
// pajak 10%) dari yang baru. Gagal membaca git (mis. tanpa folder .git) jatuh ke "dev", tidak menggagalkan pembangunan.
val buildStamp: String = runCatching {
    val sha = ProcessBuilder("git", "rev-parse", "--short", "HEAD").directory(rootProject.projectDir).redirectErrorStream(true).start()
        .inputStream.bufferedReader().readText().trim()
    "$sha ${LocalDate.now()}".takeIf { sha.matches(Regex("[0-9a-f]{4,40}")) } ?: "dev"
}.getOrDefault("dev")

android {
    namespace = "com.warkop.pos"
    compileSdk = 34

    defaultConfig {
        applicationId = "com.warkop.pos"
        minSdk = 26
        targetSdk = 34
        versionCode = 1
        versionName = "1.0.0"
        // Sengaja resValue, bukan BuildConfig: BuildConfig memaksa kompilasi Java, yang gagal pada JDK 24 di mesin
        // pembangun ini (transformasi JDK image AGP). String resource tidak butuh javac.
        resValue("string", "app_build", buildStamp)
        resValue("string", "default_local_url", posProp("pos.defaultLocalUrl", "http://10.0.2.2:8010"))
        resValue("string", "default_online_url", posProp("pos.defaultOnlineUrl", "https://smasher-nullify-subgroup.ngrok-free.dev"))
    }

    // Penandatanganan rilis: kredensial keystore dibaca dari local.properties (tidak di-commit), tidak pernah ditulis di
    // sini. Tanpa `pos.release.storeFile`, build debug tetap jalan dan `assembleRelease` menghasilkan APK tak bertanda
    // tangan (tidak bisa dipasang), jadi kesalahan konfigurasi terlihat jelas, bukan diam-diam memakai kunci debug.
    val releaseStoreFile = posProp("pos.release.storeFile", "")
    if (releaseStoreFile.isNotEmpty()) {
        signingConfigs {
            create("release") {
                storeFile = file(releaseStoreFile)
                storePassword = posProp("pos.release.storePassword", "")
                keyAlias = posProp("pos.release.keyAlias", "")
                keyPassword = posProp("pos.release.keyPassword", "")
            }
        }
    }

    buildTypes {
        release {
            signingConfigs.findByName("release")?.let { signingConfig = it }
            // Minify dimatikan sampai aturan proguard untuk kotlinx.serialization/Compose ditulis dan diuji di perangkat.
            isMinifyEnabled = false
        }
    }

    buildFeatures {
        compose = true
    }

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }

    kotlinOptions {
        jvmTarget = "17"
    }
}

dependencies {
    implementation(project(":core-domain"))
    implementation(project(":core-network"))

    implementation(libs.androidx.core.ktx)
    implementation(libs.androidx.lifecycle.runtime.ktx)
    implementation(libs.androidx.lifecycle.viewmodel.compose)
    implementation(libs.androidx.activity.compose)

    implementation(platform(libs.compose.bom))
    implementation(libs.compose.ui)
    implementation(libs.compose.ui.graphics)
    implementation(libs.compose.ui.tooling.preview)
    implementation(libs.compose.material3)
    // 055 / T025 — pull-to-refresh (material3 1.3.0 belum punya PullToRefreshBox); versi dari BOM.
    implementation(libs.compose.material)
    implementation(libs.compose.material.icons)

    implementation(libs.kotlinx.serialization.json)
    implementation(libs.timber)
    implementation(libs.kotlinx.coroutines.android)

    testImplementation(libs.junit)
    testImplementation(libs.kotlinx.coroutines.test)

    debugImplementation(libs.compose.ui.tooling)
}
