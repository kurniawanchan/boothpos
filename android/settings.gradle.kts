// 008-android-installer — native wrapper project, entirely separate from
// the existing app/ + resources/js/ tree (plan.md's Project Structure).
// This project's only job is process lifecycle + WebView hosting; it
// contains no BoothPOS business logic of its own (research.md R1).
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

rootProject.name = "BoothPOS-Installer"
include(":app")
