# Data Model: 057

## orders (new nullable columns, order-level discount)

| Column | Type | Meaning |
|---|---|---|
| `discount_percent` | decimal(5,2), null | Percentage of the whole-order discount; null when none or historical |
| `discount_reason_code` | varchar(40), null | Reason code from the discount reason list |

## order_items (new nullable columns, line discount)

| Column | Type | Meaning |
|---|---|---|
| `discount_percent` | decimal(5,2), null | Percentage of the line discount |
| `discount_reason_code` | varchar(40), null | Reason code of the line discount |

Existing `orders.discount_amount` and `order_items.discount_amount` keep their meaning: the server-computed rupiah amount.

## Rules

- Applying an order discount sets `orders.discount_percent` and `orders.discount_reason_code` in the same transaction as the amount.
- Removing the order discount sets both columns to null and the amount to 0.
- Applying a line discount sets that line's two columns; a new line discount replaces the old values.
- Historical rows (before the migration) keep null; the payload shows the amount only.
- The columns are not mass-assignable from the request (only `DiscountService` writes them).

## Reprint numbering

- `receipt_copies` (existing) = number of reprints already printed.
- The next copy number = `receipt_copies + 2` (the original is copy 1).
- The order payload exposes the reprint count; the tablet computes the next copy from it.

## Closed-order time

- The closing timestamp the server returns for a completed order (verified field in R4). Omitted from the tablet text when absent.
