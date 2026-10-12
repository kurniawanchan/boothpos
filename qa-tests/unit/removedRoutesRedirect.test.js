import { describe, it, expect } from 'vitest';
import router from '../../resources/js/router';
import { NAV_SECTIONS, isGroup } from '../../resources/js/utils/navigation';

/**
 * 046-hide-events-preorders — modul Event dan Pre-order dihapus: alamat lamanya (termasuk yang membawa
 * ?event_id= / ?preorder_id= dan anak rutenya) menuju layar bawaan, bukan halaman galat; dan tidak ada
 * item navigasi / nama rute yang tersisa.
 */
describe('alamat lama Event dan Pre-order', () => {
  it.each([
    '/events',
    '/events?event_id=1',
    '/events/3',
    '/preorders',
    '/preorders?preorder_id=9',
    '/preorders/12/invoice',
  ])('%s dialihkan ke dashboard tanpa query lama', (path) => {
    const resolved = router.resolve(path);
    const record = resolved.matched[resolved.matched.length - 1];

    expect(typeof record.redirect).toBe('function');
    expect(record.redirect(resolved)).toEqual({ name: 'dashboard', query: {}, hash: '' });
  });

  it('tidak ada rute bernama events atau preorders', () => {
    const names = router.getRoutes().map((r) => r.name);
    expect(names).not.toContain('events');
    expect(names).not.toContain('preorders');
  });

  it('tidak ada item navigasi Events atau Pre-orders untuk peran mana pun', () => {
    const leaves = NAV_SECTIONS.flatMap((s) => s.items).flatMap((i) => (isGroup(i) ? i.children : [i]));
    const keys = leaves.flatMap((l) => [l.name, l.menuKey, l.badgeKey]);

    expect(keys).not.toContain('events');
    expect(keys).not.toContain('preorders');
  });
});
