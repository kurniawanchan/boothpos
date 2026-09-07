<?php

namespace App\Http\Requests;

use App\Models\Invoice;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

class UpdateInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $invoice = $this->route('invoice');

        return $this->user()?->can('update', $invoice) ?? false;
    }

    protected function failedAuthorization(): void
    {
        $response = Gate::inspect('update', $this->route('invoice'));

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
