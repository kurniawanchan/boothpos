<?php

namespace Tests\Feature;

use App\Support\WjmCatalog;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\WjmCatalogFixtures;
use Tests\TestCase;

/**
 * 068 (research D10, FR-020, FR-023, SC-006) — katalog WJM yang dibawa repo lolos validasi dan tidak memuat data
 * pribadi; setiap aturan validasi menolak katalog yang rusak sebelum ada penulisan apa pun.
 */
class WjmCatalogFileTest extends TestCase
{
    use WjmCatalogFixtures;

    public function test_the_bundled_wjm_catalog_is_structurally_valid_complete_and_free_of_personal_data(): void
    {
        $path = database_path('seeders/data/wjm-odoo/catalog.json');
        $catalog = WjmCatalog::fromFile($path);

        $this->assertSame([], $catalog->problems());
        $this->assertCount(10, $catalog->categories());
        $this->assertCount(79, $catalog->items());
        $this->assertCount(1, $catalog->optionGroups());
        $this->assertSame(100, $catalog->initialStock());
        $this->assertSame(['name' => 'Warkop WJM 3', 'codes' => ['WJ3', 'WJM'], 'create_when' => 'mode_empty'], $catalog->outlet());
        $this->assertFalse($catalog->withdrawOthers('live'));
        $this->assertTrue($catalog->withdrawOthers('demo'));
        $this->assertSame(['Bar' => ['Bar', 'Dapur Minuman'], 'Dapur' => ['Dapur', 'Dapur Makanan']], $catalog->stations());

        // Jumlah varian mengikuti Odoo kecuali item yang menyatakan penyimpangan (variant_count_override).
        $items = collect($catalog->items());
        $variants = $items->sum(fn (array $i) => count($i['variants']));
        $this->assertSame($items->sum(fn (array $i) => $i['variant_count_override'] === null ? $i['odoo_variant_count'] : count($i['variants'])), $variants);
        if ($items->every(fn (array $i) => $i['variant_count_override'] === null)) {
            $this->assertSame(111, $variants);
        }

        $rows = $catalog->source()['rows'];
        $this->assertSame([10, 26, 80], [$rows['categories'], $rows['attribute_values'], $rows['products']]);
        $this->assertSame([], $catalog->attributeValueAccounting()['unaccounted']);

        $this->assertDoesNotMatchRegularExpression('/"(responsible|user|email|phone)"\s*:/i', (string) file_get_contents($path));

        foreach ($items->pluck('photo')->filter()->unique() as $photo) {
            $file = $catalog->photoPath($photo);
            $this->assertFileExists($file);
            $this->assertLessThanOrEqual(5 * 1024 * 1024, filesize($file));
            $this->assertSame('image/jpeg', mime_content_type($file), $photo);
        }
    }

    public function test_the_bundled_wjm2_catalog_is_valid_and_approved_with_four_items_deferred_until_their_variant_names_are_known(): void
    {
        $path = database_path('seeders/data/wjm2-odoo/catalog.json');
        $catalog = WjmCatalog::fromFile($path);

        $this->assertSame([], $catalog->problems());
        $this->assertTrue($catalog->isApproved());
        $this->assertSame(['name' => 'Warkop WJM 2', 'codes' => ['WJ2', 'WD2'], 'create_when' => 'demo_missing'], $catalog->outlet());
        $this->assertSame(['live' => true, 'demo' => true], ['live' => $catalog->withdrawOthers('live'), 'demo' => $catalog->withdrawOthers('demo')]);
        $this->assertSame(100, $catalog->initialStock());
        $this->assertCount(8, $catalog->categories());
        $this->assertCount(76, $catalog->items());
        $this->assertSame(107, collect($catalog->items())->sum(fn (array $i) => count($i['variants'])));
        $this->assertSame(
            ['MAKANAN', 'LAIN LAIN', 'NASI', 'ROTI & PISANG', 'SIGNATURE COFFEE'],
            collect($catalog->excluded())->where('source', 'categories')->pluck('name')->all(),
        );
        $this->assertEqualsCanonicalizing(
            ['JASJUS ALL VARIANT', 'ALL VARIANT MINUMAN DINGIN(SHOWCASE)', 'MIE SEDAP KUAH', 'MIE SEDAP DOUBLE'],
            collect($catalog->excluded())->where('source', 'products')->pluck('name')->all(),
        );
        $this->assertTrue(collect($catalog->excluded())->where('source', 'products')->every(fn (array $e) => str_starts_with($e['reason'], 'Ditunda:')));
        $this->assertSame([13, 0, 80], [$catalog->source()['rows']['categories'], $catalog->source()['rows']['attribute_values'], $catalog->source()['rows']['products']]);
        $this->assertSame(0, collect($catalog->items())->flatMap(fn (array $i) => $i['variants'])->filter(fn (array $v) => str_starts_with($v['name'], '(belum diisi'))->count(), 'katalog disetujui tidak boleh memuat nama sementara');
        $this->assertSame(11, collect($catalog->items())->pluck('photo')->filter()->count());
        $this->assertDoesNotMatchRegularExpression('/penanggung|responsible/i', (string) file_get_contents($path));
    }

