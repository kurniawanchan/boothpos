<?php

namespace App\Services;

use App\Models\CashierSession;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentProof;
use App\Models\Preorder;
use App\Models\User;
use App\Support\PaymentSummary;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * 028-partial-split-payment (research Decision 1) — SATU tempat yang mencatat
 * dan menghapus pembayaran untuk pre-order DAN penjualan POS. Penulis baris
 * `payments` tetap hanya PaymentRecorder; service ini yang menjaga semua aturan
 * di sekelilingnya: baris target dikunci, status tertutup ditolak, cache
 * paid_amount dihitung ulang dari entri, transisi status pre-order dijalankan,
 * dan jejak audit ditulis DI DALAM transaksi yang sama.
 */
class PaymentService
{
    public function __construct(
        private PaymentRecorder $paymentRecorder,
        private ActivityLogger $activityLogger,
    ) {}

    /**
     * Mencatat satu pembayaran dan mengembalikan target (pre-order/order) yang
     * sudah dimuat ulang lengkap dengan relasi yang dibaca presenter-nya.
     *
     * Urutan pemeriksaan (semuanya SETELAH baris target dikunci, jadi dua
     * permintaan bersamaan tak bisa sama-sama lolos dengan sisa yang basi):
     *  1. `client_ref` sudah ada pada transaksi INI → replay (idempotensi), tak
     *     ada pembayaran baru. Dicek paling awal supaya coba-ulang tetap dijawab
     *     walau transaksi sudah lunas/tertutup karena pembayaran asli tadi.
     *     `client_ref` milik transaksi lain → 422.
     *  2. Transaksi tertutup → 409.   3. Sudah lunas → 409.
     *  4. Jumlah > sisa tagihan → 422 menyebut maksimum yang bisa dibayar.
     *
     * @param  bool|null  $replayed  diisi true bila ini replay (tak ada baris baru)
     */
    public function addPayment(Preorder|Order $target, array $input, ?User $user = null, ?bool &$replayed = null): Preorder|Order
    {
        $replayed = false;

        return DB::transaction(function () use ($target, $input, $user, &$replayed) {
            $locked = $this->lock($target);

            if (! empty($input['client_ref'])) {
                // Tanpa global scope: unique index berlaku lintas mode DEMO/LIVE,
                // jadi kunci milik mode lain pun harus dikenali (sebagai konflik).
                $existing = Payment::withoutGlobalScopes()->where('client_ref', $input['client_ref'])->first();

                if ($existing) {
                    $sameTarget = $locked instanceof Order
                        ? (int) $existing->order_id === (int) $locked->id
                        : (int) $existing->preorder_id === (int) $locked->id;

                    if (! $sameTarget) {
                        throw ValidationException::withMessages(['client_ref' => __('orders_payments.payment_client_ref_conflict')]);
                    }

                    $replayed = true;

                    return $this->reload($locked);
                }
            }

            $this->assertOpen($locked);

            $before = PaymentSummary::for($locked->load('payments'));
            $remaining = (float) $before['remaining'];

            if ($remaining <= 0) {
                throw ValidationException::withMessages(['status' => __('orders_payments.payment_already_fully_paid')])->status(409);
            }
            if (round((float) $input['amount'], 2) > $remaining) {
                throw ValidationException::withMessages([
                    'amount' => __('orders_payments.payment_exceeds_balance', ['max' => 'Rp '.number_format($remaining, 0, ',', '.')]),
                ]);
            }

            $payment = $this->paymentRecorder->record(
                array_merge($input, [
                    'recorded_by' => $user?->id,
                    'session_id' => $this->receivingSessionId($locked, $input['method'], $user),
                ]),
                $locked instanceof Order ? $locked->id : null,
                $locked instanceof Preorder ? $locked->id : null,
            );

            $summary = PaymentSummary::for($locked->unsetRelation('payments')->load('payments'));
            $this->syncCache($locked, $summary);

            $this->activityLogger->log(
                userId: $user?->id,
                action: 'payment_recorded',
                entityType: $locked instanceof Order ? 'Order' : 'Preorder',
                entityId: $locked->id,
                description: sprintf(
                    'Mencatat pembayaran %s Rp %s pada %s',
                    $payment->method, number_format((float) $payment->amount, 0, ',', '.'), $this->numberOf($locked),
                ),
                newValues: [
                    'payment_id' => $payment->id,
                    'amount' => (float) $payment->amount,
                    'method' => $payment->method,
                    'reference' => $payment->reference,
                    'status' => $summary['status'],
                    'total_paid' => $summary['total_paid'],
                    'remaining' => $summary['remaining'],
                ],
            );

            return $this->reload($locked);
        });
    }

