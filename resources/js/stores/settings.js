import { defineStore } from 'pinia';
import { featureFlags, updateSettings } from '../api/settings';

/**
 * Cosmetic license-tier state only (tier label in the sidebar, the
 * artist-creation CTA, the Pro tier-notice banner). The real enforcement
 * is the server's 403 on POST /artists — this store must never be treated
 * as an authorization source. See LicenseGate.php / README "Lisensi Pro
 * vs Master".
 *
 * `systemMode` (003-seed-demo-live) is the same kind of cosmetic-but-
 * server-authoritative flag: the badge/toggle here just reflect
 * ModeGate::current(); the actual data filtering happens server-side via
 * the DataModeScope global scope, not by anything this store does.
 */
export const useSettingsStore = defineStore('settings', {
  state: () => ({
    multiArtistEnabled: null,
    artistCount: 0,
    artistLimitReached: false,
    systemMode: 'live',
    loaded: false,
  }),
  getters: {
    tierLabel: (state) => (state.multiArtistEnabled ? 'Master' : 'Pro'),
    isDemoMode: (state) => state.systemMode === 'demo',
  },
  actions: {
    async load() {
      try {
        const data = await featureFlags();
        this.multiArtistEnabled = data.multi_artist_enabled;
        this.artistCount = data.artist_count;
        this.artistLimitReached = data.artist_limit_reached;
        this.systemMode = data.system_mode ?? 'live';
      } finally {
        this.loaded = true;
      }
    },

    /** Owner/admin only server-side (SettingPolicy) — see AppSidebar/RolesView for the same canAccessMenu('settings') gate used to hide the control itself. */
    async setSystemMode(mode) {
      await updateSettings([{ key: 'system_mode', value: mode, type: 'string', group: 'system' }]);
      this.systemMode = mode;
    },
  },
});
