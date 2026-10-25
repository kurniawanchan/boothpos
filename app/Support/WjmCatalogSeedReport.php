<?php

namespace App\Support;

use Illuminate\Console\Command;

/**
 * 068 (FR-021, contracts/cli.md §3) — laporan satu mode pengisian katalog WJM. Diisi WjmCatalogSeedService dan
 * dicetak lewat printTo() oleh perintah app:seed-wjm-catalog maupun WjmCatalogSeeder, supaya bentuk laporan
 * hanya ditulis di satu tempat.
 */
final class WjmCatalogSeedReport
{
    public const APPLIED = 'applied';

    public const PREVIEWED = 'previewed';

    public const REFUSED = 'refused';

    public const FAILED = 'failed';

    /** Jenis yang dihitung, dengan labelnya di tabel laporan. */
    public const KINDS = [
        'stations' => 'Stasiun',
        'categories' => 'Kategori',
        'items' => 'Item',
        'variants' => 'Varian',
        'option_groups' => 'Grup pilihan',
        'photos' => 'Foto',
        'stock' => 'Stok awal',
    ];

    public ?string $status = null;

    /** @var array{code: string, name: string, created: bool}|null */
    public ?array $outlet = null;

    /** @var array<string, array{created: int, existing: int}> */
    public array $counts = [];

    /** @var list<string> */
    public array $excluded = [];

    /** @var list<string> */
    public array $withoutPhoto = [];

    /** @var list<string> */
    public array $zeroPrice = [];

    /** @var list<string> */
    public array $renamedGroups = [];

    /** @var list<string> */
    public array $kitItems = [];

    /** @var list<string> atribut yang nilainya berasal dari tabel pemetaan, bukan ekspor (069 D3) */
    public array $mappedAttributes = [];

    /** @var list<string> item yang masih punya nama varian sementara (hanya sah pada pratinjau) */
    public array $placeholderVariants = [];

    /** @var list<string> */
    public array $withdrawn = [];

    /** @var list<string> */
    public array $deletedByOwner = [];

    /** @var list<string> */
    public array $problems = [];

    /** @var list<string> */
    public array $warnings = [];

    /** @var list<string> */
    public array $sourceAccounting = [];

    public function __construct(public readonly string $mode)
    {
        $this->resetRun();
    }

    public function created(string $kind, int $n = 1): void
    {
        $this->counts[$kind]['created'] += $n;
    }

    public function existing(string $kind, int $n = 1): void
    {
        $this->counts[$kind]['existing'] += $n;
    }

    public function isSuccessful(): bool
    {
        return in_array($this->status, [self::APPLIED, self::PREVIEWED], true);
    }

    /** Daftar yang berasal dari katalog itu sendiri (bukan dari jalan), untuk ditinjau pemilik (FR-021). */
    public function fillFromCatalog(WjmCatalog $catalog): void
    {
        $this->excluded = array_map(fn (array $e) => "{$e['name']} — {$e['reason']}", $catalog->excluded());
        $this->withoutPhoto = $this->zeroPrice = $this->kitItems = $this->mappedAttributes = $this->placeholderVariants = [];
        foreach ($catalog->attributes() as $attribute) {
            if (($attribute['values_from'] ?? 'odoo') === 'pemetaan') {
                $this->mappedAttributes[] = $attribute['name'].' ('.count($attribute['values']).' nilai)';
            }
        }
        foreach ($catalog->items() as $item) {
            if ($item['photo'] === null) {
                $this->withoutPhoto[] = $item['name'].($item['photo_issue'] === 'truncated' ? ' (terpotong di ekspor XLSX)' : ' (kosong)');
            }
            if (in_array(0, array_column($item['variants'], 'price'), true)) {
                $this->zeroPrice[] = $item['name'];
            }
            if ($item['odoo_kit']) {
                $this->kitItems[] = $item['name'];
            }
            if (array_filter($item['variants'], fn (array $v) => str_starts_with($v['name'], '(belum diisi')) !== []) {
                $this->placeholderVariants[] = $item['name'];
            }
        }
    }

