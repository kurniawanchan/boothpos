import { describe, it, expect } from 'vitest';
import { safeRedirect } from '../../resources/js/utils/safeRedirect';

// Router palsu: hanya rute bernama yang dikenal; sisanya jatuh ke catch-all
// tanpa nama (sama seperti '/:pathMatch(.*)*' di router asli).
const KNOWN = { '/sales': 'sales', '/settlements/12': 'settlement-detail', '/login': 'login', '/activate': 'activate' };
const router = {
  resolve(path) {
    const [pathname] = path.split(/[?#]/);
    return { name: KNOWN[pathname] };
  },
};

describe('safeRedirect (open-redirect guard)', () => {
  it('accepts an internal path with query string', () => {
    expect(safeRedirect('/sales?cashier=2&sort=total', router)).toBe('/sales?cashier=2&sort=total');
    expect(safeRedirect('/settlements/12', router)).toBe('/settlements/12');
  });

  it.each([
    ['protocol-relative URL', '//evil.example/x'],
    ['backslash trick', '/\\evil.example'],
    ['absolute URL', 'https://evil.example/sales'],
    ['javascript scheme', 'javascript:alert(1)'],
    ['the login page itself', '/login'],
    ['login with query', '/login?redirect=/sales'],
    ['the activation page', '/activate'],
    ['unknown route (catch-all)', '/no-such-page'],
    ['empty string', ''],
    ['no leading slash', 'sales'],
  ])('rejects %s', (_label, value) => {
    expect(safeRedirect(value, router)).toBeNull();
  });

  it('rejects non-string input (array, object, null, undefined)', () => {
    expect(safeRedirect(['/sales', '/x'], router)).toBeNull();
    expect(safeRedirect({}, router)).toBeNull();
    expect(safeRedirect(null, router)).toBeNull();
    expect(safeRedirect(undefined, router)).toBeNull();
  });
});
