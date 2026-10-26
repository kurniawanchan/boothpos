**Classification: INTERNAL**

# Contract: cross-tab session and store branding (frontend + one additive field)

## 1. Cross-tab session channel (`utils/tabSession.js`)

Same-origin `BroadcastChannel` named `boothpos-session`. Messages are plain objects:

| Message | Sent by | Meaning | Receiver behaviour |
|---|---|---|---|
| `{type:'request'}` | a tab with **no** token, at boot | "does any tab hold a session?" | A tab that holds a token replies with `token`. A tab without one stays silent. |
| `{type:'token', token}` | a signed-in tab | reply to `request` | Only the requesting tab (the one currently waiting) accepts it, writes it to **its own** `sessionStorage`, then continues `restore()` (`GET /auth/me`). Ignored by every other tab. |
| `{type:'logout'}` | the tab that logs out | session ended | Every other tab: clear token, empty `auth.user`, hard-redirect to `/login`. |

Rules:

- Wait for a reply at most `HANDOFF_TIMEOUT_MS = 250`. No reply → proceed as signed out (login). No `BroadcastChannel` → resolve `null` at once (today's behaviour).
- The token is **never** written to `localStorage`, cookies, or the URL; it exists only in `sessionStorage` and in the message in flight.
- `auth.restore()` is the only caller; its existing `_restorePromise` cache keeps concurrent callers (guard + `main.js`) on one handoff.
- `auth.logout()` posts `logout` after the server call (success or failure, as today it always clears locally).
- Login redirect: the router guard sends signed-out users to `{name:'login', query:{redirect: to.fullPath}}` (omitted when `to.fullPath === '/'`); the 401 interceptor appends the same `redirect`. `LoginView` navigates to `safeRedirect(route.query.redirect)` which accepts only `^/(?!/)` paths that resolve to a known, non-`/login` route; anything else → dashboard.

## 2. Branding

Additive field on the existing `GET /settings/features` response (any signed-in role):

```jsonc
{ "store_name": "Demo Store" /* existing */, "app_name": "BoothPOS" /* existing */,
  "store_logo_url": "/storage/store-logo/abc.png" /* NEW: resolved URL or null */ }
```

Frontend contract (`composables/useBranding.js`, mounted once in `App.vue`):

- `document.title = storeName ? `${storeName} · ${appName}` : appName`
- Favicon `<link rel="icon">`: store logo rendered to a 64×64 "contain" PNG data URL when `store_logo_url` is set and loads; otherwise `/favicon.ico`. A load/canvas failure silently uses the default (no broken icon).
- `AppSidebar.vue` brand block: `<img>` logo (`@error` → default storefront glyph) · store name (truncated, `title` = full name; falls back to `appName` when blank) · tier label.
- `stores/settings.js` gains `storeLogoUrl`, `setStoreName()`, `setStoreLogoUrl()`; `SettingsView` calls them after a successful save so the title, sidebar and favicon change with no reload and no re-login.
- Login screen: unchanged (default title/icon; settings need a session).
