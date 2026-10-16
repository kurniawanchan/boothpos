<?php

namespace Tests\Support;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;

/**
 * 048 / S3 — penyetuju uji (PIN dev 482916) dan penerbitan token lewat POST /overrides sebagai pengguna yang
 * sedang login. Peminta selalu di-attach ke outlet yang dipakai (permintaan harus berada di outlet itu).
 */
trait OverrideFixtures
{
    protected const APPROVER_PIN = '482916';

    protected function approver(int $outletId, string $role = 'owner'): User
    {
        $approver = User::factory()->create(['role' => $role]);
        $approver->forceFill(['pin_hash' => Hash::make(self::APPROVER_PIN), 'pin_failed_attempts' => 0, 'pin_locked_until' => null])->save();
        $approver->outlets()->syncWithoutDetaching([$outletId]);

        return $approver;
    }

    protected function issueOverride(string $action, array $subject, int $outletId, User $approver, string $pin = self::APPROVER_PIN): TestResponse
    {
        auth()->user()?->outlets()->syncWithoutDetaching([$outletId]);

        return $this->postJson('/api/v1/overrides', [
            'action' => $action, 'subject' => $subject, 'approver_user_id' => $approver->id, 'pin' => $pin,
        ], ['X-Outlet-Id' => $outletId]);
    }

    protected function overrideToken(string $action, array $subject, int $outletId, ?User $approver = null): string
    {
        return $this->issueOverride($action, $subject, $outletId, $approver ?? $this->approver($outletId))
            ->assertCreated()
            ->json('override_token');
    }
}
