# Quickstart: verify the payment-done screen fixes

Run on the pilot tablet after installing this feature's build.

1. **Build**: `./gradlew --offline :app:assembleDebug` from `android-pos/`. Expect success.
2. **Full number** (US1): complete a paid sale, so the "Pembayaran selesai" screen shows a queue number. Check landscape and compact widths. The whole number is visible, nothing cut at the card edge. Try a long queue (for example `A-1234`); it shrinks to fit, it is not cut.
3. **One line** (US2): the green check and the "Lunas · T03-…" badge sit side by side on one row. Try a long transaction number; the badge text shrinks, it does not wrap under the check.
4. **Call sound** (US3): tap "Panggil pelanggan". The tablet speaks the number in Indonesian (`A-012` → "A, nol, satu, dua"). Time from tap to first sound: note it (target within 1 second).
5. **Double tap**: tap "Panggil pelanggan" twice quickly. The number is spoken once, not overlapped.
6. **Muted**: set the media volume to zero and tap. The app shows "Dipanggil …", does not hang, and the next sale still works.
7. **Voice check**: if the engine has no Indonesian voice, the number is still spoken with the default voice. Record which voice was used and whether it is understandable.
8. **No queue**: a sale without a queue number shows no call card and plays nothing.

Record for each step: pass or fail, device model, Android version, the spoken output heard, and a photo of the screen.
