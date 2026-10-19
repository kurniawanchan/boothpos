<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Http\JsonResponse;

/**
 * 055 / T003 — kategori untuk layar kasir (Android). Daftar ini sumber kategori tablet: tablet tidak lagi menurunkannya
 * dari produk, sehingga kategori tanpa produk yang bisa dijual tetap tampil (spec FR-004).
 *
 * `HasDataMode` pada Category dan Product menyaring menurut mode sistem aktif (DEMO/LIVE) di setiap kueri, termasuk
 * `withCount`, jadi tidak ada `withoutGlobalScope` di sini.
 */
class PosCategoryController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', Product::class);

        $categories = Category::query()
            ->where('is_active', true)
            ->withCount(['products as product_count' => fn ($q) => $q->where('is_active', true)])
            ->orderBy('display_order')
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'display_order']);

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
