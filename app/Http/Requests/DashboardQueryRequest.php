<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 042-dashboard-revamp — parameter bersama endpoint Dashboard. Izin menu `dashboard` dicek di
 * controller (pola yang sama dengan endpoint lain yang memakai canAccessMenu inline).
 */
class DashboardQueryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'period' => ['sometimes', 'in:today,7d,30d'],
            'tab' => ['sometimes', 'in:all,open,partial,paid,cancelled'],
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'type' => ['sometimes', 'array'],
            'type.*' => ['in:retail,dine_in,takeaway'],
            'method' => ['sometimes', 'array'],
            'method.*' => ['in:cash,bank_transfer,qr_ewallet'],
            'pstat' => ['sometimes', 'array'],
            'pstat.*' => ['in:unpaid,pending,partial,paid'],
            'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'sort' => ['sometimes', 'in:created_at,-created_at,total_amount,-total_amount,order_number,-order_number'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
