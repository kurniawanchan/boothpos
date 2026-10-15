<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * 048 / api-gap B15 — header `Idempotency-Key` pada aksi tab yang tidak punya `local_ref`/`client_ref`
 * sendiri (tambah/ubah baris, kirim ke dapur).
 *
 * Aturan:
 *  - Header opsional: tanpa header perilaku lama tidak berubah (SPA web tidak terpengaruh).
 *  - Kunci + sidik permintaan (metode, path, isi) sama dan sudah selesai → jawaban tersimpan diputar
 *    ulang apa adanya (`Idempotency-Replayed: true`), tanpa menyentuh tab lagi.
 *  - Kunci sama tetapi permintaan BERBEDA → 422 `idempotency_key_reused` (kesalahan klien, bukan retry).
 *  - Kunci sama saat permintaan pertama masih berjalan → 409 `idempotency_in_progress`; klien mencoba lagi.
 *  - Hanya jawaban < 500 yang disimpan: galat server dilepas supaya retry benar-benar mengulang.
 *    Respons 4xx ikut disimpan — mengulang permintaan yang ditolak akan ditolak dengan alasan yang sama.
 *
 * Pemesanan kunci memakai INSERT pada unique (user_id, kunci), bukan SELECT-lalu-INSERT, supaya dua
 * permintaan serentak tidak bisa sama-sama lolos.
 */
class EnsureIdempotent
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');
        $user = $request->user();

        if ($key === null || $key === '' || $user === null) {
            return $next($request);
        }

        if (strlen($key) > 100) {
            return $this->error('idempotency_key_invalid', 422);
        }

        $fingerprint = hash('sha256', $request->method().'|'.$request->path().'|'.$request->getContent());

        try {
            DB::table('idempotency_keys')->insert([
                'user_id' => $user->id,
                'idempotency_key' => $key,
                'fingerprint' => $fingerprint,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException $e) {
            if (! $this->isDuplicateKey($e)) {
                throw $e;
            }

            return $this->replayOrReject($user->id, $key, $fingerprint);
        }

        try {
            $response = $next($request);
        } catch (\Throwable $e) {
            $this->release($user->id, $key);
            throw $e;
        }

        if ($response->getStatusCode() >= 500) {
            $this->release($user->id, $key);

            return $response;
        }

        DB::table('idempotency_keys')
            ->where('user_id', $user->id)->where('idempotency_key', $key)
            ->update([
                'status_code' => $response->getStatusCode(),
                'response_body' => $response->getContent(),
                'updated_at' => now(),
            ]);

        return $response;
    }

    private function replayOrReject(int $userId, string $key, string $fingerprint): Response
    {
        $row = DB::table('idempotency_keys')->where('user_id', $userId)->where('idempotency_key', $key)->first();

        // Dilepas di antara INSERT gagal dan SELECT ini: perlakukan seperti sedang berjalan, klien mengulang.
        if ($row === null || $row->status_code === null) {
            return $this->error('idempotency_in_progress', 409);
        }

        if (! hash_equals($row->fingerprint, $fingerprint)) {
            return $this->error('idempotency_key_reused', 422);
        }

        return response($row->response_body, $row->status_code, [
            'Content-Type' => 'application/json',
            'Idempotency-Replayed' => 'true',
        ]);
    }

    private function release(int $userId, string $key): void
    {
        DB::table('idempotency_keys')->where('user_id', $userId)->where('idempotency_key', $key)->delete();
    }

    private function isDuplicateKey(QueryException $e): bool
    {
        return in_array((string) $e->getCode(), ['23000', '23505'], true)
            && str_contains($e->getMessage(), 'idempotency_keys');
    }

    private function error(string $code, int $status): JsonResponse
    {
        return response()->json(['message' => __("system.{$code}"), 'code' => $code], $status);
    }
}
