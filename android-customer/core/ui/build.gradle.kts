plugins {
    alias(libs.plugins.android.library)
    alias(libs.plugins.kotlin.compose)
    alias(libs.plugins.ksp)
    alias(libs.plugins.hilt)
}

android {
    namespace = "com.boothpos.customer.core.ui"
}

dependencies {
    // `api`: modul fitur memakai Compose/Material 3 lewat komponen bersama modul ini.
    api(platform(libs.compose.bom))
    api(libs.bundles.compose)
    // Hanya untuk memetakan ApiError ke tampilan galat bersama (pesan + StoreUnavailable/Offline).
    implementation(project(":core:network"))

    implementation(libs.coil.compose)
    implementation(libs.coil.network.okhttp)
    implementation(libs.okhttp)
    implementation(libs.hilt.android)
    ksp(libs.hilt.compiler)

    debugImplementation(libs.compose.ui.tooling)
    // Menyediakan ComponentActivity host untuk createComposeRule() di tes Robolectric.
    debugImplementation(libs.compose.ui.test.manifest)

    testImplementation(libs.bundles.unit.test)
    testImplementation(libs.bundles.compose.test)
}
