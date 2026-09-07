<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * 017-company-onboarding — satu jalur sah untuk onboard/activate
 * company. Business logic-nya genuinely non-trivial (state machine,
 * pembuatan User owner) sehingga dipisahkan dari controller
 * (Constitution I), berbeda dari BusinessType/License yang cukup CRUD
 * controller-level (plan.md).
 *
 * 019-billing-system (third expansion, 2026-09-07) — aktivasi TIDAK LAGI
 * lewat kode 6 digit yang dikirim ke email klien. Desain lama ternyata
 * salah sasaran: endpoint /companies/{company}/activate hanya bisa
 * diakses staf owner/admin PENYEDIA (menu key 'companies'), padahal
 * kode itu dikirim ke email KLIEN — staf penyedia tidak punya jalan sah
 * untuk tahu kodenya sendiri. Diganti dengan syarat yang penyedia
 * MEMANG bisa verifikasi langsung: company punya minimal satu Invoice
 * berstatus 'paid' (research.md R14). Kolom activation_code_hash/
 * activation_code_expires_at di tabel companies dibiarkan ada (nullable,
 * tidak lagi diisi) — bukan dihapus lewat migrasi baru, supaya tidak
 * menambah risiko pada tabel yang sudah dipakai fitur 017 di produksi;
 * lihat research.md R14 untuk pertimbangan lengkapnya.
 */
class CompanyOnboardingService
{
    public function __construct(private ActivityLogger $activityLogger) {}

    /**
     * @param  array{business_type_id:int, license_id:int, name:string,
     *   address:?string, contact_name:string, contact_email:string,
     *   contact_phone:?string, owner_username:string, owner_password:string}  $data
     */
    public function onboard(array $data, User $actor): Company
    {
        // research.md R7 — role_id owner user TIDAK PERNAH dari input
        // klien, selalu di-resolve ke peran default sistem 'Owner'.
        $ownerRole = Role::where('name', 'Owner')->where('is_system_default', true)->firstOrFail();

        return DB::transaction(function () use ($data, $actor, $ownerRole) {
            $owner = User::create([
                'name' => $data['contact_name'],
                'username' => $data['owner_username'],
                'password' => Hash::make($data['owner_password']),
                'role_id' => $ownerRole->id,
                'is_active' => false,
            ]);

            $company = Company::create([
                'business_type_id' => $data['business_type_id'],
                'license_id' => $data['license_id'],
                'name' => $data['name'],
                'address' => $data['address'] ?? null,
                'contact_name' => $data['contact_name'],
                'contact_email' => $data['contact_email'],
                'contact_phone' => $data['contact_phone'] ?? null,
                'owner_user_id' => $owner->id,
                'status' => 'pending_activation',
            ]);

            $this->activityLogger->log(
                userId: $actor->id,
                action: 'created',
                entityType: 'Company',
                entityId: $company->id,
                description: "Onboarding company {$company->name}.",
                newValues: $company->only(['business_type_id', 'license_id', 'name', 'contact_name', 'contact_email', 'status']),
            );

            return $company;
        });
    }

    /**
     * 019-billing-system (third expansion, research.md R14) — pengganti
     * activate(Company, string $code) lama. Digerbang oleh keberadaan
     * Invoice 'paid' milik company ini, BUKAN kode yang diketik ulang —
     * staf penyedia (satu-satunya yang punya akses ke endpoint ini)
     * memang bisa memverifikasi status pembayaran invoice secara
     * langsung di sistem yang sama, berbeda dari kode yang cuma
     * terkirim ke email klien.
     */
    public function activate(Company $company): Company
    {
        if ($company->status === 'active') {
            throw ValidationException::withMessages([
                'status' => __('companies.activation_already_active'),
            ]);
        }

        if (! $company->invoices()->where('status', 'paid')->exists()) {
            throw ValidationException::withMessages([
                'status' => __('companies.activation_requires_paid_invoice'),
            ]);
        }

        return DB::transaction(function () use ($company) {
            $company->update([
                'status' => 'active',
                'activated_at' => now(),
            ]);

            $company->owner->update(['is_active' => true]);

            $this->activityLogger->log(
                userId: $company->owner_user_id,
                action: 'activated',
                entityType: 'Company',
                entityId: $company->id,
                description: "Company {$company->name} diaktifkan.",
            );

            return $company->fresh();
        });
    }

    /**
     * 019-billing-system (third expansion) — kebalikan activate(): admin
     * bisa mengunci kembali company yang sudah aktif (mis. langganan
     * berhenti dibayar / penyalahgunaan) TANPA menghapus datanya sama
     * sekali. Mengunci login owner user (is_active=false) dan
     * mengembalikan status ke 'pending_activation' — invoice/riwayat
     * transaksi company TIDAK disentuh sama sekali; activate() lagi
     * nanti tetap butuh Invoice 'paid' yang sudah ada (bisa jadi invoice
     * yang sama seperti sebelumnya, karena delete_paid_blocked mencegah
     * riwayat itu hilang).
     */
    public function deactivate(Company $company): Company
    {
        if ($company->status !== 'active') {
            throw ValidationException::withMessages([
                'status' => __('companies.deactivation_not_active'),
            ]);
        }

        return DB::transaction(function () use ($company) {
            $company->update([
                'status' => 'pending_activation',
                'activated_at' => null,
            ]);

            $company->owner->update(['is_active' => false]);

            $this->activityLogger->log(
                userId: $company->owner_user_id,
                action: 'deactivated',
                entityType: 'Company',
                entityId: $company->id,
                description: "Company {$company->name} dinonaktifkan.",
            );

            return $company->fresh();
        });
    }

    /**
     * 019-billing-system (second expansion) — mengedit data bisnis/kontak
     * company. Tidak menyentuh owner_user_id/status/activation_* sama
     * sekali (research.md R11): edit company tidak berarti re-aktivasi.
     *
     * @param  array{business_type_id:int, license_id:int, name:string,
     *   address:?string, contact_name:string, contact_email:string,
     *   contact_phone:?string}  $data
     */
    public function update(Company $company, array $data): Company
    {
        $company->update([
            'business_type_id' => $data['business_type_id'],
            'license_id' => $data['license_id'],
            'name' => $data['name'],
            'address' => $data['address'] ?? null,
            'contact_name' => $data['contact_name'],
            'contact_email' => $data['contact_email'],
            'contact_phone' => $data['contact_phone'] ?? null,
        ]);

        return $company->fresh();
    }

    /**
     * 019-billing-system (second expansion, research.md R10) — company
     * dengan riwayat invoice tidak boleh dihapus (dokumen finansial harus
     * tetap bisa ditelusuri ke company-nya). license_id SENGAJA TIDAK
     * dijadikan guard di sini — setiap company selalu punya satu license
     * by design (FR-004, wajib diisi), jadi guard atas itu akan membuat
     * SEMUA company permanen tidak bisa dihapus.
     */
    public function delete(Company $company): void
    {
        if ($company->invoices()->exists()) {
            throw ValidationException::withMessages([
                'company' => __('companies.company_delete_has_invoices'),
            ]);
        }

        $company->delete();
    }
}
