<?php

namespace App\Mail;

use App\Models\Company;
use App\Models\Setting;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * 017-company-onboarding — dikirim oleh CompanyOnboardingService saja,
 * mencerminkan App\Mail\PreorderStatusMail's pattern. $plainCode
 * SENGAJA diteruskan sebagai argumen terpisah (bukan dibaca dari
 * $company->activation_code_hash, yang memang tidak bisa dibalik) —
 * hanya service pemanggil yang pernah memegang kode plaintext-nya,
 * persis sebelum di-hash (research.md R2).
 */
class CompanyActivationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Company $company,
        public string $plainCode,
    ) {}

    public function build(): self
    {
        $storeName = Setting::get('store_name', 'BoothPOS');

        return $this->subject("Kode Aktivasi — {$this->company->name}")
            ->view('emails.company-activation')
            ->with([
                'storeName' => $storeName,
                'company' => $this->company,
                'code' => $this->plainCode,
            ]);
    }
}
