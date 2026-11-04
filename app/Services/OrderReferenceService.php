<?php

namespace App\Services;

use App\Exceptions\OrderReferenceLimitException;
use App\Models\Order;
use App\Models\OrderReferenceImage;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * 054 (US8) — SATU-SATUNYA penulis referensi transaksi (catatan + gambar) pada penjualan POS.
 *
 * Referensi hanyalah bukti kerja ("foto WA dari pelanggan"), BUKAN catatan keuangan: tidak menyentuh
 * nominal, stok, status, atau laporan, dan boleh ditambahkan juga pada penjualan yang sudah dibatalkan.
 * Karena itu berkas yang dihapus benar-benar dihapus (tidak disimpan untuk audit seperti bukti
 * pembayaran); jejaknya ada di activity log.
 *
 * 055 — satu penjualan boleh punya sampai MAX_IMAGES gambar (tabel `order_reference_images`). Simpan bersifat
 * DELTA: klien mengirim gambar yang DITAMBAHKAN (`images[]`) dan id yang DIHAPUS (`remove_image_ids[]`), tidak
 * pernah daftar lengkap — supaya dua pengguna yang menyimpan dari tampilan lama tidak saling menghapus gambar.
 * Batas dihitung di bawah kunci baris order terhadap gambar yang benar-benar tersimpan. Tidak ada berkas
 * thumbnail: aplikasi berjalan di localhost, jadi gambar asli cukup ditampilkan kecil (lihat research 055 D5).
 *
 * Berkas: disk privat `local`, nama UUID acak (nama asli klien tak pernah dipakai), jenis dibaca dari ISI
 * berkas (bukan ekstensi). Berkas baru ditulis di dalam transaksi dan dibersihkan bila transaksi gagal;
 * berkas yang dihapus baru dihapus SETELAH commit, supaya rollback tak meninggalkan baris yang menunjuk
 * berkas hilang.
 */
class OrderReferenceService
{
    public const MAX_IMAGES = 10;

    public const MAX_IMAGE_KILOBYTES = 5120;

    private const DIRECTORY = 'order-references';

    private const MIME_EXTENSIONS = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    public function __construct(private ActivityLogger $activityLogger) {}

    /** Owner/admin atau kasir yang membuat penjualan ini — satu definisi, dipakai controller, service, dan presenter. */
    public static function mayEdit(Order $order, User $user): bool
    {
        return $user->isOwnerOrAdmin() || (int) $order->user_id === (int) $user->id;
    }

    /**
     * Blok referensi untuk SEMUA respons (endpoint referensi dan OrderResource) — satu presenter, supaya
     * kedua bentuk tak bisa melenceng. Memuat relasinya sendiri: pemanggil yang lupa eager-load mendapat
     * satu kueri tambahan, bukan field yang diam-diam hilang (jebakan relationLoaded di CLAUDE.md).
     */
    public static function present(Order $order, ?User $user): array
    {
        $order->loadMissing(['referenceImages', 'referenceEditor']);
        $images = $order->referenceImages;

        return [
            'note' => $order->reference_note,
            'images' => $images->map(fn (OrderReferenceImage $i) => [
                'id' => $i->id,
                'mime' => $i->mime,
                'size_bytes' => $i->size_bytes,
                'position' => $i->position,
                'created_at' => $i->created_at?->toIso8601String(),
            ])->values()->all(),
            'image_count' => $images->count(),
            'has_image' => $images->isNotEmpty(),
            'max_images' => self::MAX_IMAGES,
            'updated_at' => $order->reference_updated_at?->toIso8601String(),
            'updated_by_name' => $order->referenceEditor?->name,
            'can_edit' => $user !== null && self::mayEdit($order, $user),
        ];
    }

