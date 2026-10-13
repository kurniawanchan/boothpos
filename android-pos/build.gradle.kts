plugins {
    id("com.android.application") version "8.2.0" apply false
    id("org.jetbrains.kotlin.android") version "2.0.20" apply false
    id("kotlin-kapt") version "2.0.20" apply false
    id("dagger.hilt.android.plugin") version "2.49" apply false
}

allprojects {
    repositories {
        google()
        mavenCentral()
    }
}
