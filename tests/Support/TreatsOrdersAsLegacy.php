<?php

namespace Tests\Support;

use App\Models\Order;

/**
 * 045 Stage 7 — suite LAMA yang mengarakterisasi laporan per PENJUAL (atribusi item ke outlet asal) mensimulasikan data
 * sebelum ada outlet penjualan: sebelum tiap permintaan laporan/event, semua order dan pre-order yang sudah ada ditandai
 * `outlet_backfilled` (cara yang sama migrasi menandai data lama). Atribusi BARU (outlet tempat penjualan) diuji oleh
 * OutletRecapTest dan teman-temannya, tanpa trait ini.
 */
trait TreatsOrdersAsLegacy
{
    public function json($method, $uri, array $data = [], array $headers = [], $options = 0)
    {
        if (str_contains((string) $uri, '/reports') ) {
            Order::withoutGlobalScopes()->update(['outlet_backfilled' => true]);
        }

        return parent::json($method, $uri, $data, $headers, $options);
    }
}
