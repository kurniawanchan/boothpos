<?php

namespace App\Http\Requests\Settlement;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 076 — dasar semua request modul settlement: hanya pemegang menu
 * 'settlements' (Owner/Admin secara default). Penegakan sungguhan ada di
 * server; menyembunyikan menu di sidebar hanyalah kosmetik.
 */
abstract class SettlementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canAccessMenu('settlements') ?? false;
    }
}