    /**
     * Menghapus SATU pembayaran (beserta bukti bayarnya) — jalur koreksi yang
     * sengaja dibatasi (owner/admin, dijaga controller) dan SELALU diaudit.
     * Entri pembayaran tidak punya jalur ubah; ini satu-satunya cara
     * mengeluarkannya. paid_amount dan status dihitung ULANG dari entri yang
     * tersisa; status pre-order mundur sejauh yang ditimbulkan pembayaran itu
     * (settled yang tak lagi lunas → arrived; dp_paid tanpa sisa pembayaran →
     * ordered; arrived tak pernah mundur ke ordered karena stok sudah masuk).
     */
    public function deletePayment(Preorder|Order $target, Payment $payment, User $user): Preorder|Order
    {
        $filesToDelete = [];

        $result = DB::transaction(function () use ($target, $payment, $user, &$filesToDelete) {
            $locked = $this->lock($target);

            $this->assertOpen($locked);

            // Pembayaran milik transaksi lain → 404, bukan dihapus diam-diam.
            $entry = $locked->payments()->whereKey($payment->id)->firstOrFail();

            // Uang tunai milik shift yang SUDAH ditutup sudah direkonsiliasi
            // (expected_cash tersimpan, tak bisa dihitung ulang) — menghapusnya
            // akan merusak laporan shift itu.
            if ($entry->method === 'cash' && $entry->session_id
                && CashierSession::whereKey($entry->session_id)->where('status', 'closed')->exists()) {
                throw ValidationException::withMessages(['status' => __('orders_payments.payment_delete_closed_shift')])->status(409);
            }

            $before = PaymentSummary::for($locked->load('payments'));
            $oldStatus = $locked instanceof Preorder ? $locked->status : $before['status'];

            $filesToDelete = $entry->proofs()->pluck('file_path')->all();
            $entry->proofs()->delete(); // payment_proofs.payment_id restrictOnDelete → baris bukti dulu
            $entry->delete();

            $summary = PaymentSummary::for($locked->unsetRelation('payments')->load('payments'));
            $locked->update(['paid_amount' => $this->cachedPaid($locked, $summary)]);

            $newStatus = $oldStatus;
            if ($locked instanceof Preorder) {
                if ($locked->status === 'settled' && $summary['status'] !== PaymentSummary::FULLY_PAID) {
                    $newStatus = 'arrived';
                } elseif ($locked->status === 'dp_paid' && $summary['payment_count'] === 0) {
                    $newStatus = 'ordered';
                }
                $locked->update(['status' => $newStatus]);
            } else {
                $newStatus = $summary['status'];
            }

            // Di dalam transaksi yang sama: menghapus catatan uang adalah tindakan sensitif.
            $this->activityLogger->log(
                userId: $user->id,
                action: 'payment_deleted',
                entityType: $locked instanceof Order ? 'Order' : 'Preorder',
                entityId: $locked->id,
                description: sprintf(
                    'Menghapus pembayaran %s Rp %s dari %s',
                    $entry->purpose, number_format((float) $entry->amount, 0, ',', '.'), $this->numberOf($locked),
                ),
                oldValues: [
                    'payment_id' => $entry->id,
                    'amount' => (float) $entry->amount,
                    'method' => $entry->method,
                    'reference' => $entry->reference,
                    'status' => $oldStatus,
                    'total_paid' => $before['total_paid'],
                    'paid_amount' => (float) $before['total_paid'],
                ],
                newValues: [
                    'status' => $newStatus,
                    'total_paid' => $summary['total_paid'],
                    'remaining' => $summary['remaining'],
                    'paid_amount' => (float) $summary['total_paid'],
                ],
            );

            return $this->reload($locked);
        });

        // File bukti dihapus SETELAH commit: transaksi yang gagal/rollback tidak
        // boleh meninggalkan baris bukti yang filenya sudah hilang.
        foreach ($filesToDelete as $path) {
            Storage::disk('local')->delete($path);
        }

        return $result;
    }

