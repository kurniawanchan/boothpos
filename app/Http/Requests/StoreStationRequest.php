<?php

namespace App\Http\Requests;

use App\Models\Station;
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
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty() || ! $this->has('name')) {
                return;
            }

            $current = $this->route('station');
            $taken = Station::query()
                ->where('name', $this->input('name'))
                ->when($current, fn ($q) => $q->where('id', '!=', $current->id))
                ->exists();

            if ($taken) {
                $validator->errors()->add('name', __('restaurant.station_name_taken'));
            }
        }];
    }
}
