<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateServerAddressRequest;
use App\Models\Setting;
use App\Services\ActivityLogger;
use App\Support\ServerAddress;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * 054 / US3 — pengaturan alamat server untuk halaman admin. Logika ada di App\Support\ServerAddress; controller
 * hanya memeriksa izin, memvalidasi, dan mencatat audit (constitution I).
 */
class ServerAddressController extends Controller
{
    public function __construct(private ActivityLogger $activityLogger) {}

    public function show(): JsonResponse
    {
        $this->authorize('viewAny', Setting::class);

        return response()->json(ServerAddress::current());
    }

    public function update(UpdateServerAddressRequest $request): JsonResponse
    {
        $result = DB::transaction(function () use ($request) {
            $before = ServerAddress::current();
            ServerAddress::save($request->validated());
            $after = ServerAddress::current();

            // Audit di dalam transaksi yang sama: perubahan yang di-rollback tidak meninggalkan catatan (constitution IV).
            $this->activityLogger->log(
                $request->user()->id,
                'server_address_updated',
                'setting',
                null,
                'Alamat server diubah',
                $before,
                $after,
            );

            return $after;
        });

        return response()->json($result);
    }
}
