<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 017-company-onboarding — mencerminkan persis App\Models\
 * PreorderNotification. Baris ini hanya ditulis sebagai efek samping
 * alur aktivasi (CompanyOnboardingService), tidak punya permukaan API
 * create/update sendiri.
 */
class CompanyActivationNotification extends Model
{
    protected $fillable = [
        'company_id', 'trigger', 'recipient_email',
        'status', 'error_message', 'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
