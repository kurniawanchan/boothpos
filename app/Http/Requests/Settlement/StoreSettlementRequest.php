<?php

namespace App\Http\Requests\Settlement;

use Illuminate\Validation\Rule;

class StoreSettlementRequest extends SettlementRequest
{
    public function rules(): array
    {
        // `exists:` melewati scope data mode & soft delete Eloquent; pencocokan
        // mode yang sebenarnya dilakukan lagi lewat findOrFail() di service.
        return [
            'event_id' => ['required', 'integer', Rule::exists('events', 'id')->whereNull('deleted_at')],
            'artist_id' => ['required', 'integer', Rule::exists('artists', 'id')->whereNull('deleted_at')],
        ];
    }
}
