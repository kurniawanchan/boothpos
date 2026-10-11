import { describe, expect, it } from 'vitest';
import { NAV_SECTIONS, isActive, isGroup, visibleSections } from '../../resources/js/utils/navigation';

const allowAll = () => true;
const allow = (...keys) => (k) => keys.includes(k);

describe('visibleSections', () => {
  it('mengembalikan semua modul untuk peran yang boleh semuanya', () => {
    const names = visibleSections(allowAll).flatMap((s) => s.items.map((i) => i.name ?? i.key));
    expect(names).toContain('dashboard');
    expect(names).toContain('restaurant-group');
    expect(names).toContain('settings-group');
  });

  it('tidak pernah menampilkan modul yang tidak boleh diakses (disembunyikan, bukan dinonaktifkan)', () => {
    const sections = visibleSections(allow('dashboard', 'pos'));
    const names = sections.flatMap((s) => s.items.map((i) => i.name ?? i.key));
    expect(names).toEqual(['dashboard', 'pos']);
  });

  it('membuang grup tanpa anak yang boleh diakses dan bagian yang jadi kosong', () => {
    const sections = visibleSections(allow('dashboard'));
    expect(sections.map((s) => s.key)).toEqual(['overview']);
  });

  it('menyaring anak grup dan menyisakan grup bila ada satu anak yang boleh', () => {
    const sections = visibleSections(allow('floor'));
    const group = sections.flatMap((s) => s.items).find((i) => i.key === 'restaurant-group');
    expect(group.children.map((c) => c.name)).toEqual(['floor']);
  });

  it('memberi lencana per badgeKey dan titik status per nama item atau kunci grup', () => {
    const sections = visibleSections(allowAll, { cart: 3, preorders: 5 }, { sales: true, 'restaurant-group': true });
    const items = sections.flatMap((s) => s.items);
    expect(items.find((i) => i.name === 'pos').badge).toBe(3);
    expect(items.find((i) => i.name === 'preorders').badge).toBe(5);
    expect(items.find((i) => i.name === 'sales').dot).toBe(true);
    expect(items.find((i) => i.name === 'session').dot).toBe(false);
    expect(items.find((i) => i.key === 'restaurant-group').dot).toBe(true);
  });

  it('setiap menuKey, nama rute, dan label unik/terdefinisi', () => {
    const leaves = NAV_SECTIONS.flatMap((s) => s.items).flatMap((i) => (isGroup(i) ? i.children : [i]));
    const names = leaves.map((l) => l.name);
    expect(new Set(names).size).toBe(names.length);
    for (const l of leaves) {
      expect(l.label).toMatch(/^nav\./);
      expect(l.menuKey).toBeTruthy();
    }
  });
});

describe('isActive', () => {
  const group = { key: 'g', children: [{ name: 'a' }, { name: 'b' }] };
  it('mencocokkan item tunggal berdasarkan nama rute', () => {
    expect(isActive({ name: 'sales' }, 'sales')).toBe(true);
    expect(isActive({ name: 'sales' }, 'pos')).toBe(false);
  });
  it('menganggap grup aktif bila salah satu anaknya aktif', () => {
    expect(isActive(group, 'b')).toBe(true);
    expect(isActive(group, 'z')).toBe(false);
  });
});
