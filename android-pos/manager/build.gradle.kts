import java.time.LocalDate
import java.util.Properties

// 094 — aplikasi Android MANAJEMEN (terpisah dari aplikasi kasir :app). Berbagi :core-network dan :core-domain supaya klien
// HTTP (log tersamarkan, header token/outlet) dan pemetaan galat tidak disalin (research D1).
plugins {
    alias(libs.plugins.androidApplication)
    alias(libs.plugins.kotlinAndroid)
    alias(libs.plugins.composeCompiler)
    alias(libs.plugins.kotlinSerialization)
}

val localProps = Properties().apply {
    rootProject.file("local.properties").takeIf { it.exists() }?.inputStream()?.use { load(it) }
}
fun managerProp(key: String, default: String): String =
    ((project.findProperty(key) as String?) ?: localProps.getProperty(key) ?: default).trim()

// Penanda pembangunan (pola 085): sha git singkat + tanggal, tampil di layar Pengaturan server.
val buildStamp: String = runCatching {
    val sha = ProcessBuilder("git", "rev-parse", "--short", "HEAD").directory(rootProject.projectDir).redirectErrorStream(true).start()
        .inputStream.bufferedReader().readText().trim()
    "$sha ${LocalDate.now()}".takeIf { sha.matches(Regex("[0-9a-f]{4,40}")) } ?: "dev"
}.getOrDefault("dev")

android {
    namespace = "com.warkop.manager"
    compileSdk = 34

    defaultConfig {
        applicationId = "com.warkop.manager"
        minSdk = 26
        targetSdk = 34
        versionCode = 1
        versionName = "1.0.0"
        // resValue, bukan BuildConfig: BuildConfig memaksa javac yang gagal di JDK 24 mesin pembangun (catatan :app).
        resValue("string", "app_build", buildStamp)
        // Hanya isian awal formulir profil server; pengguna mengubahnya di Pengaturan. Kunci sama dengan :app.
        resValue("string", "default_local_url", managerProp("pos.defaultLocalUrl", "http://10.0.2.2:8010"))
        resValue("string", "default_online_url", managerProp("pos.defaultOnlineUrl", ""))
        testInstrumentationRunner = "androidx.test.runner.AndroidJUnitRunner"
    }

    buildTypes {
        release {
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
    implementation(libs.androidx.lifecycle.runtime.compose)
    implementation(libs.androidx.lifecycle.viewmodel.compose)
    implementation(libs.androidx.activity.compose)
    implementation(libs.navigation.compose)

    implementation(platform(libs.compose.bom))
    implementation(libs.compose.ui)
    implementation(libs.compose.ui.graphics)
    implementation(libs.compose.ui.tooling.preview)
    implementation(libs.compose.material3)
    implementation(libs.compose.material3.window.size)
    implementation(libs.compose.material3.adaptive.navigation.suite)
    implementation(libs.material3.adaptive)
    implementation(libs.material3.adaptive.layout)
    implementation(libs.material3.adaptive.navigation)
    // Pull-to-refresh: material3 1.3.0 belum punya PullToRefreshBox (catatan 055 di :app); versi dari BOM.
    implementation(libs.compose.material)
    implementation(libs.compose.material.icons)

    implementation(libs.kotlinx.serialization.json)
    implementation(libs.kotlinx.coroutines.android)

    testImplementation(libs.junit)
    testImplementation(libs.kotlinx.coroutines.test)

    debugImplementation(libs.compose.ui.tooling)
}
