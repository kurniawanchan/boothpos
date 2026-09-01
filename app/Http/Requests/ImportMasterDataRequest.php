<?php

namespace App\Http\Requests;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ImportMasterDataRequest extends FormRequest
{
    public const MAX_KILOBYTES = 10240; // 10 MB

    /**
     * Digerbang di FormRequest, bukan lewat Policy per entitas, dan bukan
     * isOwnerOrAdmin().
     *
     * - FormRequest: satu berkas impor menyentuh empat entitas sekaligus,
     *   jadi tidak ada satu model tunggal yang bisa dijadikan sasaran
     *   Policy. Presedennya sudah ada: StockAdjustmentRequest — mutasi
     *   massal lain di kodebase ini — digerbang persis dengan cara yang
     *   sama.
     * - canManageMasterData() (owner/admin/inventory), bukan
     *   isOwnerOrAdmin(): impor melakukan hal yang sama dengan yang sudah
     *   boleh dilakukan ketiga peran itu lewat layar CRUD dan penyesuaian
     *   stok. Menaikkannya ke owner/admin justru akan membuat peran
     *   inventory boleh menyesuaikan stok satu per satu tapi tidak boleh
     *   melakukannya massal — tidak konsisten tanpa alasan.
     */
    public function authorize(): bool
    {
        return $this->user()?->canManageMasterData() ?? false;
    }

    /**
     * Tanpa override ini, FormRequest membalas "This action is
     * unauthorized." — satu-satunya pesan berbahasa Inggris di antara tiga
     * endpoint impor/ekspor yang bersebelahan (GET /exports/{entity} dan
     * GET /imports/master-data/template sudah membalas dalam bahasa
     * Indonesia). Polanya meniru StoreArtistRequest::failedAuthorization().
     *
     * CATATAN: StockAdjustmentRequest punya kekurangan yang sama dan
     * SENGAJA tidak ikut diubah di sini — itu di luar cakupan perubahan
     * ini, dicatat supaya tidak dikira terlewat.
     */
    protected function failedAuthorization(): void
    {
        throw new AuthorizationException(
            'Hanya owner/admin/inventory yang dapat mengimpor data master.'
        );
    }

    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                Rule::file()->max(self::MAX_KILOBYTES)->rules(['mimes:xlsx']),
            ],
            // F15.4 — pratinjau validasi dulu, simpan belakangan. Memakai
            // jalur validasi yang sama persis dengan impor sungguhan,
            // supaya pratinjau tidak bisa berbeda hasil dari penerapannya.
            'dry_run' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'file.mimes' => 'Berkas impor harus berformat .xlsx.',
            'file.max' => 'Ukuran berkas impor maksimal 10 MB.',
        ];
    }
}
