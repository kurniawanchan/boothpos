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
// Tanpa isian, alamat lokal memakai alamat emulator dan alamat online kosong (tidak dicoba).
val localProps = Properties().apply {
    rootProject.file("local.properties").takeIf { it.exists() }?.inputStream()?.use { load(it) }
}
fun posProp(key: String, default: String): String =
    ((project.findProperty(key) as String?) ?: localProps.getProperty(key) ?: default).trim()

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
        resValue("string", "default_local_url", posProp("pos.defaultLocalUrl", "http://10.0.2.2:8010"))
        resValue("string", "default_online_url", posProp("pos.defaultOnlineUrl", ""))
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
