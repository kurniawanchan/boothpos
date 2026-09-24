<?php

namespace App\Support;

use App\Models\PaymentChannel;
use App\Models\Setting;
use App\Services\ImageUploadService;

/**
 * 022-preorder-invoice-crud-overhaul (research.md Decision 1) — identitas
 * toko untuk dokumen invoice pre-order/payment invoice DISALIN dari
 * assembly yang sudah ada persis di OrderController::receipt() (struk
 * POS), bukan dirancang ulang dari nol, supaya perilaku degradasi
 * graceful (field yang belum dikonfigurasi HILANG dari respons, bukan
 * string kosong) dan nama toko yang mode-aware (DEMO/LIVE) tidak perlu
 * ditemukan ulang dan berisiko sedikit berbeda dari struk POS.
 *
 * `payment_channels` di sini SENGAJA menampilkan account_number APA
 * ADANYA (tidak disamarkan) — berbeda dari PaymentChannelController::
 * index() yang menyamarkan nomor untuk kasir non-privileged. Invoice ini
 * dibaca PELANGGAN untuk benar-benar membayar, jadi penyamaran di sini
 * justru membuat dokumennya tidak berguna (research.md Decision 2). Ini
 * pengecualian sempit untuk SATU dokumen customer-facing ini, bukan
 * pelonggaran aturan penyamaran itu sendiri di tempat lain.
 *
 * Ditempatkan di App\Support (bukan Http\Controllers\Concerns) karena
 * dipakai baik oleh PreorderController (respons JSON invoice) maupun
 * PreorderInvoiceMail (badan email bulk, US5) — bukan sesuatu yang
 * khusus-controller.
 */
trait BuildsInvoiceDocument
{
    protected function buildInvoiceDocumentFields(ImageUploadService $imageUploadService): array
    {
        return [
            'store_identity' => $this->buildStoreIdentity($imageUploadService),
            'payment_channels' => $this->buildPaymentChannels($imageUploadService),
            'footer_text' => Setting::get('receipt_footer_text'),
        ];
    }

    private function buildStoreIdentity(ImageUploadService $imageUploadService): array
    {
        return [
            'name' => Setting::get(ModeGate::isDemo() ? 'store_name_demo' : 'store_name', 'Toko'),
            'logo_url' => filter_var(Setting::get('receipt_show_logo', true), FILTER_VALIDATE_BOOLEAN)
                ? $imageUploadService->url(Setting::get('store_logo_path'))
                : null,
            'contact_person' => Setting::get('store_contact_person'),
            'contact_phone' => Setting::get('store_contact_phone'),
            'contact_email' => Setting::get('store_contact_email'),
            'address' => Setting::get('store_address'),
        ];
    }

    private function buildPaymentChannels(ImageUploadService $imageUploadService): array
    {
        return PaymentChannel::where('is_active', true)
            ->orderBy('display_order')
            ->get()
            ->map(fn (PaymentChannel $c) => [
                'id' => $c->id,
                'type' => $c->type,
                'provider' => $c->provider,
                'account_name' => $c->account_name,
                'account_number' => $c->account_number,
                'qr_image_url' => $imageUploadService->url($c->qr_image_path),
            ])
            ->all();
    }
}