    /**
     * SC-005: setiap baris sumber harus berakhir sebagai dibuat, sudah ada, atau dikecualikan. Baris yang tidak
     * cocok ditandai "(TIDAK COCOK)" alih-alih disembunyikan.
     */
    public function accountFor(WjmCatalog $catalog): void
    {
        $made = $this->status === self::PREVIEWED ? 'akan dibuat' : 'dibuat';
        $rows = $catalog->source()['rows'];
        $excludedProducts = count(array_filter($catalog->excluded(), fn (array $e) => $e['source'] === 'products'));
        $items = $this->counts['items'];
        $categories = $this->counts['categories'];
        $excludedCategories = count(array_filter($catalog->excluded(), fn (array $e) => $e['source'] === 'categories'));
        $values = $catalog->attributeValueAccounting();
        $line = fn (bool $balanced, string $text) => $balanced ? $text : $text.' (TIDAK COCOK)';

        $this->sourceAccounting = [
            $line(
                $rows['products'] === $items['created'] + $items['existing'] + $excludedProducts,
                "produk {$rows['products']} = {$items['created']} {$made} + {$items['existing']} sudah ada + {$excludedProducts} dikecualikan",
            ),
            $line(
                $rows['categories'] === $categories['created'] + $categories['existing'] + $excludedCategories,
                "kategori {$rows['categories']} = {$categories['created']} {$made} + {$categories['existing']} sudah ada + {$excludedCategories} dikecualikan",
            ),
            $line(
                $values['unaccounted'] === [] && $values['total'] === $rows['attribute_values'],
                "nilai atribut {$values['total']} = {$values['used']} dipakai varian + {$values['options']} opsi grup + {$values['excluded']} dikecualikan",
            ),
        ];
    }

    /** Hasil jalan yang dibatalkan (rollBack) tidak boleh terbaca seolah tersimpan. */
    public function resetRun(): void
    {
        $this->outlet = null;
        $this->counts = array_map(fn () => ['created' => 0, 'existing' => 0], self::KINDS);
        $this->renamedGroups = $this->withdrawn = $this->deletedByOwner = $this->sourceAccounting = [];
    }

    public function printTo(Command $command): void
    {
        $preview = $this->status === self::PREVIEWED;
        if ($preview) {
            $command->warn('PRATINJAU — tidak ada yang disimpan');
        }

        $outlet = $this->outlet === null ? '' : sprintf(
            ' — outlet "%s" (%s)%s',
            $this->outlet['name'],
            $this->outlet['code'],
            $this->outlet['created'] ? ($preview ? ', akan dibuat baru' : ', dibuat baru') : '',
        );
        $command->line('Mode '.strtoupper($this->mode).$outlet.' — status: '.$this->status);
        foreach ($this->warnings as $warning) {
            $command->warn('  '.$warning);
        }

        if (! $this->isSuccessful()) {
            foreach ($this->problems as $problem) {
                $command->error('  '.$problem);
            }

            return;
        }

        $command->table(
            ['Jenis', $preview ? 'Akan dibuat' : 'Dibuat', 'Sudah ada'],
            array_map(
                fn (string $kind, string $label) => [$label, $this->counts[$kind]['created'], $this->counts[$kind]['existing']],
                array_keys(self::KINDS),
                self::KINDS,
            ),
        );

        $lists = [
            'Dikecualikan' => $this->excluded,
            'Tanpa foto' => $this->withoutPhoto,
            'Harga 0, tinjau pemilik' => $this->zeroPrice,
            'Kit/BoM Odoo dijual sebagai item biasa' => $this->kitItems,
            'Nilai atribut dari tabel pemetaan' => $this->mappedAttributes,
            'Nama varian masih sementara, pemilik harus mengisi' => $this->placeholderVariants,
            'Grup diberi nama pembeda' => $this->renamedGroups,
            'Dihapus pemilik, tidak dibuat ulang' => $this->deletedByOwner,
            $preview ? 'Akan ditarik dari menu outlet ini' : 'Ditarik dari menu outlet ini' => $this->withdrawn,
        ];
        foreach ($lists as $label => $entries) {
            if ($entries !== []) {
                $command->line(sprintf('  %s (%d): %s', $label, count($entries), implode('; ', $entries)));
            }
        }
        foreach ($this->sourceAccounting as $line) {
            $command->line('  Pertanggungjawaban sumber: '.$line);
        }
    }
}
