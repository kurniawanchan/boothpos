<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\DB;

/**
 * 079 — satu-satunya definisi aturan kode outlet: 3–5 huruf atau angka, unik di antara outlet yang belum
 * dihapus tanpa beda huruf. Dipakai buat dan ubah outlet; tidak ada salinan aturan di tempat lain.
 *
 * Keunikan dicek langsung ke tabel TANPA global scope: outlet DEMO dan LIVE berbagi satu indeks UNIQUE database
 * (`outlets.code_live`), jadi kode yang dipakai satu mode juga tertolak di mode lain. Outlet yang sedang diubah
 * dikecualikan agar menyimpan kodenya sendiri tidak dianggap duplikat.
 *
 * Normalisasi ke huruf besar bukan di sini, tapi di mutator `Outlet::code()` (satu tempat).
 */
class OutletCodeRule implements ValidationRule
{
    public const MIN = 3;

    public const MAX = 5;

    public function __construct(private ?int $ignoreOutletId = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! preg_match('/^[A-Za-z0-9]{'.self::MIN.','.self::MAX.'}$/', $value)) {
            $fail('master_data.outlet_code_rule')->translate();

            return;
        }

        $taken = DB::table('outlets')
            ->whereNull('deleted_at')
            ->where('code', $value)
            ->when($this->ignoreOutletId, fn ($q) => $q->where('id', '!=', $this->ignoreOutletId))
            ->exists();

        if ($taken) {
            $fail('master_data.outlet_code_taken')->translate();
        }
    }
}
