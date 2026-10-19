# Data Model: 059

## ReceiptView (tablet, extended)

| Field | Source | Notes |
|---|---|---|
| storeName, storeAddress | existing | unchanged |
| storeContactPerson | `store_contact_person` | new, nullable |
| storeContactPhone | `store_contact_phone` | new, nullable |
| storeContactEmail | `store_contact_email` | new, nullable |
| storeLogoUrl | `store_logo_url` | new, nullable; already null when the admin turns the logo off |

## PrintLine (tablet, extended)

A raster line carries the logo as a hex string of 1-bit rows, with its width in dots (at most 384) and height in dots. It is stored in the print job like any other line.

## Rules

- Contact lines print only when non-blank, in the same style as the address.
- The logo prints only when `storeLogoUrl` is non-null and the download and conversion succeed; otherwise the struk prints text-only.
