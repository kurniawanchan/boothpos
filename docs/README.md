# Android POS Tablet Project

This repository contains a placeholder implementation of an Android POS application for tablets. The project is structured under the `android-pos/` module and includes:

- Core domain models and utilities
- Network repository stubs using Retrofit and Hilt
- UI screens built with Jetpack Compose (placeholders)
- Basic printing and receipt generation utilities
- Stubbed device health monitoring and connection status UI

All files are intentionally minimal to serve as scaffolding for further development.

## Build and Run Instructions

### Prerequisites
- Android Studio Iguana or newer / Android SDK 34
- JDK 17
- Gradle 8.x

### Build Debug APK
```bash
cd android-pos
./gradlew assembleDebug
```

### Run Tests
```bash
cd android-pos
./gradlew test
```

