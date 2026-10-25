<?php

namespace App\Support;

use App\Services\ImageUploadService;

/**
 * 068 (research D10, data-model §1) — pemuat dan validator `catalog.json` katalog WJM.
 *
 * Mengumpulkan SEMUA masalah sebelum ada penulisan apa pun (FR-023), supaya operator melihat daftar lengkap
 * dalam sekali jalan, bukan satu galat per percobaan. Status draf sengaja BUKAN masalah: pratinjau boleh
 * berjalan pada draf (research D9), dan WjmCatalogSeedService menolak pengisian sungguhan lewat isApproved().
 *
 * Kontrak bentuk: specs/068-odoo-catalog-seeder/contracts/catalog.schema.json. Aturan lintas kolom (kategori
 * ada, nilai varian ada di atribut, jumlah varian = Odoo, pertanggungjawaban baris sumber, foto ada di disk)
 * hanya ada di sini; tidak ada dependensi JSON Schema.
 */
final class WjmCatalog
{
    private const ROOT_KEYS = ['format', 'outlet', 'withdraw_others', 'stations', 'source', 'approval', 'initial_stock', 'categories', 'attributes', 'option_groups', 'items', 'excluded'];

    /** Nama sementara untuk varian yang belum diberi nama pemilik (build_catalog.py); tidak boleh ada di katalog yang disetujui. */
    private const PLACEHOLDER_PREFIX = '(belum diisi';

    private const ITEM_KEYS = ['name', 'category', 'price', 'odoo_variant_count', 'variants', 'variant_count_override', 'photo', 'photo_issue', 'odoo_kit'];

    /** Data pribadi (mis. kolom Responsible Odoo = nama staf) tidak boleh masuk katalog di tingkat mana pun (SC-006). */
    private const FORBIDDEN_KEYS = ['responsible', 'user', 'email', 'phone'];

    private const STATIONS = ['Bar', 'Dapur'];

    private const EXCLUDED_SOURCES = ['products', 'categories', 'attributes', 'attribute_values'];

    private const PHOTO_MAGIC = ['jpg' => "\xFF\xD8\xFF", 'png' => "\x89PNG\r\n\x1A\n"];

    /** @var list<string> */
    private array $problems = [];

    /** @param  array<string, mixed>  $data */
    private function __construct(private readonly string $path, private readonly array $data) {}

    public static function fromFile(string $path): self
    {
        $raw = is_file($path) ? file_get_contents($path) : false;
        if ($raw === false) {
            return self::broken($path, "Berkas katalog tidak bisa dibaca: {$path}");
        }

        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return self::broken($path, 'Berkas katalog bukan JSON yang sah: '.$e->getMessage());
        }

        $catalog = new self($path, is_array($data) ? $data : []);
        $catalog->validate();

