<?php

namespace App\Http\Requests;

use App\Models\Invoice;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

class StoreInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Invoice::class) ?? false;
    }

    protected function failedAuthorization(): void
    {
        $response = Gate::inspect('create', Invoice::class);

        throw new AuthorizationException($response->message() ?: 'Tidak berhak.');
    }

    public function rules(): array
    {
        return [
            'company_id' => ['required', 'exists:companies,id'],
            'license_id' => ['required', 'exists:licenses,id'],
            'subtotal' => ['required', 'numeric', 'gt:0'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'due_date' => ['required', 'date'],
            'payment_information' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
        ];
    }

    /**
     * FR-013 — diskon tidak boleh melebihi subtotal (grand_total tidak
     * boleh negatif). Divalidasi di sini, bukan cuma dipercaya dari
     * client, karena grand_total dihitung ulang di server dari kedua
     * nilai ini (InvoiceService::update()/InvoiceController::store()).
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $subtotal = (float) $this->input('subtotal', 0);
            $discount = (float) $this->input('discount', 0);

            if ($discount > $subtotal) {
                $validator->errors()->add('discount', 'Diskon tidak boleh melebihi subtotal.');
            }
        });
    }
}
