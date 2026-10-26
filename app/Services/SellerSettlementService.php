<?php

namespace App\Services;

use App\Exceptions\SettlementRuleException;
use App\Models\Artist;
use App\Models\Event;
use App\Models\Concerns\DataModeScope;
use App\Models\SellerSettlement;
use App\Models\SellerSettlementAdjustment;
use App\Models\SellerSettlementAcknowledgement;
use App\Models\SellerSettlementPayment;
use App\Models\SellerSettlementSource;
use App\Models\User;
use App\Support\ReportSplit;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * 076-settlement-tab-session-branding — SATU-SATUNYA penulis tabel
 * seller_settlements*. Setiap perubahan (draf, sumber, penyesuaian, finalisasi,
 * buka kembali, pengakuan, pembayaran) lewat sini supaya tiga hal selalu
 * terjadi bersama dalam SATU transaksi: (1) perubahan barisnya, (2) total &
 * cache yang diturunkan, (3) baris activity_logs. Jalur lain yang menulis
 * tabel-tabel ini adalah cacat (Constitution I).
 *
 * Pembayaran settlement SENGAJA tidak lewat PaymentRecorder: `payments` adalah
 * buku kas penerimaan pelanggan (CHECK-nya mengikat tiap baris ke order /
 * pre-order / PO), sedangkan ini uang ke/dari seller — lihat plan.md,
 * "Complexity Tracking". Penulis tunggalnya tetap satu: kelas ini.
 *
 * Angka tidak pernah datang dari klien: sumber selalu dibentuk ulang oleh
 * SellerSettlementBuilder, penyesuaian hanya membawa jumlah yang diketik
 * pengguna dan tandanya ditentukan di sini.
 */
class SellerSettlementService
{
    public function __construct(
        private SellerSettlementBuilder $builder,
        private ActivityLogger $activityLogger,
    ) {}

    // ------------------------------------------------------------------
    // Draf
    // ------------------------------------------------------------------

    public function create(int $eventId, int $artistId, User $user): SellerSettlement
    {
        // findOrFail lewat model ber-scope data mode: id dari mode lain → 404
        // (FormRequest `exists:` melewati scope, jadi ini yang menjaganya).
        $event = Event::findOrFail($eventId);
        $artist = Artist::findOrFail($artistId);

        return DB::transaction(function () use ($event, $artist, $user) {
            $existing = SellerSettlement::where('event_id', $event->id)->where('artist_id', $artist->id)->first();
            if ($existing) {
                throw SettlementRuleException::make('settlement_exists', ['existing_id' => $existing->id]);
            }

            try {
                $settlement = SellerSettlement::create([
                    'event_id' => $event->id,
                    'artist_id' => $artist->id,
                    'event_name' => $event->name,
                    'artist_name' => $artist->name,
                    'artist_code' => $artist->code,
                    'status' => SellerSettlement::STATUS_DRAFT,
                    'active_slot' => 1,
                    'created_by' => $user->id,
                ]);
            } catch (QueryException $e) {
                // Dua permintaan serentak: indeks UNIQUE(artist, event, active_slot)
                // yang memutuskan pemenangnya; yang kalah mendapat 409 yang sama.
                if (($e->errorInfo[1] ?? null) === 1062) {
                    $winner = SellerSettlement::where('event_id', $event->id)->where('artist_id', $artist->id)->first();
                    throw SettlementRuleException::make('settlement_exists', ['existing_id' => $winner?->id]);
                }
                throw $e;
            }

            $this->applyBuild($settlement);

            $this->log($user, 'created', $settlement, 'created', newValues: $this->snapshot($settlement));

            return $settlement;
        });
    }

    /** Segarkan sumber dari data hidup — hanya untuk draf. */
    public function refresh(SellerSettlement $settlement, User $user): SellerSettlement
    {
        return DB::transaction(function () use ($settlement, $user) {
            $locked = $this->lock($settlement);
            $this->assertDraft($locked);

            $old = $this->snapshot($locked);
            $this->applyBuild($locked);

            $this->log($user, 'refreshed', $locked, 'refreshed', $old, $this->snapshot($locked));

            return $locked;
        });
    }

