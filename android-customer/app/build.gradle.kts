plugins {
    alias(libs.plugins.android.application)
    alias(libs.plugins.kotlin.compose)
    alias(libs.plugins.kotlin.serialization)
    alias(libs.plugins.ksp)
    alias(libs.plugins.hilt)
}

// Alamat API tidak pernah ditulis per lingkungan di kode: datang dari properti Gradle
// `boothpos.apiBaseUrl` (-P…, ~/.gradle/gradle.properties, atau ORG_GRADLE_PROJECT_…).
val configuredApiBaseUrl: String? = providers.gradleProperty("boothpos.apiBaseUrl").orNull
// Debug: emulator -> `php artisan serve` di host. Rilis: domain cadangan `.invalid` (RFC 2606) yang
// tidak pernah ter-resolve, supaya rilis tanpa konfigurasi gagal dengan jelas, bukan menuju server asing.
val debugApiBaseUrl = configuredApiBaseUrl ?: "http://10.0.2.2:8010/api/v1/"
val releaseApiBaseUrl = configuredApiBaseUrl ?: "https://boothpos.invalid/api/v1/"

fun quoted(value: String) = "\"${value.replace("\\", "\\\\").replace("\"", "\\\"")}\""

android {
    namespace = "com.boothpos.customer"

    defaultConfig {
        applicationId = "com.boothpos.customer"
        targetSdk = libs.versions.targetSdk.get().toInt()
        versionCode = 1
        versionName = "0.1.0"
    }

    buildTypes {
        debug {
            buildConfigField("String", "API_BASE_URL", quoted(debugApiBaseUrl))
        }
        release {
            // R8 + penyusutan resource. Aturan keep hanya ditambahkan bila build membuktikan perlu.
            isMinifyEnabled = true
            isShrinkResources = true
            proguardFiles(getDefaultProguardFile("proguard-android-optimize.txt"), "proguard-rules.pro")
            buildConfigField("String", "API_BASE_URL", quoted(releaseApiBaseUrl))
        }
    }

    buildFeatures {
        compose = true
        buildConfig = true
    }

    lint {
        // Satu laporan untuk semua modul, dan galat lint menggagalkan build.
        abortOnError = true
        checkDependencies = true
        // Versi Gradle/AGP sengaja dipaku pada kombinasi yang terbukti di mesin ini (lihat README);
        // peringatan "versi lebih baru tersedia" hanya menutupi peringatan yang sungguhan.
        disable += "AndroidGradlePluginVersion"
    }
}

// Gagal cepat: build rilis tidak boleh dibuat dengan alamat API non-HTTPS (transport).
val checkReleaseApiBaseUrl by tasks.registering {
    val url = releaseApiBaseUrl
    doLast {
        if (!url.startsWith("https://") || !url.endsWith("/")) {
            throw GradleException(
                "boothpos.apiBaseUrl untuk build rilis harus HTTPS dan diakhiri '/'. " +
                    "Contoh: -Pboothpos.apiBaseUrl=https://toko.example/api/v1/",
            )
        }
    }
}
tasks.matching { it.name == "preReleaseBuild" }.configureEach { dependsOn(checkReleaseApiBaseUrl) }

dependencies {
    implementation(project(":core:ui"))
    implementation(project(":core:network"))
    implementation(project(":core:data"))
    implementation(project(":feature:auth"))
    implementation(project(":feature:catalog"))
    implementation(project(":feature:cart"))
    implementation(project(":feature:checkout"))
    implementation(project(":feature:orders"))
    implementation(project(":feature:account"))

    implementation(platform(libs.compose.bom))
    implementation(libs.bundles.compose)
    implementation(libs.androidx.core.ktx)
    implementation(libs.androidx.activity.compose)
    implementation(libs.androidx.lifecycle.viewmodel.compose)
    implementation(libs.androidx.lifecycle.runtime.compose)
    implementation(libs.androidx.navigation.compose)
    implementation(libs.androidx.hilt.lifecycle.viewmodel.compose)
    implementation(libs.coil.compose)
    implementation(libs.hilt.android)
    ksp(libs.hilt.compiler)

    debugImplementation(libs.compose.ui.tooling)
    debugImplementation(libs.compose.ui.test.manifest)

    testImplementation(testFixtures(project(":core:data")))
    testImplementation(libs.bundles.unit.test)
    testImplementation(libs.bundles.compose.test)
}
