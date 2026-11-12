import com.android.build.api.dsl.ApplicationExtension
import com.android.build.api.dsl.LibraryExtension

// Versi plugin hanya dideklarasikan di gradle/libs.versions.toml. AGP 9 memakai Kotlin bawaan:
// JANGAN menerapkan org.jetbrains.kotlin.android dan jangan menulis kotlinOptions {}.
plugins {
    alias(libs.plugins.android.application) apply false
    alias(libs.plugins.android.library) apply false
    alias(libs.plugins.kotlin.compose) apply false
    alias(libs.plugins.kotlin.serialization) apply false
    alias(libs.plugins.ksp) apply false
    alias(libs.plugins.hilt) apply false
}

val compileSdkVersion = libs.versions.compileSdk.get().toInt()
val minSdkVersion = libs.versions.minSdk.get().toInt()
// Satu robolectric.properties untuk semua modul, supaya hanya satu level SDK Robolectric yang terunduh.
val sharedTestResources = rootProject.file("gradle/test-resources")
// Robolectric 4.17 pada SDK 37 memanggil jdk.internal.access.SharedSecrets saat menyiapkan
// aplikasi tes; di JDK 17+ paket itu tertutup, dan tanpa argumen ini SETIAP tes Robolectric gagal
// ("Failed to interact with raw FileDescriptor internals"). Hanya yang dibuktikan perlu oleh build.
val robolectricJvmArgs = listOf("--add-exports=java.base/jdk.internal.access=ALL-UNNAMED")

// Konfigurasi Android bersama, sengaja di sini dan bukan di included build `build-logic`
// (included build menambah unduhan kotlin-dsl; lihat README bagian toolchain).
subprojects {
    plugins.withId("com.android.library") {
        extensions.configure<LibraryExtension> {
            compileSdk = compileSdkVersion
            defaultConfig {
                minSdk = minSdkVersion
            }
            compileOptions {
                // Bytecode 17 lewat compileOptions saja: tidak ada JDK 17 terpasang, jadi tanpa jvmToolchain.
                sourceCompatibility = JavaVersion.VERSION_17
                targetCompatibility = JavaVersion.VERSION_17
            }
            testOptions {
                unitTests {
                    // Wajib untuk Robolectric: tes Compose dan Room membaca resource Android.
                    isIncludeAndroidResources = true
                    all {
                        it.maxHeapSize = "1g"
                        it.jvmArgs(robolectricJvmArgs)
                    }
                }
            }
            sourceSets.getByName("test").resources.srcDir(sharedTestResources)
        }
    }
    plugins.withId("com.android.application") {
        extensions.configure<ApplicationExtension> {
            compileSdk = compileSdkVersion
            defaultConfig {
                minSdk = minSdkVersion
            }
            compileOptions {
                sourceCompatibility = JavaVersion.VERSION_17
                targetCompatibility = JavaVersion.VERSION_17
            }
            testOptions {
                unitTests {
                    isIncludeAndroidResources = true
                    all {
                        it.maxHeapSize = "1g"
                        it.jvmArgs(robolectricJvmArgs)
                    }
                }
            }
            sourceSets.getByName("test").resources.srcDir(sharedTestResources)
        }
    }
}
