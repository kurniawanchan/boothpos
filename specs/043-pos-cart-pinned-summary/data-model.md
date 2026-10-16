# Data Model: POS Cart Summary Stays Visible

No persisted data changes. The only state involved is UI state that already exists:

| State | Source | Used for |
|---|---|---|
| Route `meta.fullHeight` | `resources/js/router/index.js` (POS route) | Tells the shell to run at viewport height |
| Cart items and totals | `posCart` store (unchanged) | Rendered in the cart column; values are not affected by layout |

No new fields, validations or state transitions.
