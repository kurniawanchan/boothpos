<?php

namespace App\Http\Requests;

use App\Models\PurchaseOrder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StorePurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', PurchaseOrder::class) ?? false;
    }

    protected function failedAuthorization(): void
    {
        $response = Gate::inspect('create', PurchaseOrder::class);

        throw new AuthorizationException($response->message() ?: 'Tidak berhak.');
    }

    public function rules(): array
    {
        return [
            'vendor_id' => ['required', 'integer', 'exists:vendors,id'],
            'notes' => ['nullable', 'string'],

            'items' => ['required', 'array', 'min:1'],
            'items.*.line_type' => ['required', Rule::in(['material', 'service'])],
            'items.*.material_id' => ['nullable', 'integer', 'exists:materials,id'],
            'items.*.product_id' => ['nullable', 'integer', 'exists:products,id'],
            'items.*.description' => ['nullable', 'string', 'max:255'],
            'items.*.qty' => ['required', 'numeric', 'min:0.001'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
        ];
    }

    /**
     * material_id WAJIB saat line_type=material, description WAJIB saat
     * line_type=service (FR-001) — silangan antar-field, bukan aturan
     * per-field tunggal, jadi ditegakkan lewat withValidator() alih-alih
     * Rule::requiredIf() yang tidak bisa membaca sibling index array yang
     * sama dengan mudah untuk kasus bertingkat begini.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            foreach ($this->input('items', []) as $index => $item) {
                if (($item['line_type'] ?? null) === 'material' && empty($item['material_id'])) {
                    $validator->errors()->add("items.{$index}.material_id", __('purchase_orders.material_required_for_material_line'));
                }

                if (($item['line_type'] ?? null) === 'service' && empty($item['description'])) {
                    $validator->errors()->add("items.{$index}.description", __('purchase_orders.description_required_for_service_line'));
                }
            }
        });
    }
}
