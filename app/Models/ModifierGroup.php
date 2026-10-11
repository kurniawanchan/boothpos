<?php

namespace App\Models;

use App\Models\Concerns\HasDataMode;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 028 US3 — grup modifier (mis. "Size", "Sugar", "Add-ons"). Aturan pilihan
 * (wajib/opsional, tunggal/jamak, min/max) disimpan di sini dan DITEGAKKAN oleh
 * ModifierResolver saat item ditambahkan ke tab.
 */
class ModifierGroup extends Model
{
    use HasDataMode, HasFactory;

    public const SINGLE = 'single';
    public const MULTIPLE = 'multiple';

    protected $fillable = ['name', 'selection_type', 'is_required', 'min_select', 'max_select', 'display_order', 'is_active'];

    // Default DB tidak terlihat pada model hasil create().
    protected $attributes = ['selection_type' => self::SINGLE, 'is_required' => false, 'min_select' => 0, 'max_select' => 1, 'display_order' => 0, 'is_active' => true];

    protected function casts(): array
    {
        return [
            'is_required' => 'boolean', 'is_active' => 'boolean',
            'min_select' => 'integer', 'max_select' => 'integer', 'display_order' => 'integer',
        ];
    }

    public function options(): HasMany
    {
        return $this->hasMany(ModifierOption::class)->orderBy('display_order')->orderBy('id');
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'modifier_group_product');
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'modifier_group_category');
    }
}
