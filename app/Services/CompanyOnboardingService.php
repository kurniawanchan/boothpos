<?php

namespace App\Services;

use App\Mail\CompanyActivationMail;
use App\Models\Company;
use App\Models\CompanyActivationNotification;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * 017-company-onboarding — satu jalur sah untuk onboard/activate/resend
 * company. Business logic-nya genuinely non-trivial (state machine, hash
 * kode, pembuatan User owner, pengiriman+audit email) sehingga
 * dipisahkan dari controller (Constitution I), berbeda dari
 * BusinessType/Package yang cukup CRUD controller-level (plan.md).
 */
class CompanyOnboardingService
{
    public function __construct(private ActivityLogger $activityLogger) {}

    /**
     * @param  array{business_type_id:int, package_id:int, name:string,
     *   address:?string, contact_name:string, contact_email:string,
     *   contact_phone:?string, owner_username:string, owner_password:string}  $data
     */
    public function onboard(array $data, User $actor): Company
    {
        // research.md R7 — role_id owner user TIDAK PERNAH dari input
        // klien, selalu di-resolve ke peran default sistem 'Owner'.
        $ownerRole = Role::where('name', 'Owner')->where('is_system_default', true)->firstOrFail();

        [$company, $plainCode] = DB::transaction(function () use ($data, $actor, $ownerRole) {
            $owner = User::create([
                'name' => $data['contact_name'],
                'username' => $data['owner_username'],
                'password' => Hash::make($data['owner_password']),
                'role_id' => $ownerRole->id,
                'is_active' => false,
            ]);

            $plainCode = $this->generateCode();

            $company = Company::create([
                'business_type_id' => $data['business_type_id'],
                'package_id' => $data['package_id'],
                'name' => $data['name'],
                'address' => $data['address'] ?? null,
                'contact_name' => $data['contact_name'],
                'contact_email' => $data['contact_email'],
                'contact_phone' => $data['contact_phone'] ?? null,
                'owner_user_id' => $owner->id,
                'status' => 'pending_activation',
                'activation_code_hash' => Hash::make($plainCode),
                'activation_code_expires_at' => now()->addHours(24),
            ]);

            $this->activityLogger->log(
                userId: $actor->id,
                action: 'created',
                entityType: 'Company',
                entityId: $company->id,
                description: "Onboarding company {$company->name}.",
                newValues: $company->only(['business_type_id', 'package_id', 'name', 'contact_name', 'contact_email', 'status']),
            );

            return [$company, $plainCode];
        });

        $this->sendActivationCode($company, $plainCode, 'created');

        return $company;
    }

    public function resendActivationCode(Company $company): Company
    {
        if ($company->status === 'active') {
            throw ValidationException::withMessages([
                'status' => __('companies.resend_already_active'),
            ]);
        }

        $plainCode = $this->generateCode();

        // Menimpa hash+expiry yang lama SEKALIGUS membatalkannya —
        // hanya satu kode yang pernah valid per company (research.md R2).
        $company->update([
            'activation_code_hash' => Hash::make($plainCode),
            'activation_code_expires_at' => now()->addHours(24),
        ]);

        $this->sendActivationCode($company, $plainCode, 'resend');

        return $company->fresh();
    }

    public function activate(Company $company, string $code): Company
    {
        if ($company->status === 'active') {
            throw ValidationException::withMessages([
                'code' => __('companies.activation_already_active'),
            ]);
        }

        if (! $company->activation_code_expires_at || now()->greaterThan($company->activation_code_expires_at)) {
            throw ValidationException::withMessages([
                'code' => __('companies.activation_code_expired'),
            ]);
        }

        if (! Hash::check($code, $company->activation_code_hash)) {
            throw ValidationException::withMessages([
                'code' => __('companies.activation_code_invalid'),
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

    private function generateCode(): string
    {
        return (string) random_int(100000, 999999);
    }

    /**
     * Dipanggil SETELAH transaksi DB (onboard/resend) commit, tidak di
     * dalamnya — mencerminkan PreorderNotifier::notifyStatusChange()
     * (research.md R3): pengiriman email tidak boleh membuat aksi
     * bisnisnya sendiri gagal/rollback.
     */
    private function sendActivationCode(Company $company, string $plainCode, string $trigger): void
    {
        if (config('mail.default') === 'log') {
            $this->recordNotification($company, $trigger, 'skipped_not_configured');

            return;
        }

        try {
            Mail::to($company->contact_email)->send(new CompanyActivationMail($company, $plainCode));

            $this->recordNotification($company, $trigger, 'sent');
        } catch (Throwable $e) {
            $this->recordNotification($company, $trigger, 'failed', $e->getMessage());
        }
    }

    private function recordNotification(
        Company $company,
        string $trigger,
        string $status,
        ?string $errorMessage = null,
    ): CompanyActivationNotification {
        return CompanyActivationNotification::create([
            'company_id' => $company->id,
            'trigger' => $trigger,
            'recipient_email' => $company->contact_email,
            'status' => $status,
            'error_message' => $errorMessage,
            'sent_at' => now(),
        ]);
    }
}
