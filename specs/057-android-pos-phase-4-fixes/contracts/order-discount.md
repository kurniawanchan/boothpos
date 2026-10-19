# Contract: order discount fields (057)

`GET /api/v1/tabs/{id}` and the order payloads (`OrderResource`, `TabResource`) gain:

```json
{
  "discount_amount": "1320.00",
  "discount_percent": 11.00,
  "discount_reason_code": "promotion",
  "discount_reason_label": "Promosi",
  "items": [
    { "id": 9, "discount_amount": "0.00", "discount_percent": null, "discount_reason_code": null }
  ]
}
```

- `discount_percent` and `discount_reason_code` are null when there is no discount, or for discounts applied before this change.
- `POST /tabs/{id}/discounts` accepts the existing body (`percent`, `reason_code`, `scope`, `line_id`); the server stores the percent and reason on the order or line.
- `DELETE /tabs/{id}/discounts` clears the order discount fields.
