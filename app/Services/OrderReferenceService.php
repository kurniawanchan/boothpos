<?php

namespace App\Services;

use App\Models\Order;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * 054 (US8) — SATU-SATUNYA penulis referensi transaksi (catatan + satu gambar) pada penjualan POS.
 *
 * Referensi hanyalah bukti kerja ("foto WA dari pelanggan"), BUKAN catatan keuangan: tidak menyentuh
 * nominal, stok, status, atau laporan, dan boleh ditambahkan juga pada penjualan yang sudah dibatalkan.
 * Karena itu file lama yang diganti/dihapus benar-benar dihapus (tidak disimpan untuk audit seperti bukti
 * pembayaran); jejaknya ada di activity log.
 *
 * Berkas: disk privat `local`, nama UUID acak (nama asli klien tak pernah dipakai), jenis dibaca dari ISI
 * berkas (bukan ekstensi). Berkas baru ditulis di dalam transaksi dan dibersihkan bila transaksi gagal;
 * berkas lama baru dihapus SETELAH commit, supaya rollback tak meninggalkan baris yang menunjuk berkas hilang.
 */
class OrderReferenceService
{
    private const DIRECTORY = 'order-references';

    private const MIME_EXTENSIONS = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    public function __construct(private ActivityLogger $activityLogger) {}

    /** Owner/admin atau kasir yang membuat penjualan ini — satu definisi, dipakai controller, service, dan presenter. */
    public static function mayEdit(Order $order, User $user): bool
    {
        return $user->isOwnerOrAdmin() || (int) $order->user_id === (int) $user->id;
    }

    /**
     * @param  array{note?:?string,remove_image?:mixed}  $input  `note` hanya diubah bila kuncinya ADA
     *                                                          ('' mengosongkan); gambar bersifat tambah/ganti, atau hapus lewat `remove_image`
     */
    public function save(Order $order, array $input, ?UploadedFile $image, User $user): Order
    {
        if (! self::mayEdit($order, $user)) {
            throw ValidationException::withMessages(['reference' => __('orders_payments.reference_not_allowed')])->status(403);
        }

        $newPath = null;
        $oldPathToDelete = null;

        try {
            $result = DB::transaction(function () use ($order, $input, $image, $user, &$newPath, &$oldPathToDelete) {
                $locked = Order::lockForUpdate()->findOrFail($order->id);

                $touchesNote = array_key_exists('note', $input);
                $note = $touchesNote ? $this->blankToNull($input['note'] ?? null) : $locked->reference_note;
                $removeImage = filter_var($input['remove_image'] ?? false, FILTER_VALIDATE_BOOLEAN);

                // Permintaan tanpa catatan, gambar, maupun perintah hapus gambar tidak mengubah apa pun —
                // dijawab 422 (bukan 200 diam-diam) supaya klien yang keliru kirim tak mengira berhasil.
                if (! $touchesNote && $image === null && ! $removeImage) {
                    throw ValidationException::withMessages(['reference' => __('orders_payments.reference_empty')]);
                }

                $path = $locked->reference_image_path;
                $mime = $locked->reference_image_mime;

                if ($image !== null) {
                    $sniffed = $image->getMimeType();
                    if (! isset(self::MIME_EXTENSIONS[$sniffed])) {
                        throw ValidationException::withMessages(['image' => __('orders_payments.reference_image_invalid')]);
                    }
                    $newPath = $image->storeAs(self::DIRECTORY, Str::uuid()->toString().'.'.self::MIME_EXTENSIONS[$sniffed], 'local');
                    $oldPathToDelete = $path;
                    [$path, $mime] = [$newPath, $sniffed];
                } elseif ($removeImage && $path !== null) {
                    $oldPathToDelete = $path;
                    [$path, $mime] = [null, null];
                }

                if ($note === null && $path === null) {
                    // Nilai kosong sama sekali bukan "referensi"; menghapus semuanya adalah DELETE.
                    throw ValidationException::withMessages(['reference' => __('orders_payments.reference_empty')]);
                }

                if ($note === $locked->reference_note && $path === $locked->reference_image_path) {
                    return $locked; // tak ada yang berubah: tanpa tulis, tanpa log
                }

                $old = ['note' => $locked->reference_note, 'has_image' => $locked->reference_image_path !== null];

                $locked->update([
                    'reference_note' => $note, 'reference_image_path' => $path, 'reference_image_mime' => $mime,
                    'reference_updated_by' => $user->id, 'reference_updated_at' => now(),
                ]);

                $this->activityLogger->log(
                    userId: $user->id,
                    action: 'order_reference_updated',
                    entityType: 'Order',
                    entityId: $locked->id,
                    description: sprintf('Mengubah referensi transaksi %s', $locked->order_number),
                    oldValues: $old,
                    newValues: ['note' => $note, 'has_image' => $path !== null],
                );

                return $locked;
            });
        } catch (\Throwable $e) {
            // Transaksi gagal: berkas baru yang sempat ditulis tak boleh tertinggal tanpa baris yang menunjuknya.
            if ($newPath !== null) {
                Storage::disk('local')->delete($newPath);
            }
            throw $e;
        }

        if ($oldPathToDelete !== null && $oldPathToDelete !== $result->reference_image_path) {
            Storage::disk('local')->delete($oldPathToDelete);
        }

        return $result->fresh();
    }

    /** Menghapus catatan DAN gambar sekaligus. */
    public function delete(Order $order, User $user): Order
    {
        if (! self::mayEdit($order, $user)) {
            throw ValidationException::withMessages(['reference' => __('orders_payments.reference_not_allowed')])->status(403);
        }

        $oldPath = null;
        $result = DB::transaction(function () use ($order, $user, &$oldPath) {
            $locked = Order::lockForUpdate()->findOrFail($order->id);

            if (! $locked->hasReference()) {
                return $locked;
            }

            $oldPath = $locked->reference_image_path;
            $old = ['note' => $locked->reference_note, 'has_image' => $oldPath !== null];

            $locked->update([
                'reference_note' => null, 'reference_image_path' => null, 'reference_image_mime' => null,
                'reference_updated_by' => $user->id, 'reference_updated_at' => now(),
            ]);

            $this->activityLogger->log(
                userId: $user->id,
                action: 'order_reference_removed',
                entityType: 'Order',
                entityId: $locked->id,
                description: sprintf('Menghapus referensi transaksi %s', $locked->order_number),
                oldValues: $old,
                newValues: ['note' => null, 'has_image' => false],
            );

            return $locked;
        });

        if ($oldPath !== null) {
            Storage::disk('local')->delete($oldPath);
        }

        return $result->fresh();
    }

    private function blankToNull(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }
}
