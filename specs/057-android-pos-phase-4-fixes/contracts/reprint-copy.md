# Contract: reprint copy number (057)

`POST /api/v1/orders/{id}/reprints` body:

```json
{ "copy_no": 2, "override_token": null }
```

- `copy_no` is required, integer, minimum 2, and must equal `receipt_copies + 2` on the server (existing rule).
- 409 `reprint_copy_changed` returns the expected number in `context.copy_no`; the tablet re-reads the order and retries once.
- The order payload carries the reprint count so the tablet can compute the next copy number.
