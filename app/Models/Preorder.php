<?php

namespace App\Models;

use App\Models\Concerns\HasDataMode;
use App\Models\Concerns\HasSellerScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Arr;

class Preorder extends Model
{
    use HasDataMode, HasSellerScope;

    protected $fillable = [
        'preorder_number', 'event_id', 'customer_id', 'user_id', 'status', 'dispatch_status', 'invoice_sent_at', 'shipping_at', 'fulfillment',
        'subtotal', 'shipping_cost', 'discount', 'total_amount', 'paid_amount', 'expected_date',
        'pickup_day', 'courier_name', 'cancel_reason', 'notes',
        'source_preorder_id', 'source_type', 'source_preorder_number',
    ];

    /**
     * Cermin default kolom DB. Tanpa ini, model hasil create() (mis. respons
     * POST /preorders) membawa dispatch_status = null sampai di-refresh,
     * karena Eloquent tidak membaca balik default sisi database.
     */
    protected $attributes = ['dispatch_status' => 'pending'];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2', 'shipping_cost' => 'decimal:2', 'discount' => 'decimal:2',
            'total_amount' => 'decimal:2', 'paid_amount' => 'decimal:2',
            'expected_date' => 'date', 'pickup_day' => 'date',
            'invoice_sent_at' => 'datetime', 'shipping_at' => 'datetime',
        ];
    }

    public function items(): HasMany { return $this->hasMany(PreorderItem::class); }
    public function payments(): HasMany { return $this->hasMany(Payment::class); }
    public function event(): BelongsTo { return $this->belongsTo(Event::class); }
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function shipment(): HasOne { return $this->hasOne(Shipment::class); }
    public function notifications(): HasMany { return $this->hasMany(PreorderNotification::class); }

    /** 027 — pre-order asal untuk salinan/hasil pisah (null bila sumbernya sudah dihapus). */
    public function sourcePreorder(): BelongsTo { return $this->belongsTo(self::class, 'source_preorder_id'); }

    /** 027 — pre-order baru yang dipisahkan DARI pre-order ini. */
    public function splitChildren(): HasMany
    {
        return $this->hasMany(self::class, 'source_preorder_id')->where('source_type', 'split')->orderBy('id');
    }

    /**
     * 007-preorder-import-export-notify (US4) — dipakai PreorderController
     * ::show() untuk field `latest_notification` tanpa request terpisah.
     */
    public function latestNotification(): ?PreorderNotification
    {
        return $this->relationLoaded('notifications')
            ? $this->notifications->sortByDesc('sent_at')->first()
            : $this->notifications()->orderByDesc('sent_at')->first();
    }

    /**
     * State machine sesuai uml-pos-mvp.md bagian 6.1. Urutan linear ketat
     * kecuali 'cancelled' yang bisa dicapai dari beberapa status.
     */
    private const ALLOWED_TRANSITIONS = [
        'ordered' => ['dp_paid', 'cancelled'],
        'dp_paid' => ['arrived', 'cancelled'],
        'arrived' => ['settled', 'cancelled'],
        'settled' => ['handed_over'],
        'handed_over' => [],
        'cancelled' => [],
    ];

    /** Nilai sah `dispatch_status` — satu sumber untuk validasi request & filter. */
    public const DISPATCH_STATUSES = ['pending', 'invoice_sent', 'shipping'];

    /** Nilai sah filter `shipping_flag` — satu sumber untuk scope dan dokumentasi. */
    public const SHIPPING_FLAGS = ['no_cost', 'no_address'];

    /**
     * Kunci filter daftar pre-order — SATU-SATUNYA daftar yang dipercaya
     * pemanggil (list, summary, neighbors, matching-ids, aksi massal, ekspor)
     * saat meneruskan filter ke scopeMatchingListFilters().
     */
    public const LIST_FILTER_KEYS = [
        'status', 'dispatch_status', 'event_id', 'customer_id', 'fulfillment', 'search', 'artist_id', 'category_id', 'shipping_flag',
    ];

    /**
     * 066 — satu-satunya rantai filter daftar pre-order. Dulu hidup di
     * PreorderController::applyFilters() DAN disalin tangan (versi lebih
     * sempit: pencarian hanya nama pelanggan, tanpa artist_id) di
     * PreorderExportImportService::export(), sehingga ekspor bisa berisi
     * baris yang berbeda dari layar. Jangan menambah rantai filter kedua di
     * mana pun — tambahkan di sini.
     *
     * Semantik: AND antar-kunci, OR dalam satu kunci (whereIn). Nilai kosong
     * ('' / null / array kosong) dibuang, bukan dianggap "cocok dengan
     * tidak ada". Nilai tunggal lama (status=a) tetap diterima lewat Arr::wrap.
     *
     * @param  array<string, mixed>  $filters
     */
    public function scopeMatchingListFilters(Builder $query, array $filters): Builder
    {
        $list = fn (string $key): array => array_values(array_filter(
            Arr::wrap($filters[$key] ?? []),
            fn ($v) => $v !== null && $v !== '' && is_scalar($v),
        ));
        $scalar = function (string $key) use ($filters) {
            $v = $filters[$key] ?? null;

            return is_scalar($v) && trim((string) $v) !== '' ? $v : null;
        };

        return $query
            ->when($list('status') !== [], fn ($q) => $q->whereIn('status', $list('status')))
            // Penanda manual invoice-terkirim/pengiriman-berjalan — pola array yang sama.
            ->when($list('dispatch_status') !== [], fn ($q) => $q->whereIn('dispatch_status', $list('dispatch_status')))
            ->when($scalar('event_id') !== null, fn ($q) => $q->where('event_id', (int) $scalar('event_id')))
            // 066 — array (customer_id[]=1&customer_id[]=2, di-OR-kan) seperti status;
            // nilai tunggal lama tetap berlaku lewat Arr::wrap.
            ->when($list('customer_id') !== [], fn ($q) => $q->whereIn('customer_id', array_map('intval', $list('customer_id'))))
            ->when($list('fulfillment') !== [], fn ($q) => $q->whereIn('fulfillment', $list('fulfillment')))
            // 007 (US1) — parsial, tidak peka huruf besar/kecil, terhadap nomor
            // pre-order, nama pelanggan, nama/SKU item di dalamnya, dan sisa
            // tagihan (digit saja, supaya "135.000"/"Rp 135.000" tetap cocok:
            // `outstanding` bukan kolom, jadi dihitung total_amount − paid_amount
            // lalu di-cast ke teks agar LIKE bekerja seperti kolom lain).
            ->when($scalar('search') !== null, function ($q) use ($scalar) {
                $term = (string) $scalar('search');
                $digitsOnly = preg_replace('/\D+/', '', $term);
                $q->where(function ($qq) use ($term, $digitsOnly) {
                    $qq->where('preorder_number', 'like', "%{$term}%")
                        ->orWhereHas('customer', fn ($cq) => $cq->where('name', 'like', "%{$term}%"))
                        ->orWhereHas('items', fn ($iq) => $iq->where('name_snapshot', 'like', "%{$term}%")
                            ->orWhere('sku_snapshot', 'like', "%{$term}%"));

                    if ($digitsOnly !== '') {
                        $qq->orWhereRaw('CAST((total_amount - paid_amount) AS CHAR) LIKE ?', ["%{$digitsOnly}%"]);
                    }
                });
            })
            // 013 — whereHas (WHERE EXISTS), BUKAN join: satu pre-order dengan
            // beberapa item dari penjual yang sama tidak pernah terduplikasi.
            ->when($list('artist_id') !== [], fn ($q) => $q->whereHas(
                'items',
                fn ($iq) => $iq->whereIn('artist_id', $list('artist_id'))
            ))
            // 066 — bendera "Mail Order tanpa ongkir / tanpa alamat" yang sudah ada di layar
            // (PreordersView::needsShippingAttention) jadi bisa disaring. Hanya berlaku untuk
            // fulfillment=courier (ambil sendiri tidak butuh keduanya). `no_cost` = ongkir ≤ 0;
            // `no_address` = alamat pelanggan kosong/spasi saja (padanan filled() di layar). Dua
            // penyebab di-OR-kan; nilai tak dikenal dibuang (bukan "cocok dengan tidak ada").
            ->when(array_intersect($list('shipping_flag'), self::SHIPPING_FLAGS) !== [], function ($q) use ($list) {
                $flags = array_intersect($list('shipping_flag'), self::SHIPPING_FLAGS);
                $q->where('fulfillment', 'courier')->where(function ($qq) use ($flags) {
                    if (in_array('no_cost', $flags, true)) {
                        $qq->orWhere('shipping_cost', '<=', 0);
                    }
                    if (in_array('no_address', $flags, true)) {
                        $qq->orWhereHas('customer', fn ($cq) => $cq->where(
                            fn ($c) => $c->whereNull('address')->orWhereRaw("TRIM(address) = ''")
                        ));
                    }
                });
            })
            // 066 (US2) — kategori produk. preorder_items tidak punya kolom kategori,
            // jadi dicapai lewat varian → produk (kategori SAAT INI, bukan snapshot).
            // EXISTS bertingkat, BUKAN join: pre-order dengan beberapa item sekategori
            // tetap satu baris, dan ia dikembalikan UTUH (semua itemnya, bukan hanya
            // yang cocok). withTrashed() hanya mengangkat soft-delete supaya riwayat
            // varian/produk yang sudah dihapus tetap bisa ditemukan; DataModeScope
            // (DEMO/LIVE) dan SellerScope pada model bertingkat itu TETAP berlaku.
            ->when($list('category_id') !== [], fn ($q) => $q->whereHas(
                'items',
                fn ($iq) => $iq->whereHas(
                    'variant',
                    fn ($vq) => $vq->withTrashed()->whereHas(
                        'product',
                        fn ($pq) => $pq->withTrashed()->whereIn('category_id', array_map('intval', $list('category_id')))
                    )
                )
            ));
    }

    public function canTransitionTo(string $newStatus): bool
    {
        return in_array($newStatus, self::ALLOWED_TRANSITIONS[$this->status] ?? [], true);
    }

    /**
     * 013-preorder-list-filters-receipt (T004) — daftar penjual unik
     * (id, name) lintas item, urut kemunculan pertama. Null-safe: item dengan
     * artist_id yang tidak resolve (penjual di-soft-delete / referensi
     * menggantung) dilewati, tidak crash. 066: dipindah dari controller supaya
     * list dan ekspor memakai definisi yang sama; pemanggil wajib memuat
     * `items.artist` (tanpa withTrashed — sama dengan list) agar tidak N+1.
     *
     * @return array<int, array{id: int, name: string}>
     */
    public function sellers(): array
    {
        $sellers = [];

        foreach ($this->items as $item) {
            $artist = $item->artist;

            if (! $artist || isset($sellers[$artist->id])) {
                continue;
            }

            $sellers[$artist->id] = ['id' => $artist->id, 'name' => $artist->name];
        }

        return array_values($sellers);
    }

    /** 028 — ringkasan pembayaran turunan (lihat App\Support\PaymentSummary). */
    public function paymentSummary(): array
    {
        return \App\Support\PaymentSummary::for($this);
    }

    public function outstanding(): float
    {
        return round((float) $this->total_amount - (float) $this->paid_amount, 2);
    }
}
