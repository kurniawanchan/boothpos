<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PaymentChannel;
use App\Services\ImageUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PaymentChannelController extends Controller
{
    public function __construct(private ImageUploadService $imageUploadService) {}

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

        $isPrivileged = $request->user()->canAccessMenu('settings');

        $data = $channels->map(fn (PaymentChannel $c) => [
            'id' => $c->id,
            'type' => $c->type,
            'provider' => $c->provider,
            'account_name' => $c->account_name,
            'account_number' => $this->formatAccountNumber($c->account_number, $isPrivileged),
            // BUG YANG DITEMUKAN & DIPERBAIKI — sebelumnya memanggil
            // route('payment-channels.qr', ...), padahal route bernama itu
            // TIDAK PERNAH didefinisikan di routes/api.php. Setiap channel
            // yang punya qr_image_path akan membuat endpoint ini melempar
            // RouteNotFoundException (500), bukan sekadar qr_image_url yang
            // kosong — kasir tidak bisa memuat daftar channel pembayaran
            // sama sekali begitu ada satu channel QR dengan gambar. QR code
            // wajib bisa dirender publik oleh UI POS, jadi disk 'public'
            // dipakai dan URL-nya dibentuk langsung dari situ, bukan lewat
            // endpoint otorisasi seperti bukti pembayaran.
            'qr_image_url' => $this->imageUploadService->url($c->qr_image_path),
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
        if (! $request->user()->canAccessMenu('settings')) {
            return response()->json(['message' => __('orders_payments.not_authorized')], 403);
        }

        $validated = $request->validate([
            'type' => ['required', 'in:bank_transfer,qr_ewallet'],
            'provider' => ['required', 'string', 'max:50'],
            'account_name' => ['required', 'string', 'max:100'],
            'account_number' => ['required_if:type,bank_transfer', 'nullable', 'string', 'max:50'],
            'display_order' => ['sometimes', 'integer'],
            // BUG YANG DITEMUKAN & DIPERBAIKI — store() sebelumnya sama
            // sekali tidak menerima berkas gambar, jadi pemilik toko yang
            // menambah channel Gopay/QR tidak pernah punya cara mengunggah
            // QR code-nya. Verifikasi langsung ke DB: baris Gopay di
            // payment_channels punya qr_image_path kosong. 'sometimes'
            // (bukan 'required_if:type,qr_ewallet') karena gambar boleh
            // ditambahkan belakangan lewat update().
            'qr_image' => [
                'sometimes',
                'file',
                Rule::file()->max(ImageUploadService::MAX_KILOBYTES)->rules(['mimes:jpeg,png']),
            ],
        ]);

        $qrImagePath = null;
        if ($request->hasFile('qr_image')) {
            $qrImagePath = $this->storeQrImage($request);
        }

        $channel = PaymentChannel::create(array_merge($validated, ['qr_image_path' => $qrImagePath]));

        return response()->json($this->present($channel, $request), 201);
    }

    /**
     * Endpoint yang sebelumnya sama sekali tidak ada — hanya index()/store()
     * yang terdaftar di routes/api.php. Tanpa ini, channel yang sudah
     * dibuat tanpa QR code (atau QR code-nya perlu diganti) tidak bisa
     * diperbarui sama sekali.
     */
    public function update(Request $request, PaymentChannel $channel): JsonResponse
    {
        if (! $request->user()->canAccessMenu('settings')) {
            return response()->json(['message' => __('orders_payments.not_authorized')], 403);
        }

        $validated = $request->validate([
            'type' => ['sometimes', 'in:bank_transfer,qr_ewallet'],
            'provider' => ['sometimes', 'string', 'max:50'],
            'account_name' => ['sometimes', 'string', 'max:100'],
            'account_number' => ['nullable', 'string', 'max:50'],
            'display_order' => ['sometimes', 'integer'],
            'is_active' => ['sometimes', 'boolean'],
            'qr_image' => [
                'sometimes',
                'file',
                Rule::file()->max(ImageUploadService::MAX_KILOBYTES)->rules(['mimes:jpeg,png']),
            ],
            // Memungkinkan gambar QR yang salah unggah dihapus tanpa
            // menggantinya dengan gambar baru — channel bank_transfer yang
            // sebelumnya salah diberi QR juga bisa dikembalikan ke tanpa
            // gambar lewat ini.
            'remove_qr_image' => ['sometimes', 'boolean'],
        ]);

        $channel->fill(collect($validated)->except(['qr_image', 'remove_qr_image'])->all());

        if ($request->hasFile('qr_image')) {
            $this->imageUploadService->delete($channel->qr_image_path);
            $channel->qr_image_path = $this->storeQrImage($request);
        } elseif ($request->boolean('remove_qr_image')) {
            $this->imageUploadService->delete($channel->qr_image_path);
            $channel->qr_image_path = null;
        }

        $channel->save();

        return response()->json($this->present($channel->fresh(), $request));
    }

    private function storeQrImage(Request $request): string
    {
        return $this->imageUploadService->store($request->file('qr_image'), 'payment-channels');
    }

    private function present(PaymentChannel $c, Request $request): array
    {
        $isPrivileged = $request->user()->canAccessMenu('settings');

        return [
            'id' => $c->id,
            'type' => $c->type,
            'provider' => $c->provider,
            'account_name' => $c->account_name,
            'account_number' => $this->formatAccountNumber($c->account_number, $isPrivileged),
            'qr_image_url' => $this->imageUploadService->url($c->qr_image_path),
            'display_order' => $c->display_order,
            'is_active' => $c->is_active,
        ];
    }
}
