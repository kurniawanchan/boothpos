import axios from 'axios';
import { getToken, clearToken } from '../utils/storage';
import { normalizeAxiosError } from '../utils/errors';
import { useToastStore } from '../stores/toast';

// Relative base URL — the built SPA is served by this same Laravel app
// (PRD §9), so no cross-origin base URL / CORS config is ever needed in
// the shipped runtime. `vite.config.js` proxies `/api` to `php artisan
// serve` during `npm run dev` only.
const client = axios.create({ baseURL: '/api/v1' });

client.interceptors.request.use((config) => {
  const token = getToken();
  if (token) {
    config.headers.Authorization = `Bearer ${token}`;
  }
  return config;
});

client.interceptors.response.use(
  (response) => response,
  (error) => {
    const apiError = normalizeAxiosError(error);

    // 401 — token missing/expired/revoked. Clear it and hard-redirect to
    // login; a hard redirect (not router.push) sidesteps any circular
    // import between the API client and app state/router modules and
    // guarantees a clean slate for the next login.
    if (apiError.status === 401) {
      clearToken();
      if (!window.location.pathname.startsWith('/login')) {
        window.location.href = '/login';
      }
      return Promise.reject(apiError);
    }

    // 409 — expected business-rule conflict (insufficient stock, session
    // already open, missing proof, invalid status transition, delete
    // guard). Surface it prominently everywhere, once, here — callers may
    // still catch it to adjust local UI state, but they never need to
    // remember to toast it themselves.
    if (apiError.isConflict) {
      useToastStore().error(apiError.message);
    }

    // 403 on an object-level check we couldn't predict client-side
    // (session close/summary ownership, etc.) — surface it instead of
    // crashing. Predictable 403s (profit report, stock adjust, artist
    // quota) should already have hidden the trigger, so this mostly fires
    // for the unpredictable cases the brief calls out.
    if (apiError.isForbidden) {
      useToastStore().error(apiError.message || 'Anda tidak memiliki akses untuk tindakan ini.');
    }

    return Promise.reject(apiError);
  }
);

export default client;
