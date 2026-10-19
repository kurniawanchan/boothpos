# Contract: receipt identity fields the tablet reads (059)

Server contract unchanged. `GET /api/v1/orders/{id}/receipt` already returns:

- `store_name`, `store_address`
- `store_contact_person`, `store_contact_phone`, `store_contact_email` (nullable)
- `store_logo_url` (nullable; null when the admin turns the receipt-logo setting off)
