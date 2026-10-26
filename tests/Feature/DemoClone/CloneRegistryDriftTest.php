<?php

namespace Tests\Feature\DemoClone;

use App\Services\DemoClone\CloneRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Jaring pengaman terhadap perubahan skema di masa depan. Jaminan "LIVE tidak
 * tersentuh" bergantung pada CloneRegistry yang cocok dengan skema: FK baru
 * yang tak terdaftar berarti nilainya disalin APA ADANYA (id LIVE bocor ke
 * baris DEMO) atau — untuk FK ON DELETE SET NULL/CASCADE — baris LIVE ikut
 * berubah saat DEMO dihapus. Test ini gagal begitu skema dan registry berpisah,
 * dengan pesan yang menyebut tabel/kolom yang harus didaftarkan.
 */
class CloneRegistryDriftTest extends TestCase
{
    use RefreshDatabase;

    /** Anak yang SENGAJA tidak disalin tetapi menunjuk tabel terdaftar (ditangani cek awal/cascade). */
    private const UNREGISTERED_CHILDREN = ['pos_drafts.event_id', 'preorder_notifications.preorder_id'];

    /** Induk bersama (tidak dimoded) yang boleh ditunjuk tabel terdaftar tanpa dipetakan. */
    private const SHARED_PARENTS = ['users', 'payment_channels', 'roles'];

    /** Tabel ber-data_mode yang SENGAJA tidak disalin. */
    private const EXCLUDED_DATA_MODE_TABLES = ['users', 'companies', 'invoices', 'pos_drafts'];

    /** @return list<object{child:string,column:string,parent:string}> */
    private function foreignKeys(): array
    {
        return array_map(fn ($r) => (object) [
            'child' => $r->child, 'column' => $r->col, 'parent' => $r->parent,
        ], DB::select(
            'SELECT TABLE_NAME AS child, COLUMN_NAME AS col, REFERENCED_TABLE_NAME AS parent
               FROM information_schema.KEY_COLUMN_USAGE
              WHERE TABLE_SCHEMA = ? AND REFERENCED_TABLE_NAME IS NOT NULL',
            [DB::getDatabaseName()],
        ));
    }

    public function test_every_foreign_key_into_a_registered_table_is_declared_in_the_registry(): void
    {
        $registered = CloneRegistry::tables();
        $problems = [];

        foreach ($this->foreignKeys() as $fk) {
            if (! isset($registered[$fk->parent])) {
                continue;
            }

            if (isset($registered[$fk->child])) {
                $declared = CloneRegistry::references($fk->child)[$fk->column] ?? null;

                if ($declared !== $fk->parent) {
                    $problems[] = "{$fk->child}.{$fk->column} -> {$fk->parent}: daftarkan di CloneRegistry (fks/deferred) tabel {$fk->child}.";
                }

                continue;
            }

            if (! in_array("{$fk->child}.{$fk->column}", self::UNREGISTERED_CHILDREN, true)) {
                $problems[] = "{$fk->child}.{$fk->column} -> {$fk->parent}: tabel {$fk->child} belum terdaftar di CloneRegistry dan tidak ada di daftar pengecualian.";
            }
        }

        $this->assertSame([], $problems, "Registry tidak sinkron dengan skema:\n".implode("\n", $problems));
    }

    public function test_a_registered_table_only_points_at_unregistered_parents_that_are_shared(): void
    {
        $registered = CloneRegistry::tables();
        $problems = [];

        foreach ($this->foreignKeys() as $fk) {
            if (isset($registered[$fk->child]) && ! isset($registered[$fk->parent]) && ! in_array($fk->parent, self::SHARED_PARENTS, true)) {
                $problems[] = "{$fk->child}.{$fk->column} -> {$fk->parent}: induk tidak terdaftar dan bukan induk bersama ".implode('/', self::SHARED_PARENTS).'; daftarkan tabel induknya atau tambahkan ke daftar bersama bila memang dibagi.';
            }
        }

        $this->assertSame([], $problems, "Registry tidak sinkron dengan skema:\n".implode("\n", $problems));
    }

    /**
     * 076 — settlement seller. Tabel anak tidak punya data_mode sehingga test
     * "data_mode terdaftar" di bawah TIDAK akan menangkap kalau mereka lupa
     * didaftarkan; test ini menjaganya secara eksplisit.
     */
    public function test_seller_settlement_tables_are_registered_with_parent_first_and_children_via_parent(): void
    {
        $tables = array_keys(CloneRegistry::tables());
        $parentAt = array_search('seller_settlements', $tables, true);

        $this->assertNotFalse($parentAt, 'seller_settlements belum terdaftar di CloneRegistry.');

        foreach (['sources', 'adjustments', 'acknowledgements', 'payments'] as $child) {
            $name = "seller_settlement_{$child}";
            $at = array_search($name, $tables, true);

            $this->assertNotFalse($at, "{$name} belum terdaftar di CloneRegistry.");
            $this->assertGreaterThan($parentAt, $at, "{$name} harus berada SETELAH induknya (urutan FK).");
            $this->assertSame('seller_settlements.settlement_id', CloneRegistry::spec($name)['via']);
        }

        $this->assertSame(['signature_path' => 'local'], CloneRegistry::spec('seller_settlement_acknowledgements')['files']);
        $this->assertSame(['proof_path' => 'local'], CloneRegistry::spec('seller_settlement_payments')['files']);
        $this->assertSame(['client_ref'], CloneRegistry::spec('seller_settlement_payments')['uuid']);
    }

    public function test_every_table_with_a_data_mode_column_is_registered_or_explicitly_excluded(): void
    {
        $registered = CloneRegistry::tables();
        $tables = DB::select(
            "SELECT DISTINCT TABLE_NAME AS t FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND COLUMN_NAME = 'data_mode'",
            [DB::getDatabaseName()],
        );
        $problems = [];

        foreach ($tables as $row) {
            if (! isset($registered[$row->t]) && ! in_array($row->t, self::EXCLUDED_DATA_MODE_TABLES, true)) {
                $problems[] = "{$row->t}: punya kolom data_mode tetapi tidak ada di CloneRegistry; daftarkan, atau tambahkan ke pengecualian bila memang tidak disalin.";
            }
        }

        $this->assertSame([], $problems, "Registry tidak sinkron dengan skema:\n".implode("\n", $problems));
    }
}