    public function delete(SellerSettlement $settlement, User $user): void
    {
        DB::transaction(function () use ($settlement, $user) {
            $locked = $this->lock($settlement);
            $this->assertDraft($locked);

            // Draf yang pernah difinalkan (sudah bernomor, mungkin sudah dicetak / diakui) tidak boleh
            // dihapus: nomor akan bebas dipakai ulang dan berkas tanda tangan lama menjadi yatim.
            if ($locked->number !== null) {
                throw SettlementRuleException::make('was_finalized');
            }

            $snapshot = $this->snapshot($locked);
            $this->log($user, 'deleted', $locked, 'deleted', oldValues: $snapshot);

            // Hard delete: slot aktif ikut bebas; anak-anaknya cascade.
            $locked->delete();
        });
    }

    // ------------------------------------------------------------------
    // Potongan & penyesuaian
    // ------------------------------------------------------------------

    /**
     * @param  array{kind: string, label: string, reason: string, basis?: ?string, amount: string|int|float}  $data
     *                                                                                                                `amount` untuk potongan diketik POSITIF; disimpan negatif.
     */
    public function addAdjustment(SellerSettlement $settlement, array $data, User $user): SellerSettlement
    {
        return DB::transaction(function () use ($settlement, $data, $user) {
            $locked = $this->lock($settlement);

            // Setelah difinalkan hanya PENYESUAIAN yang boleh ditambah (potongan hanya di draf);
            // angka beku tidak berubah, hanya net yang bergeser — dan seller perlu mengakui ulang.
            if ($locked->isFinalized()) {
                if ($data['kind'] !== 'adjustment') {
                    throw SettlementRuleException::make('not_draft');
                }
                $this->guardPostFinalizationAdjustment($locked, $data);
            }

            $postFinalization = $locked->isFinalized();
            $adjustment = $locked->adjustments()->create($this->adjustmentAttributes($data, $user, $postFinalization));
            $this->syncAdjustmentTotals($locked);

            if ($postFinalization) {
                $this->supersedeCurrentAcknowledgement($locked, 'adjusted');
            }

            $this->log($user, 'adjustment_added', $locked, 'adjustment_added', newValues: $adjustment->only(['kind', 'label', 'amount']), params: $this->adjustmentParams($adjustment));

            return $locked;
        });
    }

    public function updateAdjustment(SellerSettlement $settlement, SellerSettlementAdjustment $adjustment, array $data, User $user): SellerSettlement
    {
        return DB::transaction(function () use ($settlement, $adjustment, $data, $user) {
            $locked = $this->lock($settlement);
            $this->assertDraft($locked);
            $row = $this->ownedAdjustment($locked, $adjustment);

            $old = $row->only(['kind', 'label', 'amount']);
            $row->update($this->adjustmentAttributes($data, $user, false, forUpdate: true));
            $this->syncAdjustmentTotals($locked);

            $this->log($user, 'adjustment_updated', $locked, 'adjustment_updated', $old, $row->only(['kind', 'label', 'amount']), $this->adjustmentParams($row));

            return $locked;
        });
    }

    public function removeAdjustment(SellerSettlement $settlement, SellerSettlementAdjustment $adjustment, User $user): SellerSettlement
    {
        return DB::transaction(function () use ($settlement, $adjustment, $user) {
            $locked = $this->lock($settlement);
            $this->assertDraft($locked);
            $row = $this->ownedAdjustment($locked, $adjustment);

            $old = $row->only(['kind', 'label', 'amount']);
            $params = $this->adjustmentParams($row);
            $row->delete();
            $this->syncAdjustmentTotals($locked);

            $this->log($user, 'adjustment_removed', $locked, 'adjustment_removed', $old, null, $params);

            return $locked;
        });
    }

    // ------------------------------------------------------------------
    // Finalisasi & buka kembali
    // ------------------------------------------------------------------

    public function finalize(SellerSettlement $settlement, User $user): SellerSettlement
    {
        return DB::transaction(function () use ($settlement, $user) {
            $locked = $this->lock($settlement);
            $this->assertDraft($locked);

            // Sumber diambil ulang tepat sebelum dibekukan. Total penyesuaian
            // SENGAJA tidak dihitung ulang di sini: ia dipelihara oleh
            // setiap perubahan penyesuaian, dan rekonsiliasi di bawah
            // membuktikan ia masih sama dengan jumlah barisnya.
            $this->applyBuild($locked);
            $locked->load(['sources', 'adjustments', 'payments']);

            if ($locked->sources->isEmpty() && $locked->adjustments->isEmpty()) {
                throw SettlementRuleException::make('nothing_to_settle');
            }

            $reconciliation = SettlementReconciliation::for($locked);
            if (! $reconciliation['balanced']) {
                throw SettlementRuleException::make(
                    'reconciliation_mismatch',
                    ['figure' => $reconciliation['failing']],
                    ['figure' => $reconciliation['failing']],
                );
            }

            $old = $this->snapshot($locked);
            $this->freeze($locked, $user);
            $locked->refresh();

            $this->log($user, 'finalized', $locked, 'finalized', $old, $this->snapshot($locked));

            return $locked;
        });
    }

