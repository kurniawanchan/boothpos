<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreArtistRequest;
use App\Http\Requests\UpdateArtistRequest;
use App\Http\Resources\ArtistResource;
use App\Models\Artist;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ArtistController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Artist::class);

        $perPage = min((int) $request->integer('per_page', 25), 100);

        $artists = Artist::query()
            ->withCount('products')
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', '%'.$request->string('search').'%'))
            ->when($request->has('is_active'), fn ($q) => $q->where('is_active', $request->boolean('is_active')))
            ->orderBy('name')
            ->paginate($perPage);

        return response()->json([
            'data' => ArtistResource::collection($artists->items()),
            'meta' => [
                'current_page' => $artists->currentPage(),
                'per_page' => $artists->perPage(),
                'total' => $artists->total(),
                'last_page' => $artists->lastPage(),
            ],
        ]);
    }

    public function store(StoreArtistRequest $request): JsonResponse
    {
        $artist = Artist::create($request->validated());

        return response()->json(new ArtistResource($artist), 201);
    }

    public function show(Artist $artist): JsonResponse
    {
        $this->authorize('view', $artist);

        $artist->loadCount('products');

        return response()->json(new ArtistResource($artist));
    }

    public function update(UpdateArtistRequest $request, Artist $artist): JsonResponse
    {
        $artist->update($request->validated());

        return response()->json(new ArtistResource($artist->fresh()));
    }

    public function destroy(Artist $artist): JsonResponse
    {
        $this->authorize('delete', $artist);

        // TODO (Increment 2 — setelah modul Product ada): blokir hapus
        // bila masih ada produk aktif milik artist ini, konsisten dengan
        // aturan Category (PRD F5.4). Belum diimplementasikan sekarang
        // karena tabel 'products' belum dibangun fiturnya; memakai model
        // stub sekarang berisiko divergen dari skema Product yang
        // sebenarnya. Dicatat sebagai gap eksplisit, bukan diasumsikan aman.
        if (Schema::hasTable('products')) {
            $hasActiveProducts = DB::table('products')
                ->where('artist_id', $artist->id)
                ->where('is_active', true)
                ->exists();

            if ($hasActiveProducts) {
                return response()->json([
                    'message' => 'Artist masih memiliki produk aktif dan tidak dapat dihapus.',
                ], 409);
            }
        }

        $artist->delete();

        return response()->json(null, 204);
    }
}
