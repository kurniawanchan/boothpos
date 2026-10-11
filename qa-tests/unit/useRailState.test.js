import { beforeEach, describe, expect, it } from 'vitest';
import { nextTick, ref } from 'vue';
import { useRailState } from '../../resources/js/composables/useRailState';

beforeEach(() => localStorage.clear());

describe('useRailState', () => {
  it('bawaannya lebar (tidak ciut)', () => {
    expect(useRailState(1).collapsed.value).toBe(false);
  });

  it('menyimpan pilihan per pengguna dan membacanya kembali', () => {
    const a = useRailState(1);
    a.toggle();
    expect(localStorage.getItem('boothpos:rail-collapsed:1')).toBe('collapsed');
    expect(useRailState(1).collapsed.value).toBe(true);
    // Pengguna lain di perangkat yang sama tidak terpengaruh.
    expect(useRailState(2).collapsed.value).toBe(false);
  });

  it('membaca kunci sidebar lama ("hidden") sebagai nilai awal ciut', () => {
    localStorage.setItem('boothpos:sidebar-visible', 'hidden');
    expect(useRailState(5).collapsed.value).toBe(true);
  });

  it('pilihan eksplisit per pengguna mengalahkan kunci lama', () => {
    localStorage.setItem('boothpos:sidebar-visible', 'hidden');
    localStorage.setItem('boothpos:rail-collapsed:5', 'expanded');
    expect(useRailState(5).collapsed.value).toBe(false);
  });

  it('berganti pengguna memuat preferensi pengguna itu', async () => {
    localStorage.setItem('boothpos:rail-collapsed:2', 'collapsed');
    const id = ref(1);
    const state = useRailState(id);
    expect(state.collapsed.value).toBe(false);
    id.value = 2;
    await nextTick();
    expect(state.collapsed.value).toBe(true);
  });

  it('tidak melempar saat localStorage tidak tersedia', () => {
    const original = Storage.prototype.setItem;
    Storage.prototype.setItem = () => {
      throw new Error('quota');
    };
    try {
      const s = useRailState(1);
      expect(() => s.toggle()).not.toThrow();
      expect(s.collapsed.value).toBe(true);
    } finally {
      Storage.prototype.setItem = original;
    }
  });
});