        return $catalog;
    }

    /** @return list<string> */
    public function problems(): array
    {
        return $this->problems;
    }

    public function isApproved(): bool
    {
        return ($this->data['approval']['status'] ?? null) === 'approved';
    }

    public function path(): string
    {
        return $this->path;
    }

    /** @return array{name: string, codes: list<string>, create_when: string} */
    public function outlet(): array
    {
        return $this->data['outlet'] ?? ['name' => '', 'codes' => [], 'create_when' => ''];
    }

    /** Apakah item di luar katalog ditarik dari menu outlet tujuan pada mode ini (research D9). */
    public function withdrawOthers(string $mode): bool
    {
        return (bool) ($this->data['withdraw_others'][$mode] ?? false);
    }

    /** @return array{Bar: list<string>, Dapur: list<string>} peran stasiun → nama yang dikenali (nama pertama dipakai saat membuat) */
    public function stations(): array
    {
        return $this->data['stations'] ?? ['Bar' => [], 'Dapur' => []];
    }

    /** @return array<string, mixed> */
    public function source(): array
    {
        return $this->data['source'] ?? [];
    }

    /** @return array{status?: string, approved_on?: ?string, approved_by_role?: ?string} */
    public function approval(): array
    {
        return $this->data['approval'] ?? [];
    }

    /** Stok awal per varian baru di outlet tujuan; null = tanpa pelacakan stok (FR-013). */
    public function initialStock(): ?int
    {
        return $this->data['initial_stock'] ?? null;
    }

    /** @return list<array{name: string, source_name: string, order: int, station: string}> */
    public function categories(): array
    {
        return $this->data['categories'] ?? [];
    }

    /** @return list<array{name: string, creates_variants: bool, values_from: string, values: list<string>}> */
    public function attributes(): array
    {
        return $this->data['attributes'] ?? [];
    }

    /** @return list<array{name: string, from_attribute: string, required: bool, options: list<string>, attach_to: list<string>}> */
    public function optionGroups(): array
    {
        return $this->data['option_groups'] ?? [];
    }

    /** @return list<array<string, mixed>> */
    public function items(): array
    {
        return $this->data['items'] ?? [];
    }

    /** @return list<array{source: string, name: string, reason: string}> */
    public function excluded(): array
    {
        return $this->data['excluded'] ?? [];
    }

    public function photoPath(string $file): string
    {
        return dirname($this->path).'/photos/'.$file;
    }

    /**
     * Nasib setiap nilai atribut sumber (SC-005): dipakai varian, menjadi opsi grup pilihan, atau dikecualikan.
     * Dihitung sebagai himpunan "Atribut: nilai" supaya nilai yang tercatat di dua tempat (mis. nilai "test"
     * milik atribut yang dikecualikan) tidak terhitung dua kali.
     *
     * @return array{total: int, used: int, options: int, excluded: int, unaccounted: list<string>}
     */
    public function attributeValueAccounting(): array
    {
        $all = $used = $options = $excluded = [];
        foreach ($this->attributes() as $attribute) {
            // Nilai yang berasal dari tabel pemetaan bukan baris sumber, jadi tidak ikut dipertanggungjawabkan (069 D3).
            if (($attribute['values_from'] ?? 'odoo') !== 'odoo') {
                continue;
            }
            foreach ((array) ($attribute['values'] ?? []) as $value) {
                $all[$attribute['name'].': '.$value] = true;
            }
        }
        foreach ($this->items() as $item) {
            foreach ((array) ($item['variants'] ?? []) as $variant) {
                foreach ((array) ($variant['values'] ?? []) as $attribute => $value) {
                    $used[$attribute.': '.$value] = true;
                }
            }
        }
        foreach ($this->optionGroups() as $group) {
            foreach ((array) ($group['options'] ?? []) as $option) {
                $options[($group['from_attribute'] ?? '').': '.$option] = true;
            }
        }
        $excludedAttributes = array_column(array_filter($this->excluded(), fn ($e) => ($e['source'] ?? null) === 'attributes'), 'name');
        foreach ($this->attributes() as $attribute) {
            if (in_array($attribute['name'], $excludedAttributes, true)) {
                foreach ((array) ($attribute['values'] ?? []) as $value) {
                    $excluded[$attribute['name'].': '.$value] = true;
                }
            }
        }
        foreach ($this->excluded() as $entry) {
            if (($entry['source'] ?? null) === 'attribute_values') {
                $excluded[$entry['name']] = true;
            }
        }

        return [
            'total' => count($all),
            // Hanya nilai dari ekspor: nilai yang diisi lewat tabel pemetaan bukan baris sumber (069 D3).
            'used' => count(array_intersect_key($used, $all)),
            'options' => count(array_diff_key($options, $used)),
            'excluded' => count(array_diff_key($excluded, $used, $options)),
            'unaccounted' => array_keys(array_diff_key($all, $used, $options, $excluded)),
        ];
    }

    private static function broken(string $path, string $problem): self
    {
        $catalog = new self($path, []);
        $catalog->problems[] = $problem;

        return $catalog;
    }

    private function validate(): void
    {
        $data = $this->data;
        $this->checkKeys($data, self::ROOT_KEYS, 'Akar katalog');
        $this->checkForbiddenKeys($data, 'katalog');

        if (($data['format'] ?? null) !== 2) {
            $this->problems[] = 'format harus 2 (bangkitkan ulang dengan build_catalog.py).';
        }
        $this->validateOutlet($data['outlet'] ?? null);
        $this->validateWithdrawOthers($data['withdraw_others'] ?? null);
        $this->validateStations($data['stations'] ?? null);

        $this->validateSource($data['source'] ?? null);
        $this->validateApproval($data['approval'] ?? null);
        $stock = $data['initial_stock'] ?? null;
        if ($stock !== null && (! is_int($stock) || $stock < 0)) {
            $this->problems[] = 'initial_stock harus bilangan bulat ≥ 0 atau null.';
        }
        $categories = $this->validateCategories($data['categories'] ?? null);
        $attributes = $this->validateAttributes($data['attributes'] ?? null);
        $itemNames = $this->validateItems($data['items'] ?? null, $categories, $attributes);
        $this->validateOptionGroups($data['option_groups'] ?? null, $attributes, $itemNames);
        $this->validateExcluded($data['excluded'] ?? null);

        // Pertanggungjawaban baru bermakna bila bentuknya sudah benar; bila tidak, ia hanya menambah kebisingan.
        if ($this->problems === []) {
            $this->validateAccounting();
        }
    }

    private function validateOutlet(mixed $outlet): void
    {
        if (! is_array($outlet)) {
            $this->problems[] = 'outlet wajib berupa objek.';

            return;
        }
        $this->checkKeys($outlet, ['name', 'codes', 'create_when'], 'outlet');
        if (! $this->isText($outlet['name'] ?? null, 100)) {
            $this->problems[] = 'outlet.name wajib diisi (≤ 100 karakter).';
        }
        $codes = $outlet['codes'] ?? null;
        if (! is_array($codes) || ! array_is_list($codes) || $codes === [] || count($codes) > 5
            || array_filter($codes, fn ($c) => ! is_string($c) || ! preg_match('/^[A-Z0-9]{3}$/', $c)) !== []) {
            $this->problems[] = 'outlet.codes harus 1–5 kode outlet (3 huruf besar atau angka).';
        }
        if (! in_array($outlet['create_when'] ?? null, ['mode_empty', 'demo_missing'], true)) {
            $this->problems[] = 'outlet.create_when harus mode_empty atau demo_missing.';
        }
    }

    private function validateWithdrawOthers(mixed $withdraw): void
    {
        if (! is_array($withdraw)) {
            $this->problems[] = 'withdraw_others wajib berupa objek.';

            return;
        }
        $this->checkKeys($withdraw, ['live', 'demo'], 'withdraw_others');
        foreach (['live', 'demo'] as $mode) {
            if (! is_bool($withdraw[$mode] ?? null)) {
                $this->problems[] = "withdraw_others.{$mode} harus true/false.";
            }
        }
    }

    private function validateStations(mixed $stations): void
    {
        if (! is_array($stations)) {
            $this->problems[] = 'stations wajib berupa objek.';

            return;
        }
        $this->checkKeys($stations, self::STATIONS, 'stations');
        $owner = [];
        foreach (self::STATIONS as $role) {
            $names = $stations[$role] ?? null;
            if (! is_array($names) || ! array_is_list($names) || $names === [] || count($names) > 5
                || array_filter($names, fn ($n) => ! $this->isText($n, 50)) !== []) {
                $this->problems[] = "stations.{$role} wajib berupa daftar nama (1–5, masing-masing ≤ 50 karakter).";

                continue;
            }
            foreach ($names as $name) {
                if (isset($owner[mb_strtolower($name)]) && $owner[mb_strtolower($name)] !== $role) {
                    $this->problems[] = "stations: nama stasiun '{$name}' dipakai dua peran.";
                }
                $owner[mb_strtolower($name)] = $role;
            }
        }
    }

    private function validateSource(mixed $source): void
    {
        if (! is_array($source)) {
            $this->problems[] = 'source wajib berupa objek.';

            return;
        }
        $this->checkKeys($source, ['system', 'exported_on', 'files', 'rows'], 'source');
        if (($source['system'] ?? null) !== 'Odoo') {
            $this->problems[] = "source.system harus 'Odoo'.";
        }
        if (! $this->isDate($source['exported_on'] ?? null)) {
            $this->problems[] = 'source.exported_on harus tanggal YYYY-MM-DD.';
        }
        foreach (['categories', 'attributes', 'products'] as $key) {
            if (! $this->isText($source['files'][$key] ?? null, 255)) {
                $this->problems[] = "source.files.{$key} wajib diisi.";
            }
            $rowKey = $key === 'attributes' ? 'attribute_values' : $key;
            if (! $this->isNonNegativeInt($source['rows'][$rowKey] ?? null)) {
                $this->problems[] = "source.rows.{$rowKey} harus bilangan bulat ≥ 0.";
            }
        }
    }

    private function validateApproval(mixed $approval): void
    {
        if (! is_array($approval)) {
            $this->problems[] = 'approval wajib berupa objek.';

            return;
        }
        $this->checkKeys($approval, ['status', 'approved_on', 'approved_by_role'], 'approval');
        $status = $approval['status'] ?? null;
        if ($status === 'approved') {
            if (! $this->isDate($approval['approved_on'] ?? null)) {
                $this->problems[] = 'approval: status approved wajib disertai approved_on (YYYY-MM-DD).';
            }
            if (! $this->isText($approval['approved_by_role'] ?? null, 40)) {
                $this->problems[] = 'approval: status approved wajib disertai approved_by_role.';
            }
        } elseif ($status === 'draft') {
            if (($approval['approved_on'] ?? null) !== null || ($approval['approved_by_role'] ?? null) !== null) {
                $this->problems[] = 'approval: katalog draf tidak boleh punya approved_on atau approved_by_role.';
            }
        } else {
            $this->problems[] = "approval.status harus 'draft' atau 'approved'.";
        }
    }

    /** @return array<string, string> nama kategori → stasiun */
    private function validateCategories(mixed $categories): array
    {
        if (! is_array($categories) || $categories === [] || ! array_is_list($categories)) {
            $this->problems[] = 'categories wajib berupa daftar yang tidak kosong.';

            return [];
        }

        $map = [];
        foreach ($categories as $i => $category) {
            $label = 'Kategori #'.($i + 1);
            if (! is_array($category)) {
                $this->problems[] = "{$label} wajib berupa objek.";

                continue;
            }
            $this->checkKeys($category, ['name', 'source_name', 'order', 'station'], $label);
            $name = $category['name'] ?? null;
            if (! $this->isText($name, 100)) {
                $this->problems[] = "{$label}: name wajib diisi (≤ 100 karakter).";

                continue;
            }
            if (! $this->isText($category['source_name'] ?? null, 100)) {
                $this->problems[] = "Kategori '{$name}': source_name wajib diisi (≤ 100 karakter).";
            }
            if (isset($map[$name])) {
                $this->problems[] = "Kategori '{$name}' muncul dua kali.";
            }
            if (! $this->isNonNegativeInt($category['order'] ?? null)) {
                $this->problems[] = "Kategori '{$name}': order harus bilangan bulat ≥ 0.";
            }
            if (! in_array($category['station'] ?? null, self::STATIONS, true)) {
                $this->problems[] = "Kategori '{$name}': station harus Bar atau Dapur.";
            }
            $map[$name] = (string) ($category['station'] ?? '');
        }

        return $map;
    }

    /** @return array<string, array{creates_variants: bool, values: list<string>}> */
    private function validateAttributes(mixed $attributes): array
    {
        if (! is_array($attributes) || ! array_is_list($attributes)) {
            $this->problems[] = 'attributes wajib berupa daftar.';

            return [];
        }

        $map = [];
        foreach ($attributes as $i => $attribute) {
            $label = 'Atribut #'.($i + 1);
            if (! is_array($attribute)) {
                $this->problems[] = "{$label} wajib berupa objek.";

                continue;
            }
            $this->checkKeys($attribute, ['name', 'creates_variants', 'values_from', 'values'], $label);
            $name = $attribute['name'] ?? null;
            if (! $this->isText($name, 100)) {
                $this->problems[] = "{$label}: name wajib diisi.";

                continue;
            }
            if (isset($map[$name])) {
                $this->problems[] = "Atribut '{$name}' muncul dua kali.";
            }
            if (! is_bool($attribute['creates_variants'] ?? null)) {
                $this->problems[] = "Atribut '{$name}': creates_variants harus true/false.";
            }
            if (! in_array($attribute['values_from'] ?? null, ['odoo', 'pemetaan'], true)) {
                $this->problems[] = "Atribut '{$name}': values_from harus odoo atau pemetaan.";
            }
            $values = $attribute['values'] ?? null;
            if (! is_array($values) || $values === [] || ! array_is_list($values) || array_filter($values, fn ($v) => ! $this->isText($v, 255)) !== []) {
                $this->problems[] = "Atribut '{$name}': values wajib berupa daftar teks yang tidak kosong.";
                $values = [];
            } elseif (count(array_unique($values)) !== count($values)) {
                $this->problems[] = "Atribut '{$name}': ada nilai ganda.";
            }
            $map[$name] = ['creates_variants' => ($attribute['creates_variants'] ?? null) === true, 'values' => $values];
        }

        return $map;
    }

    /**
     * @param  array<string, string>  $categories
     * @param  array<string, array{creates_variants: bool, values: list<string>}>  $attributes
     * @return array<string, true> nama item yang dikenal (untuk attach_to)
     */
    private function validateItems(mixed $items, array $categories, array $attributes): array
    {
        if (! is_array($items) || $items === [] || ! array_is_list($items)) {
            $this->problems[] = 'items wajib berupa daftar yang tidak kosong.';

            return [];
        }

        $names = $pairs = [];
        foreach ($items as $i => $item) {
            if (! is_array($item)) {
                $this->problems[] = 'Item #'.($i + 1).' wajib berupa objek.';

                continue;
            }
            $name = $item['name'] ?? null;
            $label = is_string($name) ? "Item '{$name}'" : 'Item #'.($i + 1);
            $this->checkKeys($item, self::ITEM_KEYS, $label);

            if (! $this->isText($name, 150)) {
                $this->problems[] = "{$label}: name wajib diisi (≤ 150 karakter).";

                continue;
            }
            $names[$name] = true;

            $category = $item['category'] ?? null;
            if (! is_string($category) || ! isset($categories[$category])) {
                $this->problems[] = "{$label}: kategori '".(is_scalar($category) ? $category : '?')."' tidak ada di daftar kategori.";
            } elseif (isset($pairs[$category."\0".$name])) {
                $this->problems[] = "{$label}: muncul dua kali di kategori '{$category}'.";
            }
            $pairs[(is_string($category) ? $category : '')."\0".$name] = true;

            if (! $this->isNonNegativeInt($item['price'] ?? null)) {
                $this->problems[] = "{$label}: harga harus bilangan bulat ≥ 0.";
            }
            $odooCount = $item['odoo_variant_count'] ?? null;
            if (! is_int($odooCount) || $odooCount < 1) {
                $this->problems[] = "{$label}: odoo_variant_count harus bilangan bulat ≥ 1.";
            }

            $variants = $this->validateVariants($item['variants'] ?? null, $label, $attributes);

            $override = $item['variant_count_override'] ?? null;
            if ($override !== null && ! $this->isText($override, 255)) {
                $this->problems[] = "{$label}: variant_count_override harus null atau teks alasan.";
            }
            if ($override === null && is_int($odooCount) && $variants !== null && $variants !== $odooCount) {
                $this->problems[] = "{$label}: jumlah varian {$variants} ≠ Odoo {$odooCount} tanpa variant_count_override.";
            }

            $this->validatePhoto($item, $label);

            if (! is_bool($item['odoo_kit'] ?? null)) {
                $this->problems[] = "{$label}: odoo_kit harus true/false.";
            }
        }

        return $names;
    }

    /**
     * @param  array<string, array{creates_variants: bool, values: list<string>}>  $attributes
     * @return int|null jumlah varian, atau null bila daftarnya tidak sah
     */
    private function validateVariants(mixed $variants, string $label, array $attributes): ?int
    {
        if (! is_array($variants) || $variants === [] || ! array_is_list($variants)) {
            $this->problems[] = "{$label}: varian wajib berupa daftar yang tidak kosong.";

            return null;
        }

        $seen = [];
        foreach ($variants as $variant) {
            if (! is_array($variant)) {
                $this->problems[] = "{$label}: setiap varian wajib berupa objek.";

                continue;
            }
            $this->checkKeys($variant, ['name', 'price', 'values'], "{$label} varian");
            $name = $variant['name'] ?? null;
            if (! $this->isText($name, 100)) {
                $this->problems[] = "{$label}: nama varian wajib diisi (≤ 100 karakter).";

                continue;
            }
            if (isset($seen[$name])) {
                $this->problems[] = "{$label}: nama varian '{$name}' ganda.";
            }
            if ($this->isApproved() && str_starts_with($name, self::PLACEHOLDER_PREFIX)) {
                $this->problems[] = "{$label}: nama varian sementara '{$name}' tidak boleh ada di katalog yang disetujui.";
            }
            $seen[$name] = true;
            if (! $this->isNonNegativeInt($variant['price'] ?? null)) {
                $this->problems[] = "{$label}: harga varian '{$name}' harus bilangan bulat ≥ 0.";
            }
            if (! is_array($variant['values'] ?? null)) {
                $this->problems[] = "{$label}: values varian '{$name}' wajib berupa objek.";

                continue;
            }
            foreach ($variant['values'] as $attribute => $value) {
                $known = $attributes[$attribute] ?? null;
                if ($known === null || ! $known['creates_variants'] || ! in_array($value, $known['values'], true)) {
                    $this->problems[] = "{$label}: nilai '{$attribute}: ".(is_scalar($value) ? $value : '?')."' tidak ada di atribut pembentuk varian.";
                }
            }
        }

        return count($variants);
    }

    /** @param  array<string, mixed>  $item */
    private function validatePhoto(array $item, string $label): void
    {
        $photo = $item['photo'] ?? null;
        $issue = $item['photo_issue'] ?? null;

        if ($photo === null) {
            if (! in_array($issue, ['truncated', 'missing'], true)) {
                $this->problems[] = "{$label}: photo_issue wajib 'truncated' atau 'missing' bila foto kosong.";
            }

            return;
        }
        if ($issue !== null) {
            $this->problems[] = "{$label}: photo_issue harus kosong bila foto ada.";
        }
        if (! is_string($photo) || ! preg_match('/^[0-9a-f]{12}\.(jpg|png)$/', $photo, $match)) {
            $this->problems[] = "{$label}: nama berkas foto tidak sah.";

            return;
        }

        $file = $this->photoPath($photo);
        if (! is_file($file)) {
            $this->problems[] = "{$label}: foto '{$photo}' tidak ditemukan di folder photos/.";

            return;
        }
        if (filesize($file) > ImageUploadService::MAX_KILOBYTES * 1024) {
            $this->problems[] = "{$label}: foto '{$photo}' melebihi 5 MB.";
        }
        $magic = self::PHOTO_MAGIC[$match[1]];
        if ((string) file_get_contents($file, false, null, 0, strlen($magic)) !== $magic) {
            $this->problems[] = "{$label}: foto '{$photo}' bukan ".strtoupper($match[1]).'.';
        }
    }

    /**
     * @param  array<string, array{creates_variants: bool, values: list<string>}>  $attributes
     * @param  array<string, true>  $itemNames
     */
    private function validateOptionGroups(mixed $groups, array $attributes, array $itemNames): void
    {
        if (! is_array($groups) || ! array_is_list($groups)) {
            $this->problems[] = 'option_groups wajib berupa daftar.';

            return;
        }

        foreach ($groups as $i => $group) {
            if (! is_array($group)) {
                $this->problems[] = 'Grup pilihan #'.($i + 1).' wajib berupa objek.';

                continue;
            }
            $name = $group['name'] ?? null;
            $label = is_string($name) ? "Grup pilihan '{$name}'" : 'Grup pilihan #'.($i + 1);
            $this->checkKeys($group, ['name', 'from_attribute', 'required', 'options', 'attach_to'], $label);

            // ≤ 90 karakter: sisa ruang kolom (100) untuk nama pembeda " (WJM n)" (research D7).
            if (! $this->isText($name, 90)) {
                $this->problems[] = "{$label}: name wajib diisi (≤ 90 karakter).";
            }
            $source = $attributes[$group['from_attribute'] ?? ''] ?? null;
            if ($source === null || $source['creates_variants']) {
                $this->problems[] = "{$label}: from_attribute '".($group['from_attribute'] ?? '?')."' bukan atribut opsi di sumber.";
            }
            if (! is_bool($group['required'] ?? null)) {
                $this->problems[] = "{$label}: required harus true/false.";
            }
            $options = $group['options'] ?? null;
            if (! is_array($options) || $options === [] || ! array_is_list($options)) {
                $this->problems[] = "{$label}: options wajib berupa daftar yang tidak kosong.";
            } elseif ($source !== null) {
                foreach ($options as $option) {
                    if (! in_array($option, $source['values'], true)) {
                        $this->problems[] = "{$label}: opsi '".(is_scalar($option) ? $option : '?')."' tidak ada di atribut '{$group['from_attribute']}'.";
                    }
                }
            }
            $attachTo = $group['attach_to'] ?? null;
            if (! is_array($attachTo) || ! array_is_list($attachTo)) {
                $this->problems[] = "{$label}: attach_to wajib berupa daftar.";

                continue;
            }
            foreach ($attachTo as $item) {
                if (! is_string($item) || ! isset($itemNames[$item])) {
                    $this->problems[] = "{$label}: item '".(is_scalar($item) ? $item : '?')."' tidak ada di katalog.";
                }
            }
        }
    }

    private function validateExcluded(mixed $excluded): void
    {
        if (! is_array($excluded) || ! array_is_list($excluded)) {
            $this->problems[] = 'excluded wajib berupa daftar.';

            return;
        }

        foreach ($excluded as $i => $entry) {
            $label = 'Pengecualian #'.($i + 1);
            if (! is_array($entry)) {
                $this->problems[] = "{$label} wajib berupa objek.";

                continue;
            }
            $this->checkKeys($entry, ['source', 'name', 'reason'], $label);
            if (! in_array($entry['source'] ?? null, self::EXCLUDED_SOURCES, true)) {
                $this->problems[] = "{$label}: source harus products, categories, attributes, atau attribute_values.";
            }
            if (! $this->isText($entry['name'] ?? null, 255) || ! $this->isText($entry['reason'] ?? null, 255)) {
                $this->problems[] = "{$label}: name dan reason wajib diisi.";
            }
        }
    }

    private function validateAccounting(): void
    {
        $rows = $this->source()['rows'];
        $items = count($this->items());
        $excludedProducts = count(array_filter($this->excluded(), fn ($e) => $e['source'] === 'products'));
        if ($items + $excludedProducts !== $rows['products']) {
            $this->problems[] = "Pertanggungjawaban: {$items} item + {$excludedProducts} produk dikecualikan ≠ {$rows['products']} baris produk sumber.";
        }

        $categories = count($this->categories());
        $excludedCategories = count(array_filter($this->excluded(), fn ($e) => $e['source'] === 'categories'));
        if ($categories + $excludedCategories !== $rows['categories']) {
            $this->problems[] = "Pertanggungjawaban: {$categories} kategori + {$excludedCategories} kategori dikecualikan ≠ {$rows['categories']} baris kategori sumber.";
        }

        $values = $this->attributeValueAccounting();
        if ($values['total'] !== $rows['attribute_values']) {
            $this->problems[] = "Pertanggungjawaban: {$values['total']} nilai atribut ≠ {$rows['attribute_values']} baris nilai atribut sumber.";
        }
        if ($values['unaccounted'] !== []) {
            $this->problems[] = 'Pertanggungjawaban: nilai atribut tanpa nasib (tidak dipakai, bukan opsi, tidak dikecualikan): '.implode(', ', $values['unaccounted']).'.';
        }
    }

    /**
     * @param  array<array-key, mixed>  $object
     * @param  list<string>  $allowed
     */
    private function checkKeys(array $object, array $allowed, string $label): void
    {
        foreach (array_keys($object) as $key) {
            if (! in_array($key, $allowed, true)) {
                $this->problems[] = "{$label}: kunci tidak dikenal '{$key}'.";
            }
        }
        foreach ($allowed as $key) {
            if (! array_key_exists($key, $object)) {
                $this->problems[] = "{$label}: kunci '{$key}' wajib ada.";
            }
        }
    }

    private function checkForbiddenKeys(mixed $node, string $where): void
    {
        if (! is_array($node)) {
            return;
        }
        foreach ($node as $key => $child) {
            if (is_string($key) && in_array(strtolower($key), self::FORBIDDEN_KEYS, true)) {
                $this->problems[] = "Kunci terlarang '{$key}' di {$where}: data pribadi tidak boleh ada di katalog.";
            }
            $this->checkForbiddenKeys($child, is_string($key) ? "{$where}.{$key}" : "{$where}[{$key}]");
        }
    }

    private function isText(mixed $value, int $max): bool
    {
        return is_string($value) && trim($value) !== '' && mb_strlen($value) <= $max;
    }

    private function isNonNegativeInt(mixed $value): bool
    {
        return is_int($value) && $value >= 0;
    }

    private function isDate(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) === 1
            && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }
}