    /**
     * Kembali ke draf: hanya bila belum ada pembayaran. Pengakuan yang
     * berlaku digantikan (alasan `reopened`) karena angkanya bisa berubah.
     * Nomor yang sudah diberikan DIPERTAHANKAN (dokumen yang sudah dicetak
     * tetap merujuk nomor yang sama bila difinalkan ulang).
     */
    public function reopen(SellerSettlement $settlement, User $user): SellerSettlement
    {
        return DB::transaction(function () use ($settlement, $user) {
            $locked = $this->lock($settlement);
            $this->assertFinalized($locked);

            if ($locked->payments()->exists()) {
                throw SettlementRuleException::make('has_payments');
            }

            $old = $this->snapshot($locked);
            $this->supersedeCurrentAcknowledgement($locked, 'reopened');
            $locked->update(['status' => SellerSettlement::STATUS_DRAFT, 'finalized_at' => null, 'finalized_by' => null]);

            $this->log($user, 'reopened', $locked, 'reopened', $old, $this->snapshot($locked));

            return $locked->refresh();
        });
    }

    // ------------------------------------------------------------------
    // Pengakuan seller
    // ------------------------------------------------------------------

    /**
     * Catat pengakuan seller (tanda tangan gambar / nama diketik / dicatat di
     * luar sistem). Append-only: yang berlaku digantikan (alasan `replaced`),
     * tidak ada baris maupun berkas yang dihapus. Mengakui TIDAK mengubah angka.
     *
     * @param  array{method: string, signer_name?: ?string, note?: ?string}  $data
     */
    public function acknowledge(SellerSettlement $settlement, array $data, ?UploadedFile $signature, User $user): SellerSettlement
    {
        $storedPath = null;

        try {
            return DB::transaction(function () use ($settlement, $data, $signature, $user, &$storedPath) {
                $locked = $this->lock($settlement);
                $this->assertFinalized($locked);

                if ($data['method'] === 'signature') {
                    $storedPath = $this->storeUpload($signature, 'settlement-signatures', ['image/png' => 'png'], 'signature');
                }

                $this->supersedeCurrentAcknowledgement($locked, 'replaced');
                $locked->acknowledgements()->create([
                    'method' => $data['method'],
                    'signer_name' => $data['signer_name'] ?? null,
                    'signature_path' => $storedPath,
                    'note' => $data['note'] ?? null,
                    'acknowledged_at' => now(),
                    'recorded_by' => $user->id,
                ]);

                $this->log($user, 'acknowledged', $locked, 'acknowledged', newValues: ['method' => $data['method'], 'signer_name' => $data['signer_name'] ?? null]);

                return $locked;
            });
        } catch (\Throwable $e) {
            $this->deleteFile($storedPath); // transaksi batal → berkas yatim tidak dibiarkan
            throw $e;
        }
    }

    // ------------------------------------------------------------------
    // Pembayaran
    // ------------------------------------------------------------------

