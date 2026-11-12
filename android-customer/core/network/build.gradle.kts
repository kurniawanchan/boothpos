plugins {
    alias(libs.plugins.android.library)
    alias(libs.plugins.kotlin.serialization)
    alias(libs.plugins.ksp)
    alias(libs.plugins.hilt)
}

android {
    namespace = "com.boothpos.customer.core.network"
}

dependencies {
    // `api`: tipe Retrofit/OkHttp/Json muncul di tanda tangan publik modul ini (factory, DTO, ApiError).
    api(libs.retrofit)
    api(libs.okhttp)
    api(libs.kotlinx.serialization.json)
    api(libs.kotlinx.coroutines.android)
    implementation(libs.retrofit.kotlinx.serialization)
    implementation(libs.hilt.android)
    ksp(libs.hilt.compiler)

    // Logging HTTP hanya ada di varian debug; APK rilis tidak membawa pustakanya sama sekali.
    debugImplementation(libs.okhttp.logging)

    testImplementation(libs.bundles.unit.test)
    testImplementation(libs.okhttp.mockwebserver)
}
