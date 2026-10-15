# Data Model: Product Images in POS Search Results

**No data changes.** A client-side mapping fix.

- No tables, columns, migrations, settings, routes or permissions change.
- The photo is already stored (`product_variants.image_path`, `products.image_path`) and resolved server-side by `ProductVariant::image_url` (variant's own, else the product's, else null).
- The only "shape" change is in the SPA: the POS search card gains `image_url` (nullable string), the same field the browse card and cart item already carry.
