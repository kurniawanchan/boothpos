# Contracts: Event Availability & Invoice Redesign

## POST /events, PATCH /events/{event} (existing routes, request/response shape extended)

**Request body** (both), one new optional field:
```jsonc
{
  "name": "string",
  "location": "string|null",
  "start_date": "2026-11-01",
  "end_date": "2026-11-02",
  "available_on": "day_1" // "day_1" | "day_2" | null, optional
}
```

**Responses**:
- `201`/`200` — the event, now including `available_on` (raw stored value).
- `422` — standard validation, plus: `available_on` provided while
  `start_date === end_date` is rejected (`events.available_on_requires_multi_day`
  or equivalent message).

**Side effect** (`PATCH` only): if the date edit collapses the event to a
single day and it previously had `available_on` set, the response's
`available_on` is `null` (cleared server-side in the same transaction as
the existing pickup-day clearing).

## GET /preorders/{id}/invoice, POST /preorders/bulk-invoices (existing endpoints, response extended)

Adds `event_available_on_date` (string date or null) alongside the
existing `event_name`/`event_location`/`event_start_date`/`event_end_date`
fields. `shipping_cost` is already present (no change) — this contract
entry exists only to note it's now actually rendered by the invoice
document, not to describe a new field.

## GET /orders/{id}/receipt (existing endpoint, response extended)

Adds the same `event_available_on_date` field.

## GET /settings, POST /settings/store-logo (existing endpoints, response extended)

`GET /settings` response gains a sibling `store_logo_url` field (alongside
the existing `data` array). `POST /settings/store-logo`'s response gains
the same field. Both computed via `ImageUploadService::url()` — see
data-model.md.
