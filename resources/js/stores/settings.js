import { defineStore } from 'pinia';
import { featureFlags } from '../api/settings';

/**
 * Cosmetic license-tier state only (tier label in the sidebar, the
 * artist-creation CTA, the Pro tier-notice banner). The real enforcement
 * is the server's 403 on POST /artists — this store must never be treated
 * as an authorization source. See LicenseGate.php / README "Lisensi Pro
 * vs Master".
 */
export const useSettingsStore = defineStore('settings', {
  state: () => ({
    multiArtistEnabled: null,
    artistCount: 0,
    artistLimitReached: false,
    loaded: false,
  }),
  getters: {
    tierLabel: (state) => (state.multiArtistEnabled ? 'Master' : 'Pro'),
  },
  actions: {
    async load() {
      try {
        const data = await featureFlags();
        this.multiArtistEnabled = data.multi_artist_enabled;
        this.artistCount = data.artist_count;
        this.artistLimitReached = data.artist_limit_reached;
      } finally {
        this.loaded = true;
      }
    },
  },
});