    /**
     * Catat satu pembayaran. Idempoten lewat `client_ref`: referensi yang sama
     * pada settlement yang sama memutar ulang hasil lama (tanpa baris baru),
     * pada settlement lain ditolak. Jumlah tidak boleh melebihi sisa; cache
     * paid_amount ditulis ulang dari baris pembayaran dalam transaksi yang sama.
     *
     * @param  array{client_ref: string, amount: string|int|float, paid_at: string, method: string, reference?: ?string, notes?: ?string}  $data
     * @return array{0: SellerSettlement, 1: bool} [settlement, replayed]
     */
    public function addPayment(SellerSettlement $settlement, array $data, ?UploadedFile $proof, User $user): array
    {
        $storedPath = null;

        try {
            return DB::transaction(function () use ($settlement, $data, $proof, $user, &$storedPath) {
                $locked = $this->lock($settlement);

                $existing = SellerSettlementPayment::where('client_ref', $data['client_ref'])->first();
                if ($existing) {
                    if ($existing->settlement_id !== $locked->id) {
                        throw ValidationException::withMessages(['client_ref' => __('settlements.client_ref_taken')]);
                    }

                    return [$locked, true];
                }

                $this->assertFinalized($locked);
                if ($locked->netCents() === 0) {
                    throw SettlementRuleException::make('nothing_to_pay');
                }

                $balance = $locked->balanceCents();
                if ($balance <= 0) {
                    throw SettlementRuleException::make('already_settled');
                }

                $cents = ReportSplit::cents($data['amount']);
                if ($cents > $balance) {
                    throw ValidationException::withMessages([
                        'amount' => __('settlements.amount_exceeds_balance', ['max' => $this->formatRupiah($balance)]),
                    ]);
                }

                if ($proof) {
                    $storedPath = $this->storeUpload($proof, 'settlement-proofs', ['image/png' => 'png', 'image/jpeg' => 'jpg'], 'proof');
                }

                $payment = $locked->payments()->create([
                    'client_ref' => $data['client_ref'],
                    'amount' => ReportSplit::money($cents),
                    'paid_at' => $data['paid_at'],
                    'method' => $data['method'],
                    'reference' => $data['reference'] ?? null,
                    'notes' => $data['notes'] ?? null,
                    'proof_path' => $storedPath,
                    'recorded_by' => $user->id,
                ]);
                $this->syncPaid($locked);

                $this->log($user, 'payment_added', $locked, 'payment_added', newValues: $payment->only(['amount', 'paid_at', 'method', 'reference']));

                return [$locked, false];
            });
        } catch (QueryException $e) {
            $this->deleteFile($storedPath);
            // Dua settlement berbeda memakai UUID yang sama serentak: UNIQUE(client_ref) yang memutuskan.
            if (($e->errorInfo[1] ?? null) === 1062) {
                throw ValidationException::withMessages(['client_ref' => __('settlements.client_ref_taken')]);
            }
            throw $e;
        } catch (\Throwable $e) {
            $this->deleteFile($storedPath);
            throw $e;
        }
    }

    public function deletePayment(SellerSettlement $settlement, SellerSettlementPayment $payment, User $user): SellerSettlement
    {
        $proofPath = null;

        $result = DB::transaction(function () use ($settlement, $payment, $user, &$proofPath) {
            $locked = $this->lock($settlement);
            $this->assertFinalized($locked);
            $row = $locked->payments()->findOrFail($payment->id);

            $old = $row->only(['amount', 'paid_at', 'method', 'reference']);
            $proofPath = $row->proof_path;
            $row->delete();
            $this->syncPaid($locked);

            $this->log($user, 'payment_removed', $locked, 'payment_removed', $old);

            return $locked;
        });

        // Berkas bukti dihapus SESUDAH commit: bila transaksi batal, buktinya tetap ada.
        $this->deleteFile($proofPath);

        return $result;
    }

    // ------------------------------------------------------------------
    // Pembacaan turunan
    // ------------------------------------------------------------------

    /**
     * Isyarat "sumber berubah" (hanya untuk settlement yang sudah final):
     * bandingkan pendapatan hidup dengan yang dibekukan. Dihitung saat
     * dibaca, tidak pernah disimpan, dan tidak pernah mengubah angka beku.
     *
     * @return ?array{pos_delta: string, preorder_delta: string, as_of: string}
     */
    public function sourceChanged(SellerSettlement $settlement): ?array
    {
        if (! $settlement->isFinalized()) {
            return null;
        }

        $live = $this->builder->build($settlement->event_id, $settlement->artist_id)['totals'];
        $pos = ReportSplit::cents($live['pos_revenue']) - ReportSplit::cents($settlement->pos_revenue);
        $pre = ReportSplit::cents($live['preorder_revenue']) - ReportSplit::cents($settlement->preorder_revenue);

        if ($pos === 0 && $pre === 0) {
            return null;
        }

        return [
            'pos_delta' => ReportSplit::money($pos),
            'preorder_delta' => ReportSplit::money($pre),
            'as_of' => now()->toIso8601String(),
        ];
    }

    /** Relasi yang dibaca payload detail — dimuat sekali di sini (bukan N+1, bukan jebakan relationLoaded). */
    public function loadDetail(SellerSettlement $settlement): SellerSettlement
    {
        return $settlement->load([
            'event', 'artist', 'sources', 'adjustments.creator',
            'currentAcknowledgement.recorder', 'acknowledgements.recorder', 'payments.recorder',
        ]);
    }

