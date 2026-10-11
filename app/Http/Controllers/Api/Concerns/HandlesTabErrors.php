<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Exceptions\InputRejectedException;
use App\Exceptions\TabConflictException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * 028 — satu tempat untuk konvensi galat controller restoran, sama dengan
 * kasir ritel (OrderController): konflik aturan bisnis dari service → 409
 * (stok kurang, bayar kurang, tab tidak terbuka, …); masukan yang rujukannya
 * tidak sah (InputRejectedException) → 422; id tak dikenal → 404 (bawaan).
 */
trait HandlesTabErrors
{
    protected function ensureFloorAccess(Request $request): void
    {
        abort_unless($request->user()?->canAccessMenu('floor'), 403, __('restaurant.forbidden'));
    }

    protected function ensureOwnerOrAdmin(Request $request): void
    {
        abort_unless($request->user()?->isOwnerOrAdmin(), 403, __('restaurant.forbidden'));
    }

    /**
     * Seperti guarded(), tetapi status keberhasilan ditentukan aksi itu sendiri (mis. 201 saat
     * membuat, 200 saat pengiriman ulang idempoten).
     *
     * @param  callable(): array{0: mixed, 1: int}  $action  [data respons, status HTTP]
     */
    protected function guardedWithStatus(callable $action): JsonResponse
    {
        try {
            [$payload, $status] = $action();

            return response()->json($payload, $status);
        } catch (TabConflictException $e) {
            return response()->json(['message' => $e->getMessage()] + $e->extra, 409);
        } catch (InputRejectedException $e) {
            throw $e; // 422 standar Laravel
        } catch (ValidationException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], 409);
        }
    }

    /**
     * @param  callable(): mixed  $action  mengembalikan data respons (resource/array)
     */
    protected function guarded(callable $action, int $status = 200): JsonResponse
    {
        try {
            return response()->json($action(), $status);
        } catch (TabConflictException $e) {
            return response()->json(['message' => $e->getMessage()] + $e->extra, 409);
        } catch (InputRejectedException $e) {
            throw $e; // 422 standar Laravel
        } catch (ValidationException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], 409);
        }
    }
}
