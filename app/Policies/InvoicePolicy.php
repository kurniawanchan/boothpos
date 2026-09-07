<?php

namespace App\Policies;

use App\Models\Invoice;
use App\Models\User;

/**
 * 019-billing-system (research.md R2') — Invoice sekarang jadi menu utama
 * tersendiri, tidak lagi menumpang gerbang 'companies'.
 */
class InvoicePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAccessMenu('invoices');
    }

    public function view(User $user, Invoice $invoice): bool
    {
        return $user->canAccessMenu('invoices');
    }

    public function create(User $user): bool
    {
        return $user->canAccessMenu('invoices');
    }

    public function update(User $user, Invoice $invoice): bool
    {
        return $user->canAccessMenu('invoices');
    }

    public function delete(User $user, Invoice $invoice): bool
    {
        return $user->canAccessMenu('invoices');
    }

    public function markPaid(User $user, Invoice $invoice): bool
    {
        return $user->canAccessMenu('invoices');
    }

    public function cancel(User $user, Invoice $invoice): bool
    {
        return $user->canAccessMenu('invoices');
    }
}
