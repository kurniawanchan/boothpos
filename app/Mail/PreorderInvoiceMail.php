<?php

namespace App\Mail;

use App\Models\Payment;
use App\Models\Preorder;
use App\Services\ImageUploadService;
use App\Support\BuildsInvoiceDocument;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * 022-preorder-invoice-crud-overhaul (US5, FR-014, research.md Decision 5)
 * — badan email berupa HTML kaya (ringkasan item, total, kanal pembayaran,
 * footer_text), TANPA lampiran PDF — mengikuti prinsip yang sudah berlaku
 * di seluruh kodebase ini: tidak ada rendering PDF sisi server sama
 * sekali (invoice/struk selalu dirender di klien via html2canvas+jsPDF).
 * Melampirkan PDF di sini berarti membuat TEMPLATE KEDUA untuk dokumen
 * yang sama, berisiko berbeda visual dari yang dilihat staf di layar —
 * dihindari secara sengaja.
 *
 * `documentType` membedakan invoice pesanan biasa dari invoice
 * pembayaran (per payment event) — untuk yang kedua, `$payment` WAJIB
 * diisi supaya badan email bisa menyorot "dibayar kali ini" seperti
 * PreorderPaymentReceiptModal.vue.
 */
class PreorderInvoiceMail extends Mailable
{
    use Queueable, SerializesModels, BuildsInvoiceDocument;

    public function __construct(
        public Preorder $preorder,
        public string $documentType = 'invoice',
        public ?Payment $payment = null,
    ) {
        $this->preorder->loadMissing(['items', 'customer', 'payments']);
    }

    public function build(): self
    {
        $imageUploadService = app(ImageUploadService::class);
        $fields = $this->buildInvoiceDocumentFields($imageUploadService);

        $subjectLabel = $this->documentType === 'payment_invoice'
            ? __('preorders.mail_subject_payment_invoice')
            : __('preorders.mail_subject_invoice');

        return $this->subject("{$subjectLabel} — {$this->preorder->preorder_number}")
            ->view('emails.preorder-invoice')
            ->with([
                ...$fields,
                'preorder' => $this->preorder,
                'documentType' => $this->documentType,
                'payment' => $this->payment,
            ]);
    }
}
