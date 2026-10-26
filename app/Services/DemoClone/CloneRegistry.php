<?php

namespace App\Services\DemoClone;

use InvalidArgumentException;

/**
 * Daftar tabel yang disalin LIVE → DEMO, urut FK (induk dulu). Satu-satunya
 * tempat yang tahu bentuk graf data untuk fitur ini: cloner, fingerprint,
 * dan pemeriksaan integritas semuanya membaca dari sini.
 *
 * Kunci spec:
 *  fks             kolom => tabel induk; nilai dipetakan ke id baru. Kolom ke
 *                  users/payment_channels TIDAK di sini (dibagi, nilai tetap).
 *  deferred        kolom self-FK: di-insert NULL lalu ditambal setelah tabel selesai.
 *  suffix          kolom => lebar maks; nilai diberi akhiran "-DM".
 *  uuid            kolom yang diganti UUID baru (bila tidak NULL).
 *  files           kolom => disk; berkasnya DISALIN, tidak pernah dibagi.
 *  required_files  kolom berkas NOT NULL: baris dilewati bila berkas sumber hilang.
 *  via             "induk.kolom" untuk tabel tanpa kolom data_mode (ikut induknya).
 */
final class CloneRegistry
{
    private const DEFAULTS = [
        'fks' => [],
        'deferred' => [],
        'suffix' => [],
        'uuid' => [],
        'files' => [],
        'required_files' => [],
        'via' => null,
    ];

    /** @return array<string,array> */
    public static function tables(): array
    {
        $raw = [
            'events' => [],
            'artists' => [],
            'categories' => ['deferred' => ['parent_id' => 'categories'], 'files' => ['image_path' => 'public']],
            'vendors' => ['suffix' => ['code' => 20]],
            'materials' => ['suffix' => ['code' => 20]],
            'customers' => [],
            'products' => [
                'fks' => ['artist_id' => 'artists', 'category_id' => 'categories'],
                'files' => ['image_path' => 'public'],
            ],
            'product_variants' => ['fks' => ['product_id' => 'products'], 'files' => ['image_path' => 'public']],
            'vendor_material_prices' => ['fks' => ['vendor_id' => 'vendors', 'material_id' => 'materials']],
            'purchase_orders' => [
                'fks' => ['vendor_id' => 'vendors', 'artist_id' => 'artists'],
                'suffix' => ['po_number' => 30],
            ],
            'purchase_order_items' => [
                'fks' => ['purchase_order_id' => 'purchase_orders', 'material_id' => 'materials', 'product_id' => 'products'],
                'via' => 'purchase_orders.purchase_order_id',
            ],
            'product_variant_bom_lines' => [
                'fks' => [
                    'product_variant_id' => 'product_variants', 'material_id' => 'materials',
                    'vendor_id' => 'vendors', 'purchase_order_item_id' => 'purchase_order_items',
                ],
            ],
            'cashier_sessions' => ['fks' => ['event_id' => 'events']],
            'session_opening_cash_entries' => ['fks' => ['session_id' => 'cashier_sessions', 'artist_id' => 'artists']],
            'orders' => [
                'fks' => ['event_id' => 'events', 'session_id' => 'cashier_sessions', 'customer_id' => 'customers'],
                'suffix' => ['order_number' => 30],
                'uuid' => ['local_ref'],
            ],
            'order_items' => ['fks' => ['order_id' => 'orders', 'variant_id' => 'product_variants', 'artist_id' => 'artists']],
            'preorders' => [
                'fks' => ['customer_id' => 'customers', 'event_id' => 'events'],
                'deferred' => ['source_preorder_id' => 'preorders'],
                'suffix' => ['preorder_number' => 30],
            ],
            'preorder_items' => ['fks' => ['preorder_id' => 'preorders', 'variant_id' => 'product_variants', 'artist_id' => 'artists']],
            'shipments' => ['fks' => ['preorder_id' => 'preorders']],
            'payments' => [
                'fks' => [
                    'order_id' => 'orders', 'preorder_id' => 'preorders',
                    'purchase_order_id' => 'purchase_orders', 'session_id' => 'cashier_sessions',
                ],
                'uuid' => ['client_ref'],
            ],
            'payment_proofs' => [
                'fks' => ['payment_id' => 'payments'],
                'uuid' => ['proof_token'],
                'files' => ['file_path' => 'local'],
                'required_files' => ['file_path'],
            ],
            'stock_movements' => ['fks' => ['variant_id' => 'product_variants']],
            'material_stock_movements' => ['fks' => ['material_id' => 'materials']],
            'artist_settlements' => ['fks' => ['event_id' => 'events', 'artist_id' => 'artists']],
            // 076 — settlement seller. Induk dulu, anak mengikuti mode induknya ('via').
            // Berkas tanda tangan / bukti bayar DISALIN (disk privat), tidak pernah dibagi.
            'seller_settlements' => [
                'fks' => ['event_id' => 'events', 'artist_id' => 'artists'],
                'suffix' => ['number' => 30],
            ],
            'seller_settlement_sources' => [
                'fks' => ['settlement_id' => 'seller_settlements'],
                'via' => 'seller_settlements.settlement_id',
            ],
            'seller_settlement_adjustments' => [
                'fks' => ['settlement_id' => 'seller_settlements'],
                'via' => 'seller_settlements.settlement_id',
            ],
            'seller_settlement_acknowledgements' => [
                'fks' => ['settlement_id' => 'seller_settlements'],
                'files' => ['signature_path' => 'local'],
                'via' => 'seller_settlements.settlement_id',
            ],
            'seller_settlement_payments' => [
                'fks' => ['settlement_id' => 'seller_settlements'],
                'uuid' => ['client_ref'],
                'files' => ['proof_path' => 'local'],
                'via' => 'seller_settlements.settlement_id',
            ],
        ];

        return array_map(fn (array $spec) => $spec + self::DEFAULTS, $raw);
    }

    public static function spec(string $table): array
    {
        return self::tables()[$table] ?? throw new InvalidArgumentException("Tabel {$table} tidak ada di registry.");
    }

    /**
     * Fragmen WHERE yang memilih baris satu mode. $mode hanya boleh konstanta
     * 'live'/'demo' — string ini disisipkan ke SQL, jadi dikunci.
     */
    public static function scope(string $table, string $mode): string
    {
        if (! in_array($mode, ['live', 'demo'], true)) {
            throw new InvalidArgumentException('Mode harus live atau demo.');
        }

        $via = self::spec($table)['via'];

        if ($via === null) {
            return "data_mode = '{$mode}'";
        }

        [$parent, $column] = explode('.', $via);

        return "{$column} IN (SELECT id FROM {$parent} WHERE data_mode = '{$mode}')";
    }

    /** @return array<string,string> kolom => tabel induk (FK biasa + self-FK) */
    public static function references(string $table): array
    {
        $spec = self::spec($table);

        return $spec['fks'] + $spec['deferred'];
    }
}
