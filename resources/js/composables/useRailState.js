import { ref, watch, unref } from 'vue';

/**
 * 041-back-office-ui-redesign — keadaan rel navigasi (ciut/lebar), diingat PER PENGGUNA di perangkat ini.
 *
 * Sebelumnya satu kunci global `boothpos:sidebar-visible` ('visible'|'hidden'). Preferensi tampilan murni
 * (bukan data akun), jadi tetap di localStorage, tidak lewat API. Pembacaan/penulisan dibungkus
 * try/catch karena localStorage bisa melempar di mode privat; bila gagal, rel cukup tampil lebar (aman).
 * Kunci lama dibaca SEKALI sebagai nilai awal: pengguna yang pernah menyembunyikan sidebar mendapat rel
 * yang ciut, bukan kembali ke lebar tanpa penjelasan.
 */
const LEGACY_KEY = 'boothpos:sidebar-visible';
const keyFor = (userId) => `boothpos:rail-collapsed:${userId ?? 'anon'}`;

function read(userId) {
  try {
    const stored = localStorage.getItem(keyFor(userId));
    if (stored === 'collapsed') return true;
    if (stored === 'expanded') return false;
    return localStorage.getItem(LEGACY_KEY) === 'hidden';
  } catch {
    return false;
  }
}

/** @param {import('vue').Ref<number|string|null>|number|string|null} userId */
export function useRailState(userId = null) {
  const collapsed = ref(read(unref(userId)));

  function persist() {
    try {
      localStorage.setItem(keyFor(unref(userId)), collapsed.value ? 'collapsed' : 'expanded');
    } catch {
      // Non-fatal — preferensi cukup tidak tersimpan lintas sesi.
    }
  }

  function set(value) {
    collapsed.value = Boolean(value);
    persist();
  }

  function toggle() {
    set(!collapsed.value);
  }

  // Pengguna berganti (logout/login di perangkat yang sama) -> pakai preferensi pengguna itu.
  watch(
    () => unref(userId),
    (id) => {
      collapsed.value = read(id);
    }
  );

  return { collapsed, set, toggle };
}
