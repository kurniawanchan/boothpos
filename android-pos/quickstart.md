# Quickstart Guide: Android POS Tablet

This guide covers building, testing, and validating the `android-pos` module.

## 1. Prerequisites
- **JDK 17** (ensure `JAVA_HOME` is set to Java 17)
- **Android SDK** with build-tools and platforms 34 installed
- **Android Emulator** or physical tablet running Android 10+ (API 29+)

## 2. Build the Project
Navigate to the `android-pos/` directory:

```bash
cd android-pos
./gradlew assembleDebug
```

Output APK will be generated at:
`app/build/outputs/apk/debug/app-debug.apk`

## 3. Run Static Analysis & Unit Tests
```bash
./gradlew test
```

## 4. Install & Launch on Tablet / Emulator
Verify connected devices:
```bash
adb devices
```

Install the debug APK:
```bash
adb install -r app/build/outputs/apk/debug/app-debug.apk
```

Launch the application:
```bash
adb shell am start -n com.warkop.app/.MainActivity
```
