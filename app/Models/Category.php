<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Category extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'code',
        'name',
        'parent_id',
        'display_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'display_order' => 'integer',
        ];
    }

    protected function code(): Attribute
    {
        return Attribute::make(
            set: fn (string $value) => strtoupper($value),
        );
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id');
    }

    /**
     * BUG YANG DITEMUKAN & DIPERBAIKI — sama seperti Artist::products():
     * CategoryController::index()/show() memanggil withCount('products')/
     * loadCount('products') tapi relasinya tidak pernah ditulis, membuat
     * kedua endpoint itu fatal error 500.
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /**
     * Menelusuri rantai leluhur dari $candidateParentId ke atas. Bila
     * $categoryId ditemukan di rantai tersebut, berarti menjadikan
     * $candidateParentId sebagai induk akan membentuk siklus.
     *
     * Dipanggil SEBELUM menyimpan, bukan lewat foreign key — karena FK
     * RESTRICT di skema tidak mendeteksi siklus, hanya mendeteksi
     * penghapusan baris yang masih direferensikan.
     */
    public static function wouldCreateCycle(?int $categoryId, ?int $candidateParentId): bool
    {
        if ($candidateParentId === null) {
            return false;
        }

        if ($categoryId !== null && $candidateParentId === $categoryId) {
            return true; // Kategori tidak boleh menjadi induk dirinya sendiri
        }

        $visited = [];
        $currentId = $candidateParentId;

        // Batas 50 lompatan sebagai pengaman terhadap data yang sudah
        // korup akibat bug lain, agar tidak infinite loop.
        for ($i = 0; $i < 50 && $currentId !== null; $i++) {
            if (in_array($currentId, $visited, true)) {
                break; // siklus tak terduga di data lama, hentikan penelusuran
            }
            $visited[] = $currentId;

            if ($categoryId !== null && $currentId === $categoryId) {
                return true;
            }

            $currentId = static::where('id', $currentId)->value('parent_id');
        }

        return false;
    }
}
