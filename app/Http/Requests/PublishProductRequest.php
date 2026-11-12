<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Customer commerce (CONTRACT §6) — POST /products/{product}/publish.
 *
 * Gerbang aksi `products.publish` sudah dijalankan middleware `ability` di
 * rute; pemeriksaan policy di sini adalah lapis kedua yang sama dengan
 * UpdateProductRequest (hanya mempersempit, tidak pernah melebarkan).
 */
class PublishProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('product')) ?? false;
    }

    public function rules(): array
    {
        return [
            // true = sekaligus menerbitkan varian aktif yang belum terbit. Bawaan false.
            'with_variants' => ['sometimes', 'boolean'],
        ];
    }
}
