import { defineStore } from 'pinia';
import { featureFlags, updateSettings } from '../api/settings';
import { applyThemeAccentColor } from '../utils/theme';
import { DEFAULT_APP_NAME, resolveAppName } from '../utils/appName';

/**
 * Cosmetic license-tier state only (tier label in the sidebar, the
 * outlet-creation CTA, the Pro tier-notice banner). The real enforcement
 * is the server's 403 on POST /outlets — this store must never be treated
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
    multiOutletEnabled: null,
    outletCount: 0,
    outletLimitReached: false,
    systemMode: 'live',
    themeAccentColor: null,
    receiptFooterText: '',
    receiptShowLogo: true,
    storeName: '',
    // 072 — logo toko untuk sidebar dan favicon; null = belum diunggah.
    storeLogoUrl: null,
    appName: DEFAULT_APP_NAME,
    // 042-dashboard-revamp — zona waktu toko dan jam pergantian hari bisnis, hanya untuk MEMFORMAT tanggal bisnis
    // di layar. Penentuan hari bisnis sebuah transaksi tetap dilakukan server (App\Support\BusinessDay).
    storeTimezone: 'Asia/Jakarta',
    businessDayCutoff: '00:00',
    // 035-po-row-actions — hanya true untuk owner/admin saat database tertinggal; server yang menentukan.
    schemaUpdateRequired: false,
    // 043 — rilis menu aktif? Hanya menampilkan/menyembunyikan banner rilis, angka "Belum dirilis" dan kendali
    // persetujuan; server tetap menolak tulis dengan 409 release_disabled. Bawaan false = alur langsung berlaku.
    menuReleaseEnabled: false,
    // 043 — pembulatan bawaan ubah harga massal ('none' | '500'); hanya nilai awal saklar di drawer, server tetap
    // menghitung ulang dan memvalidasi.
    priceRoundingDefault: '500',
    // 048 — tarif pajak eksklusif (persen) dan label di struk/keranjang. Hanya untuk MENAMPILKAN pratinjau keranjang;
    // server tetap menghitung pajak sebenarnya saat order dibuat. Bawaan 0 = tanpa pajak.
    taxRate: 0,
    taxLabel: '',
    loaded: false,
  }),
  getters: {
    tierLabel: (state) => (state.multiOutletEnabled ? 'Master' : 'Pro'),
    isDemoMode: (state) => state.systemMode === 'demo',
  },
  actions: {
    async load() {
      try {
        const data = await featureFlags();
        this.multiOutletEnabled = data.multi_outlet_enabled;
        this.outletCount = data.outlet_count;
        this.outletLimitReached = data.outlet_limit_reached;
        this.systemMode = data.system_mode ?? 'live';
        this.themeAccentColor = data.theme_accent_color ?? null;
        this.receiptFooterText = data.receipt_footer_text ?? '';
        this.receiptShowLogo = data.receipt_show_logo ?? true;
        this.storeName = data.store_name ?? '';
        this.storeLogoUrl = data.store_logo_url ?? null;
        this.appName = resolveAppName(data.app_name);
        this.storeTimezone = data.store_timezone || 'Asia/Jakarta';
        this.businessDayCutoff = data.business_day_cutoff || '00:00';
        this.schemaUpdateRequired = data.schema_update_required === true;
        this.menuReleaseEnabled = data.menu_release_enabled === true;
        this.priceRoundingDefault = data.price_rounding_default === 'none' ? 'none' : '500';
        this.taxRate = Number(data.tax_rate) || 0;
        this.taxLabel = data.tax_label ?? '';
        applyThemeAccentColor(this.themeAccentColor);
      } finally {
        this.loaded = true;
      }
    },

    /** Owner/admin only server-side (SettingPolicy) — see utils/navigation.js/RolesView for the same canAccessMenu('settings') gate used to hide the control itself. */
    async setSystemMode(mode) {
      await updateSettings([{ key: 'system_mode', value: mode, type: 'string', group: 'system' }]);
      this.systemMode = mode;
    },

    /** US6 — applied immediately (no reload needed) once saved. */
    async setThemeAccentColor(hex) {
      await updateSettings([{ key: 'theme_accent_color', value: hex, type: 'string', group: 'appearance' }]);
      this.themeAccentColor = hex;
      applyThemeAccentColor(hex);
    },

    /**
     * Merek produk (sidebar, "Powered by …" di dokumen). Nama kosong berarti
     * "kembali ke default" — dikirim sebagai null, sama seperti server
     * memperlakukannya. State baru berubah SETELAH server menerima.
     */
    async setAppName(name) {
      const trimmed = (name ?? '').trim();
      await updateSettings([{ key: 'app_name', value: trimmed || null, type: 'string', group: 'general' }]);
      this.appName = resolveAppName(trimmed);
    },

    /**
     * 042 — empat pengaturan hari bisnis disimpan dalam SATU permintaan massal (server memvalidasi tiap kunci;
     * satu nilai salah menolak semuanya, jadi tidak pernah tersimpan setengah-setengah). Zona waktu dan jam
     * batas ikut menyegarkan state layar; toleransi kas dan batas offline hanya dibaca server.
     */
    async setBusinessDay({ storeTimezone, businessDayCutoff, cashDifferenceTolerance, deviceOfflineAfterMinutes }) {
      await updateSettings([
        { key: 'store_timezone', value: storeTimezone, type: 'string', group: 'business_day' },
        { key: 'business_day_cutoff', value: businessDayCutoff, type: 'string', group: 'business_day' },
        { key: 'cash_difference_tolerance', value: cashDifferenceTolerance, type: 'decimal', group: 'business_day' },
        { key: 'device_offline_after_minutes', value: deviceOfflineAfterMinutes, type: 'integer', group: 'business_day' },
      ]);
      this.storeTimezone = storeTimezone;
      this.businessDayCutoff = businessDayCutoff;
    },

    /**
     * 043 — pengaturan rilis menu dalam SATU permintaan massal (satu nilai salah menolak semuanya). Hanya
     * `menu_release_enabled` hidup di state layar (menggerbang banner/persetujuan); ambang dan pembulatan dibaca server.
     */
    async setMenuRelease({ enabled, thresholdPct, roundingDefault }) {
      await updateSettings([
        { key: 'menu_release_enabled', value: enabled, type: 'boolean', group: 'menu' },
        { key: 'price_approval_threshold_pct', value: thresholdPct, type: 'decimal', group: 'menu' },
        { key: 'price_rounding_default', value: roundingDefault, type: 'string', group: 'menu' },
      ]);
      this.menuReleaseEnabled = enabled === true;
      this.priceRoundingDefault = roundingDefault === 'none' ? 'none' : '500';
    },

    /** 072 — dipanggil setelah unggah logo supaya sidebar dan favicon berubah tanpa muat ulang. */
    setStoreLogoUrl(url) {
      this.storeLogoUrl = url ?? null;
    },

    /** US7 */
    async setReceiptDisplay({ footerText, showLogo }) {
      await updateSettings([
        { key: 'receipt_footer_text', value: footerText || null, type: 'string', group: 'receipt' },
        { key: 'receipt_show_logo', value: showLogo, type: 'boolean', group: 'receipt' },
      ]);
      this.receiptFooterText = footerText || '';
      this.receiptShowLogo = showLogo;
    },
  },
});
