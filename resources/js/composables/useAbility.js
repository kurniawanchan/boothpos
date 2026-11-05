import { useAuthStore } from '../stores/auth';

/**
 * 057 — izin AKSI per menu untuk layar. `can('sales.void')` / `can('sales')` (= View). Reaktif: membaca
 * `auth.user.permissions`, yang disegarkan dari GET /auth/me (mis. setelah 403 `action_not_allowed`).
 * Hanya kosmetik — penegakan sebenarnya di server (middleware `ability`); gunakan dengan `v-if`.
 */
export function useAbility() {
  const auth = useAuthStore();
  function can(path) {
    const [menu, action = 'view'] = String(path).split('.');
    return auth.canDo(menu, action);
  }
  return { can };
}
