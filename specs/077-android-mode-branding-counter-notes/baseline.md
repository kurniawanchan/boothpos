# Baseline — 077 (2026-10-26)

**Classification**: INTERNAL

- Android `./gradlew :app:testDebugUnitTest`: 289 tests, 0 failed, 0 skipped (107 result files; task was UP-TO-DATE, results read from `app/build/test-results`).
- Server (safe flags) `--filter='PosSetupTest|TabLifecycleTest|TabDataModeTest|TabDetailReadTest'`: 41 passed, 176 assertions.
- Known pre-existing failure outside these areas: `ShiftReportParityTest` (065).
