<?php

namespace Tests\Feature\CustomerCommerce;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Customer commerce (CONTRACT §3) — amplop galat `application/problem+json`
 * HANYA berlaku untuk /api/v1/customer/* dan /api/v1/storefront/*. SPA staf
 * dan aplikasi POS Android membaca bentuk lama (`{message}`, `{message,
 * errors}`), jadi test ini mengunci bentuk itu: ia ditulis dan dijalankan
 * hijau SEBELUM renderer baru dipasang di bootstrap/app.php, dan harus tetap
 * hijau sesudahnya. Kalau test ini merah, renderer pelanggan bocor ke rute staf.
 */
class StaffErrorShapeRegressionTest extends TestCase
{
    use RefreshDatabase;

    /** Kunci milik amplop pelanggan yang tidak boleh muncul di galat staf. */
    private const PROBLEM_KEYS = ['type', 'title', 'status', 'detail', 'code', 'request_id'];

    private function assertStaffShape($response, int $status): void
    {
        $response->assertStatus($status);
        $this->assertStringStartsWith('application/json', (string) $response->headers->get('Content-Type'));
        $this->assertFalse($response->headers->has('X-Request-Id'), 'Rute staf tidak memakai X-Request-Id.');

        foreach (self::PROBLEM_KEYS as $key) {
            $response->assertJsonMissingPath($key);
        }
    }

    public function test_staff_401_body_is_unchanged(): void
    {
        $response = $this->getJson('/api/v1/auth/me');

        $this->assertStaffShape($response, 401);
        $response->assertExactJson(['message' => 'Unauthenticated.']);
    }

    public function test_staff_bad_login_401_body_is_unchanged(): void
    {
        $response = $this->withHeader('Accept-Language', 'id')
            ->postJson('/api/v1/auth/login', ['username' => 'tidak-ada', 'password' => 'salah-sekali']);

        $this->assertStaffShape($response, 401);
        $response->assertExactJson(['message' => 'Username atau password salah.']);
    }

    public function test_staff_422_body_is_unchanged(): void
    {
        $response = $this->postJson('/api/v1/auth/login', []);

        $this->assertStaffShape($response, 422);
        $this->assertSame(['message', 'errors'], array_keys($response->json()));
        $this->assertEqualsCanonicalizing(['username', 'password'], array_keys($response->json('errors')));
    }

    public function test_staff_404_bodies_are_unchanged(): void
    {
        // Rute yang tidak ada.
        $unknown = $this->getJson('/api/v1/rute-yang-tidak-ada');
        $this->assertStaffShape($unknown, 404);
        $unknown->assertJsonPath('message', 'The route api/v1/rute-yang-tidak-ada could not be found.');

        // Route-model binding yang meleset (bawaan framework, memang menyebut kelas model: bentuk lama staf).
        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');
        $missing = $this->getJson('/api/v1/products/999999');
        $this->assertStaffShape($missing, 404);
        $missing->assertJsonPath('message', 'No query results for model [App\\Models\\Product] 999999');
    }

    public function test_staff_405_body_is_unchanged(): void
    {
        $response = $this->deleteJson('/api/v1/auth/login');

        $this->assertStaffShape($response, 405);
        $response->assertJsonPath('message', 'The DELETE method is not supported for route api/v1/auth/login. Supported methods: POST.');
    }

    public function test_staff_429_body_is_unchanged(): void
    {
        // `throttle:5,1` pada login staf: permintaan keenam ditolak.
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', ['username' => 'tidak-ada', 'password' => 'salah-sekali'])->assertStatus(401);
        }

        $response = $this->postJson('/api/v1/auth/login', ['username' => 'tidak-ada', 'password' => 'salah-sekali']);

        $this->assertStaffShape($response, 429);
        $response->assertJsonPath('message', 'Too Many Attempts.');
        $this->assertTrue($response->headers->has('Retry-After'));
    }

    public function test_staff_typed_business_error_body_is_unchanged(): void
    {
        // Galat bertipe milik staf (`{message, code}`) tetap bentuk lama: 403 gerbang aksi menu.
        $this->actingAs(User::factory()->create(['role' => 'cashier']), 'sanctum');

        $response = $this->putJson('/api/v1/settings', ['settings' => [['key' => 'app_name', 'value' => 'X']]]);

        $response->assertStatus(403)->assertJsonPath('code', 'action_not_allowed');
        foreach (['type', 'title', 'status', 'detail', 'request_id'] as $key) {
            $response->assertJsonMissingPath($key);
        }
    }
}
