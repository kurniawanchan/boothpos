<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PaymentChannel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentChannelController extends Controller
{
    /**
     * OWASP — Excessive Data Exposure: nomor rekening penuh hanya
     * ditampilkan ke owner/admin. Kasir menerima versi tersamar pada
     * daftar (cukup untuk memverifikasi channel mana yang dipilih),
     * nomor penuh tetap bisa dilihat kasir lewat GET /payment-channels/{id}
     * satu-per-satu saat benar-benar dipakai bertransaksi — bukan dibatasi
     * total, karena kasir memang perlu menunjukkan nomor ke pembeli.
     *
     * Keputusan ini didokumentasikan di laporan sesi sebagai ambiguitas
     * (A7): PRD tidak menspesifikasikan level penyamaran secara presisi.
     */
    public function index(Request $request): JsonResponse
    {
        $channels = PaymentChannel::where('is_active', true)
            ->orderBy('display_order')
            ->get();

        $isPrivileged = $request->user()->isOwnerOrAdmin();

        $data = $channels->map(fn (PaymentChannel $c) => [
            'id' => $c->id,
            'type' => $c->type,
            'provider' => $c->provider,
            'account_name' => $c->account_name,
            'account_number' => $this->formatAccountNumber($c->account_number, $isPrivileged),
            'qr_image_url' => $c->qr_image_path ? route('payment-channels.qr', $c->id) : null,
            'is_active' => $c->is_active,
        ]);

        return response()->json(['data' => $data]);
    }

    private function formatAccountNumber(?string $number, bool $isPrivileged): ?string
    {
        if ($number === null) {
            return null;
        }

        if ($isPrivileged) {
            return $number;
        }

        $visibleTail = substr($number, -4);
        return str_repeat('*', max(strlen($number) - 4, 0)).$visibleTail;
    }

    public function store(Request $request): JsonResponse
    {
        if (! $request->user()->isOwnerOrAdmin()) {
            return response()->json(['message' => 'Tidak berhak.'], 403);
        }

        $validated = $request->validate([
            'type' => ['required', 'in:bank_transfer,qr_ewallet'],
            'provider' => ['required', 'string', 'max:50'],
            'account_name' => ['required', 'string', 'max:100'],
            'account_number' => ['required_if:type,bank_transfer', 'nullable', 'string', 'max:50'],
            'display_order' => ['sometimes', 'integer'],
        ]);

        $channel = PaymentChannel::create($validated);

        return response()->json($channel, 201);
    }
}
