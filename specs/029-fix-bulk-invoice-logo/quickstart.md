# Quickstart: verifying the image fix

## Automated

```bash
npx vitest run qa-tests/unit/pdfCapture.test.js
npm test            # full suite, no backend needed
```

Cases the unit test must cover (they fail on the old positional code):
1. **Decoy images before the document** — the simulated cloned page contains an unrelated avatar `<img>` *before* the document's own images: the logo gets the logo's bytes, the QR gets the QR's bytes, the avatar is untouched.
2. **Decoy images inside and after** — extra images between and after the document's images do not shift anything.
3. **Distinct bytes per URL** — the fetch mock returns different content per URL so a swap is detectable (not just "starts with `data:`").
4. **Reference-element scoping** — when html2canvas passes the cloned reference element, only images inside it are touched.
5. **Failed fetch** — an image whose fetch fails keeps its original `src`; the others still get their own data; capture still resolves.
6. **Same URL twice** — both elements get the same (correct) data and the URL is fetched once.
7. **No images** — resolves as before.
8. Existing cases stay green (live element's `src` untouched, PDF/PNG helpers).

## Real-browser check (Constitution II)

Use an **isolated** server + the test database (never the dev DB), as in earlier features: seed with `db:seed` + `license:dev-activate`, then create a store logo upload, a QR payment channel with an image, and 2–3 pre-orders; serve on another port.

1. Log in, open **Pre-orders**. Make sure the page shows at least one other picture (inject a decoy `<img>` into the header via DevTools to reproduce deterministically if the page has none).
2. Tick 2–3 pre-orders → **Download** (invoice) in bulk. To inspect without unzipping, temporarily wrap `HTMLCanvasElement.prototype.toDataURL` in the console to keep each rendered canvas, then draw them into a visible overlay and screenshot.
3. Expect for every invoice: the store logo at the top, the QR only under "Cara pembayaran". **Before the fix** the QR appears at the top.
4. Repeat for **payment invoice** and **shipping slip** bulk actions, and a single invoice/payment-invoice/receipt download from its modal.
5. Console clean; EN ↔ ID unaffected.

Final confirmation by the reporter: re-download `invoice-PO-20261001-0004.pdf` from the real page and open it — the logo slot shows the store logo.
