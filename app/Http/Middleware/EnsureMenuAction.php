<?php

namespace App\Http\Middleware;

use App\Support\MenuActions;
use App\Support\MenuKeys;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 057 — gerbang izin AKSI per menu pada rute, alias `ability`:
 *   `ability:sales`       → aksi standar dari metode HTTP (POST=create, PUT/PATCH=edit, DELETE=delete);
 *   `ability:sales,void`  → aksi bernama (atau standar yang disebut eksplisit, mis. `ability:session,edit`).
 *
 * Ini gerbang TAMBAHAN: pemeriksaan FormRequest/Policy/inline yang sudah ada tetap berjalan sesudahnya, jadi izin
 * aksi hanya bisa mempersempit, tak pernah melebarkan (spec FR-009). Penolakan = 403 `action_not_allowed`
 * sebelum controller disentuh, tak menulis apa pun (tak masuk activity log). Pasangan (menu, aksi) yang tak
 * terdaftar melempar saat dipanggil — salah ketik di routes/api.php ketahuan oleh MenuActionsRouteCoverageTest.
 */
class EnsureMenuAction
{
    public function handle(Request $request, Closure $next, string $menu, ?string $action = null): Response
    {
        $action ??= MenuActions::fromHttpMethod($request->method());
        MenuActions::assertValid($menu, $action);

        $user = $request->user();
        if ($user !== null && ! $user->canDo($menu, $action)) {
            return response()->json([
                'message' => __('permissions.action_not_allowed', [
                    'action' => __('permissions.actions.'.$action),
                    'menu' => MenuKeys::ALL[$menu] ?? $menu,
                ]),
                'code' => 'action_not_allowed',
                'menu' => $menu,
                'action' => $action,
            ], 403);
        }

        return $next($request);
    }
}
