# Data Model: Correct Images on Downloaded Invoices

**No data changes.** This is a client-side rendering fix.

- No tables, columns, migrations, settings, routes, response shapes or permissions change.
- The data the documents already carry is correct and is only *read* by the renderer: `store_identity.logo_url` (store logo, from the `store_logo_path` setting) and each payment channel's `qr_image_url`.
- The only "state" introduced is transient and local to one capture: an in-memory map from an image's own `src` to its fetched `data:` URI, discarded when the capture finishes.
