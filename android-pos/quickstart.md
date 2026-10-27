# Quickstart Guide: Android POS Tablet

This guide covers building, testing, and validating the `android-pos` module.

## 1. Prerequisites
- **JDK 17** (ensure `JAVA_HOME` is set to Java 17)
- **Android SDK** with build-tools and platforms 34 installed
- **Android Emulator** or physical tablet running Android 8.0+ (API 26+, the app's `minSdk`)

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
adb shell am start -n com.warkop.pos/.MainActivity
```

## 5. Point the App at the Server
On the first-launch setup screen, enter the outlet server address:
- **Emulator:** `10.0.2.2:8010` (the emulator's alias for the host machine).
- **Physical tablet:** the host's LAN IP, e.g. `192.168.1.10:8010` (macOS: `ipconfig getifaddr en0`). The tablet
  and the host must be on the same network; if it cannot connect, first open `http://<ip>:8010` in the tablet's
  browser to rule out the network or the host firewall.

## 6. Production (Release) APK
Debug APKs are not for stores. For the signed release build (keystore, `local.properties`, versioning, verification,
Xiaomi install issues) see `docs/RUNBOOK.md` §13.6.