    public function test_the_second_mini_fixture_is_valid_and_approved(): void
    {
        $catalog = WjmCatalog::fromFile($this->wjmCatalogPath(null, 'wjm2-catalog'));

        $this->assertSame([], $catalog->problems());
        $this->assertTrue($catalog->isApproved());
        $this->assertSame('Warkop Uji 2', $catalog->outlet()['name']);
        $this->assertSame(['Bar' => ['Bar'], 'Dapur' => ['Dapur']], $catalog->stations());
    }

    public function test_the_mini_fixture_is_valid_and_approved(): void
    {
        $catalog = WjmCatalog::fromFile($this->wjmCatalogPath());

        $this->assertSame([], $catalog->problems());
        $this->assertTrue($catalog->isApproved());
    }

    public function test_a_draft_catalog_has_no_structural_problem_but_is_not_approved(): void
    {
        $catalog = WjmCatalog::fromFile($this->wjmCatalogPath(function (array $d) {
            $d['approval'] = ['status' => 'draft', 'approved_on' => null, 'approved_by_role' => null];

            return $d;
        }));

        $this->assertSame([], $catalog->problems());
        $this->assertFalse($catalog->isApproved());
    }

    public function test_a_variant_count_override_accepts_a_deliberate_deviation_from_odoo(): void
    {
        $catalog = WjmCatalog::fromFile($this->wjmCatalogPath(function (array $d) {
            $d['items'][0]['odoo_variant_count'] = 3;
            $d['items'][0]['variant_count_override'] = 'Pemilik menghapus satu varian';

            return $d;
        }));

        $this->assertSame([], $catalog->problems());
    }

    public function test_an_unreadable_or_invalid_file_is_a_problem_not_an_exception(): void
    {
        $this->assertStringContainsString('tidak bisa dibaca', WjmCatalog::fromFile('/nonexistent/catalog.json')->problems()[0]);

        $broken = sys_get_temp_dir().'/wjm-broken-'.uniqid().'.json';
        file_put_contents($broken, '{ bukan json');
        $this->assertStringContainsString('bukan JSON yang sah', WjmCatalog::fromFile($broken)->problems()[0]);
    }

