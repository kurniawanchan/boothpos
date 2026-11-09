<?php

namespace App\Services;

use App\Models\Preorder;
use App\Models\Shipment;
use Illuminate\Validation\ValidationException;

/**
 * 065 — diekstrak dari ShipmentController::store supaya endpoint individual dan aksi massal membuat data
 * pengiriman lewat SATU aturan (Constitution I): hanya fulfillment kurir (Mail Order), satu pengiriman per
 * pre-order (tak pernah menimpa yang ada), bidang wajib & panjang maksimum yang sama.
 *
 * Status di sini sengaja 409 untuk konflik bisnis (konvensi proyek) — pemanggil memetakan sendiri.
 */
class ShipmentService
{
    /**
     * Bentuk data (dipakai controller untuk validasi HTTP; aksi massal memakainya lewat requiredRecipientFields()).
     * 022 — city/postal_code dihapus; alamat cukup satu field (address_line), sama seperti Customer.address.
     */
    public const STORE_RULES = [
        'courier_name' => ['required', 'string', 'max:50'],
        'tracking_number' => ['nullable', 'string', 'max:50'],
        'shipping_cost' => ['sometimes', 'numeric', 'min:0'],
        'recipient_name' => ['required', 'string', 'max:100'],
        'recipient_phone' => ['required', 'string', 'max:30'],
        'address_line' => ['required', 'string', 'max:255'],
        'province' => ['nullable', 'string', 'max:100'],
        'notes' => ['nullable', 'string'],
    ];

    /** Bidang penerima yang wajib berisi (turunan STORE_RULES: semua yang 'required' selain kurir). */
    public const RECIPIENT_FIELDS = ['recipient_name', 'recipient_phone', 'address_line'];

    /**
     * Jawaban tunggal atas "kenapa pengiriman tak bisa dibuat untuk pre-order ini". null = boleh.
     *
     * @return array{code:string,message:string}|null
     */
    public function blocker(Preorder $preorder): ?array
    {
        if ($preorder->fulfillment !== 'courier') {
            return ['code' => 'not_mail_order', 'message' => __('preorders.not_courier_fulfillment')];
        }

        if ($preorder->shipment) {
            return ['code' => 'shipment_exists', 'message' => __('preorders.shipment_already_exists')];
        }

        return null;
    }

    /**
     * Membuat SATU pengiriman. `$data` sudah divalidasi bentuknya (STORE_RULES). Tidak pernah menimpa pengiriman
     * yang ada.
     *
     * @throws ValidationException (->status(409)) bila bukan Mail Order atau sudah ada pengiriman
     */
    public function createFor(Preorder $preorder, array $data): Shipment
    {
        if ($blocker = $this->blocker($preorder)) {
            throw ValidationException::withMessages(['shipment' => $blocker['message']])->status(409);
        }

        return $preorder->shipment()->create($data);
    }

    /**
     * Isian awal penerima = sumber yang sama dengan formulir pengiriman di detail pre-order (PreordersView):
     * nama/telepon/alamat pelanggan pre-order itu sendiri dan kurir pilihan saat pre-order dibuat.
     *
     * @return array{courier_name:string,recipient_name:string,recipient_phone:string,address_line:string}
     */
    public function recipientDefaults(Preorder $preorder): array
    {
        $customer = $preorder->customer;

        return [
            'courier_name' => $preorder->courier_name ?: 'JNE',
            'recipient_name' => (string) ($customer?->name ?? ''),
            'recipient_phone' => (string) ($customer?->phone ?? ''),
            'address_line' => (string) ($customer?->address ?? ''),
        ];
    }

    /** Nama bidang penerima yang kosong (setelah trim) — untuk menandai baris sebelum konfirmasi. */
    public function missingRecipientFields(array $row): array
    {
        return array_values(array_filter(
            self::RECIPIENT_FIELDS,
            fn (string $f) => trim((string) ($row[$f] ?? '')) === '',
        ));
    }
}