    /**
     * 031-optional-payment-proof (research Decision 2) — menambah / mengubah /
     * mengganti KONFIRMASI sebuah pembayaran non-tunai: bukti (foto/berkas),
     * nomor referensi, dan catatan. Bukan "ubah pembayaran" umum: jumlah, metode,
     * kanal, tujuan, waktu, shift, dan total/status transaksi TIDAK pernah ditulis
     * di sini (028 sengaja menutup jalur ubah itu); hanya keterangan deskriptif yang
     * bisa berubah.
     *
     * Urutan pemeriksaan (semuanya SETELAH baris target dikunci, seperti
     * addPayment/deletePayment):
     *  1. pembayaran milik transaksi ini (404 bila bukan);
     *  2. transaksi tidak batal: penjualan `voided` / pre-order `cancelled` → 409.
     *     Pre-order `handed_over` SENGAJA boleh — konfirmasi tak menggerakkan uang,
     *     dan bukti susulan justru lazim datang untuk pesanan yang sudah selesai;
     *  3. bukan tunai (422);   4. pelaku: owner/admin atau pencatat (403);
     *  5. minimal satu dari bukti/referensi/catatan dikirim (422);
     *  6. token bukti valid & belum terpakai (422);
     *  7. hasil akhirnya tak boleh kosong sama sekali (422) — hapus bukti secara
     *     mentah memang tidak ditawarkan, hanya menggantinya.
     * Mengganti bukti = bukti lama DITANDAI `superseded_at`, TIDAK dihapus (file dan
     * baris tetap ada untuk audit; activity log menunjuk ke keduanya).
     */
    public function updateConfirmation(Preorder|Order $target, Payment $payment, array $input, User $user): Preorder|Order
    {
        return DB::transaction(function () use ($target, $payment, $input, $user) {
            $locked = $this->lock($target);

            // Pembayaran milik transaksi lain → 404, bukan diubah diam-diam.
            $entry = $locked->payments()->with('proofs')->whereKey($payment->id)->firstOrFail();

            $closed = $locked instanceof Preorder ? $locked->status === 'cancelled' : $locked->status === 'voided';
            if ($closed) {
                throw ValidationException::withMessages(['status' => __('orders_payments.payment_confirmation_target_closed')])->status(409);
            }
            if ($entry->method === 'cash') {
                throw ValidationException::withMessages(['method' => __('orders_payments.payment_confirmation_cash')]);
            }
            // Pertahanan berlapis: controller sudah menjaga ini, tetapi aturan bisnis
            // tidak boleh bergantung pada pemanggil yang ingat memanggilnya.
            if (! $entry->mayManageConfirmation($user)) {
                throw ValidationException::withMessages(['confirmation' => __('orders_payments.payment_confirmation_not_allowed')])->status(403);
            }

            $token = $input['proof_token'] ?? null;
            $touchesReference = array_key_exists('reference', $input);
            $touchesNotes = array_key_exists('notes', $input);

            if (! $token && ! $touchesReference && ! $touchesNotes) {
                throw ValidationException::withMessages(['confirmation' => __('orders_payments.payment_confirmation_empty')]);
            }

            $proof = null;
            if ($token) {
                // Dikunci supaya dua permintaan serentak tak bisa memakai token yang sama.
                $proof = PaymentProof::where('proof_token', $token)->whereNull('payment_id')->lockForUpdate()->first();

                if (! $proof) {
                    throw ValidationException::withMessages(['proof_token' => __('orders_payments.proof_token_invalid')]);
                }
            }

            $current = $entry->currentProof();
            $newReference = $touchesReference ? $this->blankToNull($input['reference'] ?? null) : $entry->reference;
            $newNotes = $touchesNotes ? $this->blankToNull($input['notes'] ?? null) : $entry->notes;
            $newProofId = $proof?->id ?? $current?->id;

            if ($newReference === null && $newNotes === null && $newProofId === null) {
                throw ValidationException::withMessages(['confirmation' => __('orders_payments.payment_confirmation_empty')]);
            }

            if ($proof === null && $newReference === $entry->reference && $newNotes === $entry->notes) {
                return $this->reload($locked); // tak ada yang benar-benar berubah: tanpa tulis, tanpa log
            }

            $old = ['payment_id' => $entry->id, 'reference' => $entry->reference, 'notes' => $entry->notes, 'proof_id' => $current?->id];

            if ($proof) {
                $current?->update(['superseded_at' => now()]);
                $proof->update(['payment_id' => $entry->id]);
            }
            $entry->update(['reference' => $newReference, 'notes' => $newNotes]);

            $this->activityLogger->log(
                userId: $user->id,
                action: 'payment_confirmation_updated',
                entityType: $locked instanceof Order ? 'Order' : 'Preorder',
                entityId: $locked->id,
                description: sprintf(
                    'Mengubah konfirmasi pembayaran %s Rp %s pada %s%s',
                    $entry->method, number_format((float) $entry->amount, 0, ',', '.'), $this->numberOf($locked),
                    $proof && $current ? ' (bukti diganti)' : ($proof ? ' (bukti ditambahkan)' : ''),
                ),
                oldValues: $old,
                newValues: ['payment_id' => $entry->id, 'reference' => $newReference, 'notes' => $newNotes, 'proof_id' => $newProofId],
            );

            return $this->reload($locked);
        });
    }