    /** @return array<string, array{0: callable(array): array, 1: string}> */
    public static function brokenCatalogs(): array
    {
        return [
            'kategori tak dikenal' => [fn (array $d) => self::set($d, 'items.0.category', 'Tidak Ada'), "kategori 'Tidak Ada' tidak ada"],
            'nilai varian tak ada di atribut' => [fn (array $d) => self::set($d, 'items.0.variants.0.values', ['Suhu' => 'Panas']), "nilai 'Suhu: Panas' tidak ada"],
            'jumlah varian beda dari Odoo' => [fn (array $d) => self::set($d, 'items.0.odoo_variant_count', 3), 'jumlah varian 2 ≠ Odoo 3'],
            'harga negatif' => [fn (array $d) => self::set($d, 'items.0.price', -1), 'harga harus bilangan bulat ≥ 0'],
            'nama item ganda dalam kategori' => [fn (array $d) => self::set($d, 'items.4.name', 'Roti Bakar Coklat'), 'muncul dua kali di kategori'],
            'nama varian ganda dalam item' => [fn (array $d) => self::set($d, 'items.0.variants.1.name', 'Hot'), "nama varian 'Hot' ganda"],
            'grup dari atribut pembentuk varian' => [fn (array $d) => self::set($d, 'option_groups.0.from_attribute', 'Suhu'), 'bukan atribut opsi'],
            'attach_to ke item tak dikenal' => [fn (array $d) => self::set($d, 'option_groups.0.attach_to', ['Tidak Ada']), "item 'Tidak Ada' tidak ada di katalog"],
            'berkas foto hilang' => [fn (array $d) => self::set($d, 'items.0.photo', 'aaaaaaaaaaaa.jpg'), 'tidak ditemukan di folder photos/'],
            'foto kosong tanpa photo_issue' => [fn (array $d) => self::set($d, 'items.0.photo', null), 'photo_issue wajib'],
            'kunci data pribadi' => [fn (array $d) => self::set($d, 'items.0.responsible', 'Nama Staf'), "Kunci terlarang 'responsible'"],
            'jumlah baris produk sumber' => [fn (array $d) => self::set($d, 'source.rows.products', 9), 'baris produk sumber'],
            'disetujui tanpa tanggal' => [fn (array $d) => self::set($d, 'approval.approved_on', null), 'wajib disertai approved_on'],
            'format lama (1)' => [fn (array $d) => self::set($d, 'format', 1), 'format harus 2'],
            'kunci lama outlet_name' => [fn (array $d) => array_merge($d, ['outlet_name' => 'Warkop WJM 3']), "kunci tidak dikenal 'outlet_name'"],
            'create_when tak dikenal' => [fn (array $d) => self::set($d, 'outlet.create_when', 'selalu'), 'outlet.create_when harus mode_empty atau demo_missing'],
            'kode outlet bukan 3 karakter' => [fn (array $d) => self::set($d, 'outlet.codes', ['WJ']), 'outlet.codes harus 1–5 kode'],
            'outlet tanpa kode' => [fn (array $d) => self::set($d, 'outlet.codes', []), 'outlet.codes harus 1–5 kode'],
            'withdraw_others bukan boolean' => [fn (array $d) => self::set($d, 'withdraw_others.live', 'ya'), 'withdraw_others.live harus true/false'],
            'stasiun satu peran kosong' => [fn (array $d) => self::set($d, 'stations.Bar', []), 'stations.Bar wajib berupa daftar nama'],
            'stasiun satu nama di dua peran' => [fn (array $d) => self::set($d, 'stations.Dapur', ['Bar']), "nama stasiun 'Bar' dipakai dua peran"],
            'values_from tak dikenal' => [fn (array $d) => self::set($d, 'attributes.0.values_from', 'excel'), 'values_from harus odoo atau pemetaan'],
            'source_name kategori kosong' => [fn (array $d) => self::set($d, 'categories.0.source_name', ''), 'source_name wajib diisi'],
            'nama varian sementara pada katalog disetujui' => [fn (array $d) => self::set($d, 'items.0.variants.0.name', '(belum diisi 1)'), 'nama varian sementara'],
            'stok awal negatif' => [fn (array $d) => self::set($d, 'initial_stock', -1), 'initial_stock harus bilangan bulat ≥ 0 atau null'],
            'stok awal pecahan' => [fn (array $d) => self::set($d, 'initial_stock', 1.5), 'initial_stock harus bilangan bulat ≥ 0 atau null'],
        ];
    }

    #[DataProvider('brokenCatalogs')]
    public function test_each_validation_rule_rejects_a_broken_catalog(callable $mutate, string $expected): void
    {
        $problems = WjmCatalog::fromFile($this->wjmCatalogPath($mutate))->problems();

        $this->assertNotEmpty($problems);
        $this->assertTrue(
            collect($problems)->contains(fn (string $p) => str_contains($p, $expected)),
            "Tidak ada masalah yang memuat \"{$expected}\". Masalah: ".implode(' | ', $problems),
        );
    }

    private static function set(array $data, string $path, mixed $value): array
    {
        data_set($data, $path, $value);

        return $data;
    }
}
