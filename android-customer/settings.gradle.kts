// Aplikasi Android storefront pelanggan BoothPOS. Proyek Gradle TERPISAH dari android/ (pemasang
// Laravel lokal): tujuan, siklus rilis, dan batas keamanannya berbeda, jadi tidak berbagi build.
pluginManagement {
    repositories {
        google()
        mavenCentral()
        gradlePluginPortal()
    }
}

dependencyResolutionManagement {
    repositoriesMode.set(RepositoriesMode.FAIL_ON_PROJECT_REPOS)
    repositories {
        google()
        mavenCentral()
    }
}

rootProject.name = "BoothPOS-Customer"

include(
    ":app",
    ":core:ui",
    ":core:network",
    ":core:data",
    ":feature:auth",
    ":feature:catalog",
    ":feature:cart",
    ":feature:checkout",
    ":feature:orders",
    ":feature:account",
)
