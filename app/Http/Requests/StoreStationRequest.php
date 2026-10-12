<?php

namespace App\Http\Requests;

use App\Models\Station;
use App\Support\OutletDefaults;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 028 US4 — gerbang `dining_setup`. Keunikan nama dicek lewat model Eloquent (bukan
 * rule `unique:` bawaan yang melewati DataModeScope — nama DEMO akan memblokir LIVE).
 */
class StoreStationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canAccessMenu('dining_setup') ?? false;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('name')) {
            $this->merge(['name' => trim((string) $this->input('name'))]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:60'],
            'display_order' => ['sometimes', 'integer', 'min:-32768', 'max:32767'],
            'is_active' => ['sometimes', 'boolean'],
            // 045 Stage 2 — outlet stasiun, hanya saat membuat; wajib termasuk outlet yang boleh dilihat.
            'outlet_id' => ['sometimes', 'nullable', 'integer'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty() || ! $this->has('name')) {
                return;
            }

            $current = $this->route('station');
            $outletId = $current?->outlet_id ?? ($this->filled('outlet_id') ? $this->integer('outlet_id') : OutletDefaults::peekForNewRecord());
            if ($outletId === null) {
                return;
            }

            // Sama dengan indeks database uniq_stations_outlet_name: per outlet, lintas mode data.
            $taken = Station::withoutGlobalScopes()
                ->where('name', $this->input('name'))
                ->where('outlet_id', $outletId)
                ->when($current, fn ($q) => $q->where('id', '!=', $current->id))
                ->exists();

            if ($taken) {
                $validator->errors()->add('name', __('restaurant.station_name_taken'));
            }
        }];
    }
}
