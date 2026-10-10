<?php

namespace App\Http\Requests;

use App\Models\Category;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('category')) ?? false;
    }

    public function rules(): array
    {
        return [
            'code' => ['prohibited'],
            'name' => ['required', 'string', 'max:100'],
            'parent_id' => ['nullable', 'integer', Rule::exists('categories', 'id')->whereNull('deleted_at')],
            'display_order' => ['sometimes', 'integer'],
            'is_active' => ['sometimes', 'boolean'],
            'station_id' => ['sometimes', 'nullable', 'integer', function (string $attribute, mixed $value, \Closure $fail) {
                if ($value !== null && ! \App\Models\Station::whereKey($value)->exists()) {
                    $fail(__('restaurant.station_not_found'));
                }
            }],
        ];
    }

    public function messages(): array
    {
        return [
            'code.prohibited' => __('master_data.category_code_permanent'),
        ];
    }

    public function withValidator(ValidatorContract $validator): void
    {
        $validator->after(function ($validator) {
            $parentId = $this->input('parent_id');
            $categoryId = (int) $this->route('category')->id;

            if ($parentId && Category::wouldCreateCycle($categoryId, (int) $parentId)) {
                $validator->errors()->add(
                    'parent_id',
                    'Kategori induk yang dipilih akan membentuk siklus dengan kategori ini.'
                );
            }
        });
    }
}
