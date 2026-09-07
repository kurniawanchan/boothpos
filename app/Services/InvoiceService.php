<?php

namespace App\Services;

use App\Models\Concerns\DataModeScope;
use App\Models\Invoice;
use Illuminate\Validation\ValidationException;

/**
 * 019-billing-system — transisi status invoice, mencerminkan pola
 * transition-guard PurchaseOrderService/PreorderService::
 * transitionStatus() (research.md R1). Hanya `unpaid` yang boleh
 * bertransisi; `paid`/`cancelled` bersifat terminal.
 */
class InvoiceService
{
    public function markPaid(Invoice $invoice): Invoice
    {
        if ($invoice->status !== 'unpaid') {
            throw ValidationException::withMessages([
                'status' => __('invoices.invoice_invalid_transition', ['status' => $invoice->status]),
            ]);
        }

        $invoice->update(['status' => 'paid', 'paid_at' => now()]);

        return $invoice->fresh();
    }

    public function cancel(Invoice $invoice): Invoice
    {
        if ($invoice->status !== 'unpaid') {
            throw ValidationException::withMessages([
                'status' => __('invoices.invoice_invalid_transition', ['status' => $invoice->status]),
            ]);
        }

        $invoice->update(['status' => 'cancelled']);

        return $invoice->fresh();
    }

    /**
     * 019-billing-system (research.md R3') — mirip persis
     * PreorderService::generateNumber(): format `INV-{YYYYMM}-{seq}`,
     * dicek lintas DEMO/LIVE via withoutGlobalScope(DataModeScope::class)
     * karena `invoice_number` punya UNIQUE constraint database-wide
     * sementara Invoice sendiri tetap ter-scope HasDataMode. Public
     * (bukan private) karena InvoiceExportImportService::import() (Phase 6)
     * perlu jalur penomoran yang sama persis untuk baris yang dibuat dari
     * import, bukan implementasi kedua yang bisa mencar dari yang ini.
     *
     * BUG YANG DITEMUKAN & DIPERBAIKI (019-billing-system, second
     * expansion) — terverifikasi lewat verifikasi manual browser
     * sungguhan: hitungan sebelumnya HANYA menghitung invoice yang masih
     * ada (SoftDeletes menyaring baris ter-hapus dari query default),
     * padahal UNIQUE constraint di level database TIDAK ikut terhapus
     * saat soft delete — nilai invoice_number lama tetap "menempel" di
     * baris yang di-soft-delete. Akibatnya, invoice yang baru dibuat bisa
     * mendapat nomor yang sudah dipakai (lalu dihapus) sebelumnya,
     * menyebabkan 1062 Duplicate entry saat insert. Diperbaiki dengan
     * withTrashed() supaya hitungan ikut memperhitungkan baris yang sudah
     * di-soft-delete, sama seperti constraint database-nya sendiri.
     */
    public function generateNumber(): string
    {
        $month = now()->format('Ym');
        $countThisMonth = Invoice::withoutGlobalScope(DataModeScope::class)
            ->withTrashed()
            ->where('invoice_number', 'like', "INV-{$month}-%")
            ->count();

        return sprintf('INV-%s-%04d', $month, $countThisMonth + 1);
    }

    /**
     * FR-011 — invoice yang sudah `paid` adalah catatan keuangan final dan
     * tidak boleh dihapus lewat jalur manapun (single-delete maupun
     * import/update, lihat data-model.md). Dilempar sebagai
     * ValidationException supaya controller bisa memetakannya ke 409,
     * konsisten dengan konvensi conflict di seluruh kodebase ini.
     */
    public function delete(Invoice $invoice): void
    {
        if ($invoice->status === 'paid') {
            throw ValidationException::withMessages([
                'status' => __('invoices.delete_paid_blocked'),
            ]);
        }

        $invoice->delete();
    }

    /**
     * FR-013 — `grand_total` SELALU dihitung ulang di server dari
     * subtotal/discount yang tervalidasi, tidak pernah dipercaya dari
     * client, sama seperti seluruh perhitungan uang lain di kodebase ini.
     * Invoice yang sudah `paid` bersifat final (mirror guard delete()).
     */
    public function update(Invoice $invoice, array $data): Invoice
    {
        if ($invoice->status === 'paid') {
            throw ValidationException::withMessages([
                'status' => __('invoices.update_paid_blocked'),
            ]);
        }

        $subtotal = (float) $data['subtotal'];
        $discount = (float) ($data['discount'] ?? 0);

        $invoice->update([
            'company_id' => $data['company_id'],
            'license_id' => $data['license_id'],
            'subtotal' => $subtotal,
            'discount' => $discount,
            'grand_total' => $subtotal - $discount,
            'due_date' => $data['due_date'],
            'payment_information' => $data['payment_information'] ?? $invoice->payment_information,
            'notes' => $data['notes'] ?? null,
        ]);

        return $invoice->fresh();
    }
}
