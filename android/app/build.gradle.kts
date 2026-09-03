plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.android")
}

android {
    namespace = "com.boothpos.installer"
    // T001 — minSdk 26 (Android 8.0) per research.md R7: old enough to
    // cover tablets small shops already own, recent enough that scoped
    // storage / foreground service / WebView behavior is mature and
    // consistent, avoiding per-API-level special-casing.
    compileSdk = 34

    defaultConfig {
        applicationId = "com.boothpos.installer"
        minSdk = 26
        targetSdk = 34
        versionCode = 1
        versionName = "1.0.0"

        ndk {
            // research.md R7 — arm64-v8a primary/required target; the
            // bundled PHP/MariaDB/mysqldump binaries (T002-T004) are
            // sourced for this ABI only for the initial build.
            abiFilters += listOf("arm64-v8a")
        }
    }

    buildTypes {
        release {
            isMinifyEnabled = false
            proguardFiles(getDefaultProguardFile("proguard-android-optimize.txt"), "proguard-rules.pro")
        }
    }

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }

    kotlinOptions {
        jvmTarget = "17"
    }

    // T005 — the packaged, unmodified Laravel app (vendor/, app/, built
    // resources/js/ output, migrations, config, routes) and the runtime
    // binaries (T002-T004) live under src/main/assets/, populated by the
    // copyLaravelApp / fetchRuntimeBinaries Gradle tasks below, not
    // committed to this repo directly (see .gitignore).
    sourceSets {
        getByName("main") {
            assets.srcDirs("src/main/assets")
        }
    }

    packaging {
        // The bundled PHP/MariaDB/mysqldump binaries are placed under
        // assets/runtime/ deliberately (not jniLibs/) so they can be
        // executed as arbitrary CLI processes by RuntimeForegroundService,
        // not loaded as JNI shared libraries — copied to the app's private
        // files directory (with the executable bit set) on first run,
        // since assets/ itself is read-only and not directly executable.
        jniLibs {
            useLegacyPackaging = false
        }
    }
}

dependencies {
    implementation("androidx.core:core-ktx:1.13.1")
    implementation("androidx.appcompat:appcompat:1.7.0")
    implementation("androidx.webkit:webkit:1.11.0")
    implementation("androidx.lifecycle:lifecycle-service:2.8.4")
    implementation("androidx.activity:activity-ktx:1.9.1")
}

// -----------------------------------------------------------------------
// T005 — packages the EXISTING, UNMODIFIED Laravel app (built resources/js
// output, vendor/, app/, database/migrations, routes/, config/) into this
// module's assets/laravel/ directory. Deliberately a thin wrapper around
// commands that already exist in this repo (npm run build, composer
// install --no-dev) rather than reinventing the build — see
// android/README.md for the manual equivalent if this task is run outside
// a full Gradle+Composer+Node environment.
// -----------------------------------------------------------------------
tasks.register<Exec>("buildLaravelAssets") {
    workingDir = rootProject.projectDir.parentFile // repo root
    commandLine("npm", "run", "build")
}

tasks.register<Exec>("installComposerDeps") {
    workingDir = rootProject.projectDir.parentFile
    commandLine("composer", "install", "--no-dev", "--optimize-autoloader")
}

tasks.register<Copy>("copyLaravelApp") {
    dependsOn("buildLaravelAssets", "installComposerDeps")
    val repoRoot = rootProject.projectDir.parentFile
    val dest = file("src/main/assets/laravel")

    from(repoRoot) {
        include(
            "app/**", "bootstrap/**", "config/**", "database/**", "lang/**",
            "public/**", "resources/views/**", "routes/**", "vendor/**",
            "artisan", "composer.json", "composer.lock",
        )
        // storage/ is intentionally NOT copied wholesale — its runtime
        // contents (logs, cache, the app's private uploads dir) are
        // created fresh per-installation on-device, not shipped in the
        // APK; only its directory skeleton is needed, provisioned by
        // FirstRunSetup instead.
        exclude("vendor/**/tests/**", "vendor/**/.git/**")
    }
    into(dest)
}

tasks.named("preBuild") {
    dependsOn("copyLaravelApp")
}
