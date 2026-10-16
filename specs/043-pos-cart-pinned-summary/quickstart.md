# Quickstart: check the POS layout in a browser

## 1. Unit and component tests

```bash
npm test -- AppShell posLayout
```

Expected: the shell adds the fixed-height classes only for the POS route; other routes keep today's classes.

## 2. Browser check at booth sizes (console checked)

Use the running dev app (`http://localhost:8000` after `npm run build`), logged in as `owner` (dev seed password), in **DEMO** mode so the test data stays out of LIVE.

For each size: tablet landscape `1180×820` and laptop `1366×768`, plus a short window `1366×600` as a stress check.

1. Open POS. Note the total and Pay button position.
2. Add enough products (or narrow the window height until the grid overflows) so the product list is taller than the screen.
3. Confirm, without scrolling the page: the discount field, subtotal, total, Pay button and the status message (when no session is open) are all visible.
4. Scroll the product grid: the cart summary does not move.
5. Add 10+ cart lines: the cart item list scrolls; the summary stays.
6. Confirm the page itself has no vertical scrollbar at the document level, and no horizontal scrollbar.
7. Check another screen (Products): it still scrolls the page as before.
8. Console has no errors.

## 3. Before merge
- `qa-tests` green (`npm test`).
- `CLAUDE.md` SPECKIT block points to this plan (done in plan step).