    /** Teks kosong/spasi saja berarti "kosongkan" (NULL), sisanya di-trim. */
    private function blankToNull(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * Shift tempat uang ini DITERIMA (research Decision 7). Hanya untuk penjualan
     * POS: tunai wajib punya shift terbuka milik pencatat (409 bila tidak ada),
     * non-tunai mencatatnya bila ada. Pembayaran pre-order tak punya shift (null),
     * persis seperti sebelumnya.
     */
    private function receivingSessionId(Preorder|Order $target, string $method, ?User $user): ?int
    {
        if (! $target instanceof Order) {
            return null;
        }

        $session = $user
            ? CashierSession::where('user_id', $user->id)->where('status', 'open')->first()
            : null;

        if (! $session && $method === 'cash') {
            throw ValidationException::withMessages(['session_id' => __('orders_payments.payment_session_required')])->status(409);
        }

        return $session?->id;
    }

    /**
     * Nilai cache `paid_amount`: untuk pre-order = total terbayar; untuk ORDER
     * cache-nya menyimpan uang yang DISERAHKAN (jumlah entri), jadi kembalian
     * dikembalikan — pembaca lama (`paid_amount` ≥ `total_amount` untuk order
     * lunas dengan kembalian) tetap benar.
     */
    private function cachedPaid(Preorder|Order $target, array $summary): float
    {
        return (float) $summary['total_paid'] + ($target instanceof Order ? (float) $target->change_amount : 0.0);
    }

    private function lock(Preorder|Order $target): Preorder|Order
    {
        // Query ber-scope DEMO/LIVE: id milik mode lain tidak ditemukan.
        return $target::lockForUpdate()->findOrFail($target->id);
    }

    /** 409 — status tertutup membuat pembayaran tak bisa ditambah/dihapus lagi. */
    private function assertOpen(Preorder|Order $target): void
    {
        $closed = $target instanceof Preorder
            ? in_array($target->status, ['handed_over', 'cancelled'], true)
            : $target->status === 'voided';

        if ($closed) {
            throw ValidationException::withMessages(['status' => __('orders_payments.payment_target_closed')])->status(409);
        }
    }

    /**
     * Cache paid_amount ditulis ulang dari rumus yang sama (bukan ditambah),
     * dan lifecycle pre-order dijalankan: pembayaran pertama memindahkan
     * ordered → dp_paid; pembayaran yang melunasi pre-order yang sudah
     * "arrived" memindahkannya ke settled.
     */
    private function syncCache(Preorder|Order $target, array $summary): void
    {
        $target->update(['paid_amount' => $this->cachedPaid($target, $summary)]);

        if ($target instanceof Preorder) {
            if ($target->status === 'ordered') {
                $target->update(['status' => 'dp_paid']);
            } elseif ($target->status === 'arrived' && $summary['status'] === PaymentSummary::FULLY_PAID) {
                $target->update(['status' => 'settled']);
            }
        }
    }

    private function reload(Preorder|Order $target): Preorder|Order
    {
        return $target->fresh(
            $target instanceof Preorder
                ? PreorderService::PAYLOAD_RELATIONS
                : ['items', 'payments.channel', 'payments.recorder', 'payments.proofs', 'customer'],
        );
    }

    private function numberOf(Preorder|Order $target): string
    {
        return $target instanceof Preorder ? $target->preorder_number : $target->order_number;
    }
}