    // ------------------------------------------------------------------
    // Internal
    // ------------------------------------------------------------------

    /** Baris dikunci & dibaca ulang di dalam transaksi (scope data mode tetap berlaku). */
    private function lock(SellerSettlement $settlement): SellerSettlement
    {
        return SellerSettlement::query()->lockForUpdate()->findOrFail($settlement->id);
    }

    private function assertDraft(SellerSettlement $settlement): void
    {
        if (! $settlement->isDraft()) {
            throw SettlementRuleException::make('not_draft');
        }
    }

    private function assertFinalized(SellerSettlement $settlement): void
    {
        if (! $settlement->isFinalized()) {
            throw SettlementRuleException::make('not_finalized');
        }
    }

    /**
     * Ganti baris sumber dengan hasil builder dan tulis ulang total pendapatan,
     * memo, dan net dalam SATU UPDATE (CHECK konsistensi dievaluasi per
     * statement). total penyesuaian & paid_amount tidak disentuh.
     */
    private function applyBuild(SellerSettlement $settlement): void
    {
        $built = $this->builder->build($settlement->event_id, $settlement->artist_id);

        SellerSettlementSource::where('settlement_id', $settlement->id)->delete();
        if ($built['sources'] !== []) {
            SellerSettlementSource::insert(array_map(
                fn (array $row) => ['settlement_id' => $settlement->id] + $row,
                $built['sources'],
            ));
        }

        $totals = $built['totals'];
        $net = ReportSplit::cents($totals['revenue_total']) + ReportSplit::cents($settlement->adjustments_total);

        $settlement->update($totals + ['net_amount' => ReportSplit::money($net)]);
    }

