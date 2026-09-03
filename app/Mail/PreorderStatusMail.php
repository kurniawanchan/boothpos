<?php

namespace App\Mail;

use App\Models\Preorder;
use App\Models\Setting;
use App\Support\PreorderDocumentType;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * 007-preorder-import-export-notify (US4) — dikirim oleh PreorderNotifier
 * saja (research.md R2/R7). Isi berupa ringkasan HTML sederhana, BUKAN
 * lampiran PDF server-generated — research.md R2 secara eksplisit
 * memutuskan tidak ada jalur pembuatan PDF di server sama sekali di
 * kodebase ini (invoice/struk selalu dirender di klien). Badan email
 * karena itu menautkan kembali ke tampilan invoice/struk dalam aplikasi,
 * bukan melampirkannya.
 */
class PreorderStatusMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $documentType;
    public string $subjectTheme;

    public function __construct(public Preorder $preorder)
    {
        $this->preorder->loadMissing(['items', 'customer']);
        $this->documentType = PreorderDocumentType::forStatus($preorder->status);
        $this->subjectTheme = PreorderDocumentType::subjectThemeForStatus($preorder->status);
    }

    public function build(): self
    {
        $storeName = Setting::get('store_name', 'BoothPOS');

        return $this->subject("{$this->subjectTheme} — {$this->preorder->preorder_number}")
            ->view('emails.preorder-status')
            ->with([
                'storeName' => $storeName,
                'preorder' => $this->preorder,
                'documentType' => $this->documentType,
                'subjectTheme' => $this->subjectTheme,
            ]);
    }
}