    /**
     * @param  array{note?:?string,remove_image_ids?:array}  $input  `note` hanya diubah bila kuncinya ADA ('' mengosongkan)
     * @param  list<UploadedFile>  $images  gambar yang DITAMBAHKAN, urut sesuai permintaan
     */
    public function save(Order $order, array $input, array $images, User $user): Order
    {
        if (! self::mayEdit($order, $user)) {
            throw ValidationException::withMessages(['reference' => __('orders_payments.reference_not_allowed')])->status(403);
        }

        $newPaths = [];
        $removedPaths = [];

        try {
            $result = DB::transaction(function () use ($order, $input, $images, $user, &$newPaths, &$removedPaths) {
                $locked = Order::lockForUpdate()->findOrFail($order->id);
                $stored = $locked->referenceImages()->get();

                $touchesNote = array_key_exists('note', $input);
                $note = $touchesNote ? $this->blankToNull($input['note'] ?? null) : $locked->reference_note;
                $requestedRemovals = array_map('intval', (array) ($input['remove_image_ids'] ?? []));

                // Permintaan tanpa catatan, gambar, maupun penghapusan tidak mengubah apa pun — dijawab 422
                // (bukan 200 diam-diam) supaya klien yang keliru kirim tak mengira berhasil.
                if (! $touchesNote && $images === [] && $requestedRemovals === []) {
                    throw ValidationException::withMessages(['reference' => __('orders_payments.reference_empty')]);
                }

                // Id milik penjualan lain atau yang sudah terhapus (pengguna lain) diabaikan tanpa galat.
                $toRemove = $stored->whereIn('id', $requestedRemovals);
                $kept = $stored->count() - $toRemove->count();

                if ($kept + count($images) > self::MAX_IMAGES) {
                    $remaining = max(0, self::MAX_IMAGES - $kept);
                    throw new OrderReferenceLimitException(
                        __('orders_payments.reference_image_limit', ['max' => self::MAX_IMAGES, 'remaining' => $remaining]),
                        self::MAX_IMAGES,
                        $remaining,
                    );
                }

                // Semua berkas diperiksa DULU sebelum satu pun ditulis — satu berkas buruk menggagalkan semuanya.
                $sniffed = [];
                foreach (array_values($images) as $index => $image) {
                    $mime = $image->getMimeType();
                    if (! isset(self::MIME_EXTENSIONS[$mime])) {
                        throw ValidationException::withMessages(["images.{$index}" => __('orders_payments.reference_image_invalid')]);
                    }
                    $sniffed[$index] = $mime;
                }

                if ($note === null && $kept === 0 && $images === []) {
                    // Nilai kosong sama sekali bukan "referensi"; menghapus semuanya adalah DELETE.
                    throw ValidationException::withMessages(['reference' => __('orders_payments.reference_empty')]);
                }

                $noteChanged = $note !== $locked->reference_note;
                if (! $noteChanged && $images === [] && $toRemove->isEmpty()) {
                    return $locked; // tak ada yang berubah: tanpa tulis, tanpa log
                }

                $position = (int) $stored->max('position');
                foreach (array_values($images) as $index => $image) {
                    $path = $image->storeAs(self::DIRECTORY, Str::uuid()->toString().'.'.self::MIME_EXTENSIONS[$sniffed[$index]], 'local');
                    $newPaths[] = $path;
                    $locked->referenceImages()->create([
                        'path' => $path,
                        'mime' => $sniffed[$index],
                        'size_bytes' => $image->getSize(),
                        'position' => ++$position, // celah bekas hapus tak pernah dinomori ulang
                        'uploaded_by' => $user->id,
                    ]);
                }

                if ($toRemove->isNotEmpty()) {
                    $removedPaths = $toRemove->pluck('path')->all();
                    OrderReferenceImage::whereKey($toRemove->pluck('id'))->delete();
                }

                $old = ['note' => $locked->reference_note, 'image_count' => $stored->count()];
                $locked->update([
                    'reference_note' => $note, 'reference_updated_by' => $user->id, 'reference_updated_at' => now(),
                ]);

                $this->activityLogger->log(
                    userId: $user->id,
                    action: 'order_reference_updated',
                    entityType: 'Order',
                    entityId: $locked->id,
                    description: sprintf('Mengubah referensi transaksi %s', $locked->order_number),
                    oldValues: $old,
                    newValues: [
                        'note' => $note,
                        'image_count' => $kept + count($images),
                        'images_added' => count($images),
                        'images_removed' => $toRemove->count(),
                    ],
                );

                return $locked;
            });
        } catch (\Throwable $e) {
            // Transaksi gagal: berkas baru yang sempat ditulis tak boleh tertinggal tanpa baris yang menunjuknya.
            if ($newPaths !== []) {
                Storage::disk('local')->delete($newPaths);
            }
            throw $e;
        }

        if ($removedPaths !== []) {
            Storage::disk('local')->delete($removedPaths);
        }

        return $result->fresh();
    }

    /** Menghapus catatan DAN semua gambar sekaligus. */
    public function delete(Order $order, User $user): Order
    {
        if (! self::mayEdit($order, $user)) {
            throw ValidationException::withMessages(['reference' => __('orders_payments.reference_not_allowed')])->status(403);
        }

        $oldPaths = [];
        $result = DB::transaction(function () use ($order, $user, &$oldPaths) {
            $locked = Order::lockForUpdate()->findOrFail($order->id);
            $stored = $locked->referenceImages()->get();

            if ($locked->reference_note === null && $stored->isEmpty()) {
                return $locked;
            }

            $oldPaths = $stored->pluck('path')->all();
            $old = ['note' => $locked->reference_note, 'image_count' => $stored->count()];

            $locked->referenceImages()->delete();
            $locked->update([
                'reference_note' => null, 'reference_updated_by' => $user->id, 'reference_updated_at' => now(),
            ]);

            $this->activityLogger->log(
                userId: $user->id,
                action: 'order_reference_removed',
                entityType: 'Order',
                entityId: $locked->id,
                description: sprintf('Menghapus referensi transaksi %s', $locked->order_number),
                oldValues: $old,
                newValues: ['note' => null, 'image_count' => 0],
            );

            return $locked;
        });

        if ($oldPaths !== []) {
            Storage::disk('local')->delete($oldPaths);
        }

        return $result->fresh();
    }

    private function blankToNull(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }
}