    /** Total penyesuaian & net dari BARIS penyesuaian, dalam satu UPDATE. */
    private function syncAdjustmentTotals(SellerSettlement $settlement): void
    {
        $sum = $settlement->adjustments()->get()->sum(fn ($row) => ReportSplit::cents($row->amount));
        $net = ReportSplit::cents($settlement->revenue_total) + $sum;

        $settlement->update([
            'adjustments_total' => ReportSplit::money($sum),
            'net_amount' => ReportSplit::money($net),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function adjustmentAttributes(array $data, User $user, bool $postFinalization, bool $forUpdate = false): array
    {
        $cents = abs(ReportSplit::cents($data['amount']));
        // Potongan selalu mengurangi; penyesuaian membawa tandanya sendiri.
        $signed = $data['kind'] === 'deduction' ? -$cents : ReportSplit::cents($data['amount']);

        return [
            'kind' => $data['kind'],
            'label' => $data['label'],
            'reason' => $data['reason'],
            'basis' => $data['basis'] ?? null,
            'amount' => ReportSplit::money($signed),
        ] + ($forUpdate ? [] : ['post_finalization' => $postFinalization, 'created_by' => $user->id]);
    }

    private function ownedAdjustment(SellerSettlement $settlement, SellerSettlementAdjustment $adjustment): SellerSettlementAdjustment
    {
        return $settlement->adjustments()->findOrFail($adjustment->id);
    }

    private function supersedeCurrentAcknowledgement(SellerSettlement $settlement, string $reason): void
    {
        $settlement->acknowledgements()->whereNull('superseded_at')->update([
            'superseded_at' => now(),
            'superseded_reason' => $reason,
        ]);
    }

    /** Beri nomor (sekali, dipertahankan), tandai final — satu UPDATE (chk_sset_final). */
    private function freeze(SellerSettlement $settlement, User $user): void
    {
        $attempts = 0;

        while (true) {
            try {
                $settlement->update([
                    'number' => $settlement->number ?? $this->nextNumber(),
                    'status' => SellerSettlement::STATUS_FINALIZED,
                    'finalized_at' => now(),
                    'finalized_by' => $user->id,
                ]);

                return;
            } catch (QueryException $e) {
                // Dua finalisasi serentak pada hari yang sama bisa menghitung nomor sama (1062) atau saling
                // mengunci rentang (1213 deadlock): yang kalah menghitung ulang (maks 3x).
                if (! in_array($e->errorInfo[1] ?? null, [1062, 1213], true) || ++$attempts >= 3) {
                    throw $e;
                }
                $settlement->number = null;
            }
        }
    }

    /**
     * STL-YYYYMMDD-NNNN. Urutan dihitung lintas KEDUA mode data (nomor unik
     * se-database, bukan per mode — pola PreorderService::generateNumber()).
     * Akhiran "-DM" milik hasil salin DEMO tidak mengganggu: hanya empat digit
     * pertama setelah tanggal yang dibaca.
     */
    private function nextNumber(): string
    {
        $prefix = 'STL-'.now()->format('Ymd').'-';

        $last = SellerSettlement::withoutGlobalScope(DataModeScope::class)
            ->where('number', 'like', $prefix.'%')
            ->lockForUpdate()
            ->orderByDesc('number')
            ->value('number');

        $sequence = ($last && preg_match('/^STL-\d{8}-(\d{4})/', $last, $m)) ? ((int) $m[1]) + 1 : 1;

        return sprintf('%s%04d', $prefix, $sequence);
    }

    /** paid_amount (cache) = jumlah BARIS pembayaran, dalam satu UPDATE; CHECK menjaga batas atasnya. */
    private function syncPaid(SellerSettlement $settlement): void
    {
        $sum = $settlement->payments()->get()->sum(fn ($row) => ReportSplit::cents($row->amount));

        $settlement->update(['paid_amount' => ReportSplit::money($sum)]);
    }

    /**
     * Penyesuaian setelah final tidak boleh membalik arah pembayaran yang sudah
     * berjalan, atau membuat total lebih kecil dari yang sudah dibayar.
     *
     * @param  array<string, mixed>  $data
     */
    private function guardPostFinalizationAdjustment(SellerSettlement $settlement, array $data): void
    {
        $paid = $settlement->paidCents();
        if ($paid <= 0) {
            return; // belum ada uang bergerak: arah boleh berubah
        }

        $oldNet = $settlement->netCents();
        $newNet = $oldNet + ReportSplit::cents($data['amount']);

        if (($newNet <=> 0) !== ($oldNet <=> 0)) {
            throw SettlementRuleException::make('direction_locked');
        }
        if (abs($newNet) < $paid) {
            throw SettlementRuleException::make('net_below_paid');
        }
    }

    /**
     * Simpan berkas unggahan di disk PRIVAT dengan nama acak. Tipe dibaca dari
     * isi berkas (bukan ekstensi/nama klien) sebagai lapis kedua setelah rule
     * `mimes` pada request.
     *
     * @param  array<string, string>  $mimeToExtension
     */
    private function storeUpload(UploadedFile $file, string $directory, array $mimeToExtension, string $field): string
    {
        $mime = $file->getMimeType();
        if (! isset($mimeToExtension[$mime])) {
            throw ValidationException::withMessages([$field => __('orders_payments.unsupported_file_type')]);
        }

        $path = $file->storeAs($directory, Str::uuid()->toString().'.'.$mimeToExtension[$mime], 'local');
        if ($path === false) {
            throw new \RuntimeException('Gagal menyimpan berkas settlement.');
        }

        return $path;
    }

    private function deleteFile(?string $path): void
    {
        if ($path) {
            Storage::disk('local')->delete($path);
        }
    }

    private function formatRupiah(int $cents): string
    {
        return 'Rp '.number_format($cents / 100, $cents % 100 === 0 ? 0 : 2, ',', '.');
    }

    /** @return array<string, mixed> */
    private function snapshot(SellerSettlement $settlement): array
    {
        return $settlement->only([
            'number', 'status', 'pos_revenue', 'preorder_revenue', 'revenue_total',
            'adjustments_total', 'net_amount', 'paid_amount',
        ]);
    }

    /** @return array<string, string> */
    private function adjustmentParams(SellerSettlementAdjustment $adjustment): array
    {
        return ['kind' => __("settlements.kind_{$adjustment->kind}"), 'label' => $adjustment->label];
    }

    private function log(
        User $user,
        string $action,
        SellerSettlement $settlement,
        string $messageKey,
        ?array $oldValues = null,
        ?array $newValues = null,
        array $params = [],
    ): void {
        $this->activityLogger->log(
            userId: $user->id,
            action: $action,
            entityType: 'SellerSettlement',
            entityId: $settlement->id,
            description: __("settlements.{$messageKey}", $params + [
                'seller' => $settlement->artist_name,
                'event' => $settlement->event_name,
                'number' => $settlement->number ?? '-',
            ]),
            oldValues: $oldValues,
            newValues: $newValues,
        );
    }
}
