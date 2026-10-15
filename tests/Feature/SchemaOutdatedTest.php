<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * 035-po-row-actions (US4) — database yang TERTINGGAL dari versi aplikasi
 * (migrasi belum diterapkan) tidak boleh tampil sebagai teks SQL mentah:
 * "kolom/tabel tidak ada" pada request API menjadi 503 `schema_outdated`
 * dengan pesan ramah; detail teknis tetap di log.
 */
class SchemaOutdatedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Rute uji: melempar QueryException sungguhan dengan SQLSTATE tertentu.
        Route::middleware('api')->get('api/v1/_schema-test/{state}', function (string $state) {
            $pdo = new class($state) extends \PDOException
            {
                public function __construct(string $state)
                {
                    parent::__construct("SQLSTATE[{$state}]: Unknown column 'secret_table.secret_column' in 'where clause'");
                    $this->code = $state;
                    $this->errorInfo = [$state, 1054, "Unknown column 'secret_table.secret_column'"];
                }
            };

            throw new QueryException('mysql', 'select secret_column from secret_table', [], $pdo);
        });
    }

    private function hit(string $state)
    {
        return $this->getJson("/api/v1/_schema-test/{$state}");
    }

    public function test_an_unknown_column_becomes_a_503_schema_outdated_without_any_sql(): void
    {
        $response = $this->hit('42S22')->assertStatus(503)->assertJsonPath('code', 'schema_outdated');

        $body = $response->getContent();
        foreach (['SQLSTATE', 'secret_table', 'secret_column', 'select ', 'Unknown column'] as $leak) {
            $this->assertStringNotContainsString($leak, $body, "response leaks '{$leak}'");
        }
        $this->assertNotSame('', (string) $response->json('message'));
    }

    public function test_a_missing_table_is_handled_the_same_way(): void
    {
        $response = $this->hit('42S02')->assertStatus(503)->assertJsonPath('code', 'schema_outdated');

        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
    }

    public function test_the_full_exception_is_still_reported_for_the_log(): void
    {
        Exceptions::fake();

        $this->hit('42S22')->assertStatus(503);

        Exceptions::assertReported(fn (QueryException $e) => str_contains($e->getMessage(), 'secret_column'));
    }

    public function test_other_database_errors_are_not_disguised_as_a_schema_problem(): void
    {
        $response = $this->hit('23000');

        $this->assertNotSame(503, $response->getStatusCode());
        $this->assertNotSame('schema_outdated', $response->json('code'));
    }

    public function test_the_message_exists_in_both_languages_and_is_generic(): void
    {
        foreach (['en', 'id'] as $locale) {
            $text = trans('system.schema_outdated', [], $locale);

            $this->assertNotSame('system.schema_outdated', $text, "missing {$locale} translation");
            $this->assertDoesNotMatchRegularExpression('/SQLSTATE|column|table|kolom|tabel/i', $text);
        }
    }
}
