<?php

namespace App\Http\Requests;

use App\Models\Category;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Category::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'code' => [
                'required',
                'string',
                'size:2',
                'alpha',
                Rule::unique('categories', 'code')->whereNull('deleted_at'),
            ],
            'name' => ['required', 'string', 'max:100'],
            'parent_id' => ['nullable', 'integer', Rule::exists('categories', 'id')->whereNull('deleted_at')],
            'display_order' => ['sometimes', 'integer'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Kategori baru belum punya id, jadi siklus hanya mungkin terjadi bila
     * parent_id yang dipilih ternyata sudah berada dalam rantai leluhur
     * yang menuju ke dirinya sendiri — secara praktis ini tidak mungkin
     * untuk kategori BARU (belum ada anak). Pemeriksaan tetap disertakan
     * untuk konsistensi dan jaga-jaga bila logika ini kelak dipakai ulang
     * di alur import massal.
     */
    public function withValidator(ValidatorContract $validator): void
    {
        $validator->after(function ($validator) {
            $parentId = $this->input('parent_id');

            if ($parentId && Category::wouldCreateCycle(null, (int) $parentId)) {
                $validator->errors()->add('parent_id', 'Kategori induk yang dipilih akan membentuk siklus.');
            }
        });
    }
}
