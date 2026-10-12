<?php

namespace App\Models;

use App\Models\Concerns\HasDataMode;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use HasDataMode, HasFactory, SoftDeletes;

    /**
     * BUG YANG DITEMUKAN & DIPERBAIKI — 'code_prefix' dan 'product_segment'
     * awalnya sengaja dikeluarkan dari $fillable dengan niat mencegah
     * mass-assignment dari input klien. Niatnya benar, tapi caranya salah:
     * ProductController::store() sendiri butuh menulis KEDUA kolom ini
     * lewat Product::create() (nilainya berasal dari ProductCodeGenerator,
     * bukan dari klien) — akibatnya INSERT selalu gagal 500 "code_prefix
     * doesn't have a default value" karena Eloquent diam-diam membuang
     * kedua field itu. Proteksi sesungguhnya terhadap input klien sudah
     * cukup di lapisan validasi (StoreProductRequest tidak punya rule
     * 'code_prefix' sama sekali, dan UpdateProductRequest sengaja tidak
     * menerima keduanya), jadi $fillable tidak perlu jadi lapis proteksi
     * kedua yang malah memblokir jalur internal yang sah.
     */
    protected $fillable = [
        'home_outlet_id',
        'category_id',
        'code_prefix',
        'product_segment',
        'name',
        'description',
        'image_path',
        'is_preorder',
        'preorder_eta',
        'is_active',
        'station_id',
        // 043 — fakta item. `published_at`/`discontinued_at` SENGAJA tidak di sini: hanya
        // ProductLifecycleService yang menulisnya (forceFill), tidak pernah dari input klien.
        'short_name',
        'tax_category',
        'prep_minutes',
        'is_retail',
    ];

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_DISCONTINUED = 'discontinued';

    /**
     * 043 — menjaga status turunan tetap selaras dengan `is_active` APA PUN penulisnya (form, impor,
     * layanan siklus hidup), sehingga tidak ada jalur yang bisa menghasilkan produk "aktif tapi draft":
     *  - dibuat aktif → langsung terbit; dibuat nonaktif → draft (belum terbit);
     *  - aktif → nonaktif → diskontinu (`discontinued_at`), nonaktif → aktif → terbit lagi (diskontinu dicabut).
     */
    protected static function booted(): void
    {
        static::creating(function (Product $p) {
            if ($p->published_at === null && $p->is_active) {
                $p->published_at = now();
            }
        });

        static::updating(function (Product $p) {
            if (! $p->isDirty('is_active')) {
                return;
            }
            if ($p->is_active) {
                $p->discontinued_at = null;
                $p->published_at ??= now();
            } elseif ($p->published_at !== null && $p->discontinued_at === null) {
                $p->discontinued_at = now();
            }
        });
    }

    protected function casts(): array
    {
        return [
            'is_preorder' => 'boolean',
            'is_active' => 'boolean',
            'preorder_eta' => 'date',
            'is_retail' => 'boolean',
            'prep_minutes' => 'integer',
            'published_at' => 'datetime',
            'discontinued_at' => 'datetime',
        ];
    }

    /**
     * 043 — status TURUNAN (research.md D5): draft = belum pernah diterbitkan; discontinued = punya
     * `discontinued_at`; selain itu active. `is_active` tetap penanda "bisa dipesan" dan dijaga
     * ProductLifecycleService agar selaras dengan status ini.
     */
    protected function status(): \Illuminate\Database\Eloquent\Casts\Attribute
    {
        return \Illuminate\Database\Eloquent\Casts\Attribute::get(fn () => match (true) {
            $this->discontinued_at !== null => self::STATUS_DISCONTINUED,
            $this->published_at === null => self::STATUS_DRAFT,
            default => self::STATUS_ACTIVE,
        });
    }

    /** 045 Stage 3 — baris relasi outlet × produk (dibawa atau tidak). */
    public function outletItemSettings(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(OutletItemSetting::class);
    }

    public function homeOutlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class, 'home_outlet_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    /** 028 — stasiun persiapan khusus produk ini (menang atas stasiun kategorinya). */
    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }

    /** 028 — grup modifier yang dilampirkan langsung ke sini (lihat ModifierResolver::effectiveGroups). */
    public function modifierGroups(): BelongsToMany
    {
        return $this->belongsToMany(ModifierGroup::class, 'modifier_group_product')
            ->withPivot(['has_rules', 'is_required', 'min_select', 'max_select', 'display_order']);
    }

    /** 043 — tanda 86 (semua, termasuk yang sudah berakhir; pakai `->active()` untuk yang berlaku). */
    public function availabilityMarks(): HasMany
    {
        return $this->hasMany(ItemAvailabilityMark::class);
    }

    public function recipes(): HasMany
    {
        return $this->hasMany(Recipe::class)->orderByDesc('version');
    }
}
