<?php

namespace App\Http\Requests;

use App\Models\Event;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Event::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'location' => ['nullable', 'string', 'max:200'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            // 023-event-availability-invoice-redesign (US1) — bentuk saja
            // yang dicek di sini; aturan lintas-field (tidak boleh diisi
            // untuk event satu hari) ada di withValidator() di bawah,
            // karena butuh start_date/end_date sekaligus.
            'available_on' => ['nullable', Rule::in(['day_1', 'day_2'])],
            'event_cost' => ['sometimes', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $start = $this->input('start_date');
            $end = $this->input('end_date');
            if ($this->filled('available_on') && $start && $end && $start === $end) {
                $validator->errors()->add('available_on', __('events_sessions.available_on_requires_multi_day'));
            }
        });
    }
}
