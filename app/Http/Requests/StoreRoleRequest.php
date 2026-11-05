<?php

namespace App\Http\Requests;

use App\Models\Role;
use App\Support\MenuActions;
use App\Support\MenuKeys;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class StoreRoleRequest extends FormRequest
{
    use \App\Http\Requests\Concerns\ValidatesMenuActions;

    protected function effectiveMenuKeys(): array
    {
        return (array) $this->input('menu_keys', []);
    }

    public function withValidator($validator): void
    {
        $validator->after(fn ($v) => $this->validateMenuActions($v));
    }

    public function authorize(): bool
    {
        return $this->user()?->can('create', Role::class) ?? false;
    }

    protected function failedAuthorization(): void
    {
        $response = Gate::inspect('create', Role::class);

        throw new AuthorizationException($response->message() ?: 'Tidak berhak.');
    }

    public function rules(): array
    {
        return [
            'name' => [
                'required', 'string', 'max:50',
                Rule::unique('roles', 'name')->whereNull('deleted_at'),
            ],
            // Setiap nilai divalidasi terhadap App\Support\MenuKeys — kunci
            // tak dikenal DITOLAK (422), bukan didiamkan tersimpan tanpa
            // efek. Owner yang salah ketik kunci menu harus tahu saat itu
            // juga, bukan menemukan izin yang tidak pernah berfungsi.
            'menu_keys' => ['required', 'array'],
            'menu_keys.*' => [Rule::in(MenuKeys::keys())],
            // 057 — aksi TAMBAHAN per menu; diperiksa terhadap registri dan terhadap menu yang dimiliki peran.
            'menu_actions' => ['sometimes', 'nullable', 'array'],
            'menu_actions.*' => ['array'],
            'menu_actions.*.*' => ['string'],
        ];
    }

    public function messages(): array
    {
        return [
            'menu_keys.*.in' => __('roles.unknown_menu_key'),
        ];
    }
}
