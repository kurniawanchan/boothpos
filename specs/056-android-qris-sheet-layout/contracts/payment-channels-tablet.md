# Contract: payment channel fields read by the tablet (056)

Server contract is **unchanged**: `GET /api/v1/payment-channels` already returns the fields below (`PaymentChannelController::index`). This file records what the Android app reads, so a server change breaks it visibly.

## Response (fields the tablet reads)

```json
{
  "data": [
    {
      "id": 3,
      "type": "qr_ewallet",
      "provider": "QRIS",
      "account_name": "Warkop WJM 3",
      "account_number": "…masked…",
      "qr_image_url": "https://…/storage/payment-channels/qr.png",
      "is_active": true
    }
  ]
}
```

- `type = qr_ewallet` selects the static QRIS channel (first match).
- `qr_image_url` may be null (no image uploaded): the tablet shows "QR belum diatur".
- `account_number` is not read on the sheet.

## Errors

Unchanged: `401` no session, `403` without POS access (same gate as before).
