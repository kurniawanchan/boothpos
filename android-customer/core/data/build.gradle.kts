plugins {
    alias(libs.plugins.android.library)
    alias(libs.plugins.kotlin.serialization)
    alias(libs.plugins.ksp)
    alias(libs.plugins.hilt)
}

android {
    namespace = "com.boothpos.customer.core.data"

    defaultConfig {
        testInstrumentationRunner = "androidx.test.runner.AndroidJUnitRunner"
    }

    // Fake bersama (sesi, keranjang, alamat, konfigurasi toko, dispatcher) untuk tes modul lain:
    // `testImplementation(testFixtures(project(":core:data")))`. Tidak pernah masuk APK.
    testFixtures {
        enable = true
    }
}

ksp {
    // Skema Room diekspor dan di-commit supaya setiap perubahan skema terlihat di review.
    arg("room.schemaLocation", "$projectDir/schemas")
    arg("room.generateKotlin", "true")
}

dependencies {
    // `api`: kontrak domain mengembalikan ApiResult/ApiError milik :core:network.
    api(project(":core:network"))

    implementation(libs.androidx.room.runtime)
    implementation(libs.androidx.room.ktx)
    ksp(libs.androidx.room.compiler)
    implementation(libs.androidx.datastore.preferences)
    implementation(libs.hilt.android)
    ksp(libs.hilt.compiler)

    testFixturesImplementation(libs.kotlinx.coroutines.test)
    testFixturesImplementation(libs.junit)

    testImplementation(libs.bundles.unit.test)
    testImplementation(libs.okhttp.mockwebserver)
    // Room in-memory dan DataStore membutuhkan Context: dijalankan di JVM lewat Robolectric.
    testImplementation(libs.robolectric)
    testImplementation(libs.androidx.test.core)
    testImplementation(libs.androidx.test.ext.junit)

    // Satu-satunya tes instrumentasi proyek: AndroidKeyStore tidak tersedia di JVM/Robolectric.
    androidTestImplementation(libs.androidx.test.ext.junit)
    androidTestImplementation(libs.androidx.test.runner)
    androidTestImplementation(libs.kotlinx.coroutines.test)
}
