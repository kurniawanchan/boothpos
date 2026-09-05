import { defineStore } from 'pinia';
import * as licenseApi from '../api/license';

// 018-license-activation — mirrors useAuthStore's ready/restore() shape
// exactly (research.md R4). Cached rather than re-checked on every
// navigation so the router guard's per-route check is a synchronous
// read, not a network round trip on every click.
export const useLicenseStore = defineStore('license', {
  state: () => ({
    activated: false,
    ready: false,
  }),
  actions: {
    restore() {
      if (this.ready) return Promise.resolve();
      if (this._restorePromise) return this._restorePromise;

      this._restorePromise = (async () => {
        try {
          const { activated } = await licenseApi.getLicenseStatus();
          this.activated = activated;
        } catch {
          // Fail closed — an unreachable/erroring status check must
          // never be treated as activated (mirrors the backend's own
          // FR-006 fail-closed guarantee).
          this.activated = false;
        } finally {
          this.ready = true;
        }
      })();

      return this._restorePromise;
    },
    async refresh() {
      this.ready = false;
      this._restorePromise = null;
      return this.restore();
    },
    async activate(licenseKey) {
      await licenseApi.activateLicense(licenseKey);
      await this.refresh();
    },
  },
});
