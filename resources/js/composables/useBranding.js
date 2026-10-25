import { onBeforeUnmount, watch } from 'vue';
import { useSettingsStore } from '../stores/settings';
import { buildDocumentTitle } from '../utils/documentTitle';

const DEFAULT_FAVICON = '/favicon.ico';

/** Elemen ikon yang sudah ada (dari app.blade.php) dipakai ulang; dibuat hanya bila belum ada. */
function iconLink() {
  let link = document.head.querySelector('link[rel="icon"]');
  if (!link) {
    link = document.createElement('link');
    link.rel = 'icon';
    document.head.appendChild(link);
  }

  return link;
}

/**
 * 072 — judul tab dan favicon mengikuti identitas toko (nama toko + nama aplikasi, logo toko) tanpa muat ulang.
 * Dipasang di kerangka setelah login. Sebelum login `GET /settings/features` belum bisa dibaca, jadi halaman
 * login memakai judul dan ikon bawaan dari app.blade.php; saat kerangka dilepas (logout) keduanya dikembalikan.
 * Nama toko dipasang lewat `document.title` (teks), bukan HTML, sehingga markup di nama tidak ditafsirkan.
 */
export function useBranding() {
  const settings = useSettingsStore();
  const originalTitle = document.title;

  const stopTitle = watch(
    () => [settings.storeName, settings.appName],
    ([storeName, appName]) => { document.title = buildDocumentTitle(storeName, appName); },
    { immediate: true },
  );
  const stopIcon = watch(
    () => settings.storeLogoUrl,
    (url) => { iconLink().setAttribute('href', url || DEFAULT_FAVICON); },
    { immediate: true },
  );

  onBeforeUnmount(() => {
    stopTitle();
    stopIcon();
    document.title = originalTitle;
    iconLink().setAttribute('href', DEFAULT_FAVICON);
  });
}
