<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PaymentProof;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PaymentProofController extends Controller
{
    private const ALLOWED_MIME = ['image/jpeg', 'image/png'];
    private const MAX_KILOBYTES = 5120; // 5 MB

    /**
     * Bukti diunggah SEBELUM payment/order dibuat (lihat sequence diagram
     * transaksi kasir di uml-pos-mvp.md). payment_id diisi belakangan saat
     * order/preorder benar-benar tersimpan — lihat catatan migrasi
     * payment_proofs untuk penjelasan penyimpangan dari skema asli.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file' => [
                'required',
                'file',
                Rule::file()->max(self::MAX_KILOBYTES)->rules(['mimes:jpeg,png']),
            ],
            'captured_via' => ['required', 'in:webcam,upload'],
        ]);

        $file = $validated['file'];

        // Pemeriksaan MIME kedua, independen dari rule 'mimes' Laravel —
        // membaca isi berkas langsung (bukan ekstensi/nama), sebagai
        // pertahanan berlapis (defense in depth) terhadap berkas yang
        // di-rename agar lolos validasi ekstensi.
        $actualMime = $file->getMimeType();
        if (! in_array($actualMime, self::ALLOWED_MIME, true)) {
            return response()->json([
                'message' => 'Tipe berkas tidak didukung. Hanya JPEG atau PNG.',
            ], 422);
        }

        // Nama berkas ACAK, bukan nama asli — mencegah path traversal dan
        // mencegah nama berkas dipakai untuk menebak isi/pemilik.
        $randomName = Str::uuid()->toString().'.'.($actualMime === 'image/png' ? 'png' : 'jpg');
        $path = $file->storeAs('payment-proofs', $randomName, 'local'); // disk privat, di luar public/

        $proof = PaymentProof::create([
            'proof_token' => Str::uuid()->toString(),
            'payment_id' => null, // dilink saat payment dibuat
            'file_path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $actualMime,
            'file_size' => $file->getSize(),
            'captured_via' => $validated['captured_via'],
            'uploaded_by' => $request->user()->id,
            'created_at' => now(),
        ]);

        return response()->json([
            'proof_token' => $proof->proof_token,
            'file_size' => $proof->file_size,
        ], 201);
    }

    public function show(Request $request, PaymentProof $proof): Response|JsonResponse
    {
        // Otorisasi objek: hanya owner/admin, atau si pengunggah sendiri.
        // Mencegah kasir A membaca bukti pembayaran transaksi kasir B lewat
        // tebak-tebakan ID (BOLA).
        $user = $request->user();
        $isOwnerOfProof = $proof->uploaded_by === $user->id;

        if (! $user->isOwnerOrAdmin() && ! $isOwnerOfProof) {
            return response()->json(['message' => 'Tidak berhak mengakses berkas ini.'], 403);
        }

        if (! Storage::disk('local')->exists($proof->file_path)) {
            return response()->json(['message' => 'Berkas tidak ditemukan.'], 404);
        }

        return response(Storage::disk('local')->get($proof->file_path))
            ->header('Content-Type', $proof->mime_type)
            ->header('Cache-Control', 'private, max-age=3600');
    }
}
