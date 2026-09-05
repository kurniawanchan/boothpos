<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * 018-license-activation — dikirim oleh GenerateLicense (CLI vendor-side,
 * research.md R5) saja. Mencerminkan pola Mailable lain di kodebase ini
 * (mis. PreorderStatusMail) — HTML sederhana, bukan lampiran.
 */
class LicenseKeyMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $issuedTo,
        public string $licenseKey,
    ) {}

    public function build(): self
    {
        return $this->subject('Kunci Lisensi BoothPOS Anda')
            ->view('emails.license-key')
            ->with([
                'issuedTo' => $this->issuedTo,
                'licenseKey' => $this->licenseKey,
            ]);
    }
}
