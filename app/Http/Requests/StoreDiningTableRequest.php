<?php

namespace App\Http\Requests;

use App\Models\DiningTable;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 028 US1 — gerbang `dining_setup` (tingkat master data, research Keputusan 8).
 */
class StoreDiningTableRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canAccessMenu('dining_setup') ?? false;
    }

    protected function prepareForValidation(): void
    {
        // area boleh kosong/null — disimpan sebagai '' (kolom NOT NULL supaya
        // UNIQUE (mode, area, nama) benar-benar berlaku).
        if ($this->has('area')) {
            $this->merge(['area' => trim((string) $this->input('area'))]);
        }
        if ($this->has('name')) {
            $this->merge(['name' => trim((string) $this->input('name'))]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:60'],
            'area' => ['nullable', 'string', 'max:60'],
            'seats' => ['sometimes', 'integer', 'min:1', 'max:99'],
            'display_order' => ['sometimes', 'integer', 'min:-32768', 'max:32767'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Keunikan (area, nama) dicek lewat model Eloquent — BUKAN rule `unique:`
     * bawaan — karena rule itu memakai query mentah yang melewati
     * DataModeScope: meja DEMO bernama sama akan memblokir meja LIVE
     * (jebakan yang sama dengan `customer_id` di CLAUDE.md).
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $current = $this->route('dining_table');
            $name = $this->has('name') ? (string) $this->input('name') : $current?->name;
            $area = $this->has('area') ? (string) ($this->input('area') ?? '') : ($current?->area ?? '');

            if ($name === null) {
                return;
            }

            $taken = DiningTable::query()
                ->where('name', $name)
                ->where('area', $area)
                ->when($current, fn ($q) => $q->where('id', '!=', $current->id))
                ->exists();

            if ($taken) {
                $validator->errors()->add('name', __('restaurant.table_name_taken'));
            }
        }];
    }

    /** Nilai siap simpan: area null menjadi ''. */
    public function payload(): array
    {
        $data = $this->validated();

        if (array_key_exists('area', $data)) {
            $data['area'] = (string) ($data['area'] ?? '');
        }

        return $data;
    }
}
