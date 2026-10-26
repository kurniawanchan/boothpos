Classification: INTERNAL

# BoothPOS web app documentation (Mintlify)

Source for the user guide of the BoothPOS web app. 43 MDX pages (32 web app, 11 Android app), navigation in `docs.json`.

## Preview

Mintlify needs Node 20.17 or newer.

```bash
npm i -g mint
cd docs-site
mint dev
mint validate
mint broken-links
```

## Status

- Written from code, locales and specs. No page has been rendered by Mintlify or checked in a browser.
- `mint validate` has not been run (the CLI was not installed and Node was v18 when these pages were written).
- A scripted check confirmed: every page is in `docs.json`, internal links resolve, frontmatter is complete, and prose braces are escaped for MDX.
- Pages note unverified points as `ASSUMPTION:`. Search for it before publishing.

## Scope

Covers the store-facing modules of the web app and the Android POS tablet app (UI labels on the tablet are Indonesian and are quoted as shown). Excluded: provider-side administration (Companies, Business Types, Licenses, Invoices), and removed modules (Events, Pre-orders).
