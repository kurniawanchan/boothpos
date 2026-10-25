# Quickstart — verify 075

**Classification**: INTERNAL

1. `cd android-pos && ./gradlew :app:testDebugUnitTest` — new tests (grouping, range, stock, sold-out, image fallback, picker) pass.
2. Dev server with the WJM catalog (068/069). Install the debug build on a tablet and a phone-size emulator; open a shift, start a Counter order.
3. Grid: one card per product; "ABC Klepon"-style product shows "2 varian" + price range; single-variant product has no badge.
4. Photos: products with a stored photo show it (compare with web POS); one without shows initials; stop the server/Wi-Fi and reopen → initials, no error popup.
5. Tap multi-variant product → picker (photo, name, code, price, stock) → tap a variant → one line added, sheet closed; scrim tap adds nothing.
6. Sold-out: mark a variant "habis" (hold in picker) → row marked, not addable; mark all → card "Habis". Confirm the stock-vs-availability assumption (R4) against web POS for a zero-stock item.
7. Option-group item: pick variant → existing option step follows.
8. Search "klepon" → variant cards; add from there; same line as via picker.
9. Totals: Subtotal, red Diskon, "PPN (10%)"-style tax from the server, dashed divider, big Total; compare amounts before/after on the same order (SC-005).
10. Replace a photo in the web back office → pull to refresh → new photo shows.
