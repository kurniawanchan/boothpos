# Quickstart: verify the new icon and name

Run on the pilot tablet after installing the debug or release build from this feature.

1. **Build**: from `android-pos/`, run `./gradlew --offline :app:assembleDebug`. Expect `BUILD SUCCESSFUL`.
2. **Launcher icon**: find Warkop WJM in the app drawer. The icon must be the approved Warkop artwork, not the Android default. Check at least two launcher masks (circle and rounded square, if the launcher offers them). The logo must not be clipped at the edges.
3. **Label**: the label under the icon, and the name in Settings > Apps, must read exactly "Warkop WJM POS".
4. **Sharpness**: on a second screen density, if available, the icon is sharp.
5. **Background**: the icon background is white (`#FFFFFF`). If the product owner chose another colour, change `ic_launcher_background` and repeat step 2.
6. **Update in place**: install over the previous build without uninstalling. The app must open with the existing login state, the paired terminal, and queued print jobs intact (spec SC-003). If it asks to re-pair, stop and record it as a failure.
7. **Server unchanged**: the web app's "Powered by" text still reads BoothPOS (`app_name` setting). Expected; spec FR-005.

Record for each step: pass or fail, device model, Android version, and a screenshot of the launcher.
