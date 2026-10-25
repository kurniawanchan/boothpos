<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Services\OutletMenuService;
use App\Support\OutletContext;
use Illuminate\Http\JsonResponse;

/**
 * 055 / T003 — kategori untuk layar kasir (Android). Daftar ini sumber kategori tablet: tablet tidak lagi menurunkannya
 * dari produk, sehingga kategori tanpa produk yang bisa dijual tetap tampil (spec FR-004).
 *
 * 069 research D10: kategori dipakai bersama beberapa outlet, jadi tablet satu outlet akan menerima tab kosong milik
 * outlet lain. Kategori yang PUNYA item tetapi seluruhnya dijual di outlet lain disembunyikan, dengan aturan "dijual
 * di outlet ini" yang sama dengan `GET /products?pos=1` (`OutletMenuService::notCarriedProductIds`, outlet shift).
 * Kategori yang sama sekali belum punya item tetap tampil, dan `product_count` menghitung item aktif di outlet ini.
 *
 * `HasDataMode` pada Category dan Product menyaring menurut mode sistem aktif (DEMO/LIVE) di setiap kueri, termasuk
 * `withCount`, jadi tidak ada `withoutGlobalScope` di sini.
 */
class PosCategoryController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', Product::class);

        $notHere = app(OutletMenuService::class)->notCarriedProductIds(app(OutletContext::class)->orderingId());

        $categories = Category::query()
            ->where('is_active', true)
            ->withCount([
                'products as product_count' => fn ($q) => $q->where('is_active', true)->when($notHere !== [], fn ($q2) => $q2->whereNotIn('id', $notHere)),
                'products as here_count' => fn ($q) => $q->when($notHere !== [], fn ($q2) => $q2->whereNotIn('id', $notHere)),
                'products as away_count' => fn ($q) => $q->when($notHere !== [], fn ($q2) => $q2->whereIn('id', $notHere), fn ($q2) => $q2->whereRaw('1 = 0')),
            ])
            ->orderBy('display_order')
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'display_order'])
            ->reject(fn (Category $c) => $c->away_count > 0 && $c->here_count === 0);

        return response()->json([
            'data' => $categories->map(fn (Category $c) => [
                'id' => $c->id,
                'code' => $c->code,
                'name' => $c->name,
                'display_order' => $c->display_order,
                'product_count' => (int) $c->product_count,
            ])->values(),
        ]);
    }
}
