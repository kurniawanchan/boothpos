# Phase 1 Data Model: Event Availability & Invoice Redesign

## Event (existing table, one new column)

**Migration**: add `available_on ENUM('day_1','day_2') NULL` (after
`end_date`).

New fields:

| Field | Type | Notes |
|---|---|---|
| `available_on` | `'day_1'\|'day_2'\|null` | Raw stored choice. `null` means "not set" — never shown on any document. |

New model behavior (`App\Models\Event`):

```php
public function availableOnDate(): ?Carbon
{
    return match ($this->available_on) {
        'day_1' => $this->start_date,
        'day_2' => $this->end_date,
        default => null,
    };
}
```

**Validation rules** (`StoreEventRequest`/`UpdateEventRequest`):
- `available_on`: `nullable`, `in:day_1,day_2`.
- Cross-field: if `start_date === end_date` (a one-day event) and
  `available_on` is present in the request, reject with a validation error
  (defensive — the frontend already hides the control in this case, per
  FR-002, but the backend enforces it independently, Constitution IV).

**Auto-clear rule** (`EventController::update()`, same transaction as the
existing pickup-day clearing):
```php
if ($event->start_date->equalTo($event->end_date) && $event->available_on !== null) {
    $event->update(['available_on' => null]);
}
```

## Preorder / Order — no schema change

`Preorder.shipping_cost` already exists and is already serialized by
`PreorderController::present()`. This feature adds no new column to either
table — only new *response* fields (below) and new *rendering* of an
already-returned field.

## Invoice / Payment Invoice response shape (`PreorderController::invoicePayload()`, extended)

One new field, alongside the existing `event_name`/`event_location`/
`event_start_date`/`event_end_date`:

```jsonc
{
  // ...existing fields unchanged (including shipping_cost, already present)...
  "event_available_on_date": "2026-11-01" // or null — resolved via Event::availableOnDate()
}
```

Omitted-safe the same way every other optional invoice field already is:
`null` when the event has no `available_on` set, or the preorder has no
linked event at all.

## Sale Receipt response shape (`OrderController::receipt()`, extended)

Same single addition:

```jsonc
{
  // ...existing fields unchanged...
  "event_available_on_date": "2026-11-01" // or null
}
```

## Settings response shape (`SettingsController::index()`/`uploadStoreLogo()`, extended)

```jsonc
// GET /settings
{
  "data": [ /* existing SettingResource rows, unchanged */ ],
  "store_logo_url": "http://localhost:8000/storage/store-logo/uuid.png" // or null
}

// POST /settings/store-logo
{
  "data": { /* existing SettingResource for store_logo_path, unchanged */ },
  "store_logo_url": "http://localhost:8000/storage/store-logo/uuid.png"
}
```

`store_logo_url` is computed identically in both places —
`$this->imageUploadService->url(Setting::get('store_logo_path'))` (index)
or `$this->imageUploadService->url($newPath)` (upload, avoiding a re-read
of the just-written setting) — the same convention every other image URL
in this product already uses.
