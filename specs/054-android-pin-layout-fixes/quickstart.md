# Quickstart: verifying 054

Run these in order. Each step names the story it proves. Do not mark a story done without its evidence (constitution II).

## Backend (after implementation)

1. Run the new feature tests inside the container with the mandatory overrides from CLAUDE.md:
   ```bash
   docker compose exec -e APP_ENV=testing -e DB_DATABASE=warkop_wjm_test app \
     php artisan test --filter=ServerAddressTest -e CACHE_STORE=array -e SESSION_DRIVER=array
   ```
   Expected: all pass. Covers US3 scenarios 1–3 and 5, FR-011, FR-012, the audit row, and the device response shape (only `selected` and `url`).
2. Confirm the OpenAPI file lists the three operations (`docs/openapi-pos-mvp.yaml`).

## Admin page (US3)

3. Sign in as `owner` (`password123`, local only). Open Settings → Alamat server. Enter a local and an online address, choose "Online", save. Expected: values persist after reload; the API returns them.
4. Sign in as `kasir01`. Confirm the section is hidden and `GET /api/v1/settings/server-address` returns 403.
5. Enter `not a url` in the online field and save. Expected: an inline Indonesian message on that field, nothing saved.
6. Browser check: open the page with the browser console open; no console errors.

## Tablet: connection state (US2)

7. Install the debug build on the pilot tablet. Sign-in screen with Wi-Fi on and the server reachable: the chip shows "Online" and there is no banner. (Expected to fail on the current build, which shows "Online" by default.)
8. Turn Wi-Fi off. Within about 10 s the chip shows "Offline" and the banner appears with "Coba lagi". Confirm the chip never read "Online" while the network was off.
9. Turn Wi-Fi on, press "Coba lagi". Expected: chip "Online", banner gone, staff list loads.
10. Admin switches the selected address (step 3). Within about 10 s on the PIN screen the tablet calls the device endpoint and uses the new URL; confirm in the app log (tokens are not logged).

## Tablet: layout (US1, US4)

11. Pilot outlet name "Warkop WJM 3" with code T02: one line, no wrap, no ellipsis, portrait and landscape.
12. Temporarily set a 40-character outlet name: the whole name is visible on one line (smaller font or moved chip). Nothing is cut.
13. Short landscape split-screen with the banner shown: scroll the sign-in content; the keypad is reachable, digits typed before scrolling remain, six digits can be submitted.
14. Normal-height screen with no banner: layout identical to the current release (side-by-side screenshot).

## Android unit tests

15. `./gradlew :app:testDebugUnitTest --tests '*PosStoreConnectionTest*' --tests '*ChipFitTest*'` in `android-pos/`. Expected: pass. Toolchain must be run, not assumed (research.md open items).

## Evidence to record in the task log

- Test output lines for step 1 and step 15.
- Screenshot references for steps 7–9, 11–14 (kept out of the repository).
- Console check result for step 6.
