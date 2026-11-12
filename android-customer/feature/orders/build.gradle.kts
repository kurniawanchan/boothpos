plugins {
    alias(libs.plugins.android.library)
    alias(libs.plugins.kotlin.compose)
    alias(libs.plugins.kotlin.serialization)
    alias(libs.plugins.ksp)
    alias(libs.plugins.hilt)
}

android {
    namespace = "com.boothpos.customer.feature.orders"
}

dependencies {
    // Modul fitur hanya bergantung pada :core:*; tidak pernah pada modul fitur lain.
    implementation(project(":core:ui"))
    implementation(project(":core:network"))
    implementation(project(":core:data"))

    implementation(platform(libs.compose.bom))
    implementation(libs.bundles.compose)
    implementation(libs.androidx.lifecycle.viewmodel.compose)
    implementation(libs.androidx.lifecycle.runtime.compose)
    implementation(libs.androidx.navigation.compose)
    // String.toUri() untuk Intent "Lacak paket" (sudah ada di APK lewat Compose; dideklarasikan eksplisit).
    implementation(libs.androidx.core.ktx)
    implementation(libs.androidx.hilt.lifecycle.viewmodel.compose)
    implementation(libs.hilt.android)
    ksp(libs.hilt.compiler)

    debugImplementation(libs.compose.ui.tooling)
    debugImplementation(libs.compose.ui.test.manifest)

    testImplementation(testFixtures(project(":core:data")))
    testImplementation(libs.bundles.unit.test)
    testImplementation(libs.bundles.compose.test)
    testImplementation(libs.okhttp.mockwebserver)
}
