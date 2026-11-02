# API Contract: Laporan Bahan (088)

**Klasifikasi**: INTERNAL · Semua rute di bawah `/api/v1`, `auth:sanctum`, mode data aktif, `OutletFilter` (header `X-Outlet-Id` menyempitkan). Uang = string 2 desimal; jumlah bahan = string 3 desimal. `docs/openapi-pos-mvp.yaml` diperbarui di commit yang sama.

## 1. GET /reports/materials/position

Gerbang: menu `reports` (403 lainnya).

Query: `window_days` (7), `risk_days` (3), `target_days` (7), `status[]`, `kind[]`, `search`.

```json
{
  "data": [{
    "material_id": 10, "code": "INDM-AB", "name": "Indomie Ayam Bawang", "unit": "pcs", "kind": "ingredient",
    "outlet_id": 14, "outlet_code": "WDM",
    "on_hand": "-1.000", "daily_usage": "4.000", "days_left": 0, "status": "negative", "usage_untracked": false,
    "unit_cost": "3000.00", "stock_value": "-3000.00",
    "at_risk_options": [{"option_id": 51, "option": "AYAM BAWANG", "group": "Rasa (Indomie)", "portions": 0}],
    "at_risk_products": [{"product_id": 789, "name": "Indomie Kuah"}],
    "suggestion": {"qty": "29.000", "vendor_id": 3, "vendor": "Tukang Grosir Indomie", "unit_price": "3000.00", "cost": "87000.00"}
  }],
  "summary": {"negative": 1, "out": 0, "low": 2, "ok": 5, "unused": 3, "at_risk_products": 2, "stock_value": "250000.00", "suggestion_cost": "120000.00"},
  "params": {"window_days": 7, "risk_days": 3, "target_days": 7},
  "computed_at": "2026-11-01T02:00:00+07:00"
}
```

`summary` dihitung dari baris SESUDAH saringan outlet/jenis tetapi SEBELUM saringan `status` (supaya kartu ringkasan tetap lengkap saat satu status dipilih).

## 2. GET /reports/materials/usage

Gerbang: menu `reports`. Query: `date_from`, `date_to` (bawaan 7 hari tanggal bisnis terakhir), `kind[]`, `search`.

```json
{
  "data": [{"material_id": 9, "name": "Indomie Soto", "unit": "pcs", "outlet_id": 14, "outlet_code": "WDM",
            "sold_net": "12.000", "wasted": "1.000", "adjusted_in": "20.000", "adjusted_out": "0.000", "purchased": "0.000",
            "transferred_in": "0.000", "transferred_out": "0.000", "transfer_cancelled": "0.000",
            "sold_net_value": "36000.00", "wasted_value": "3000.00", "adjusted_out_value": "0.00"}],
  "totals": {"sold_net_value": "36000.00", "wasted_value": "3000.00", "adjusted_out_value": "0.00", "adjusted_in_value": "60000.00", "purchased_value": "0.00"},
  "trend": [{"date": "2026-10-31", "sold_net_value": "6000.00", "wasted_value": "0.00"}],
  "top_used": [{"material_id": 9, "name": "Indomie Soto", "value": "36000.00"}],
  "top_wasted": [{"material_id": 9, "name": "Indomie Soto", "value": "3000.00"}]
}
```

## 3. GET /reports/materials/{material}/usage-by-menu

Gerbang: menu `reports`. Query: `date_from`, `date_to`.

```json
{"data": [{"product_id": 789, "product": "Indomie Kuah", "options": ["SOTO"], "qty": "12.000"}]}
```

## 4. POST /reports/materials/purchase-drafts

Gerbang: menu `reports` DAN `can('create', PurchaseOrder::class)` (403). Outlet = outlet tunggal yang berlaku; tidak ada → 422 `{errors: {outlet: [...]}}`.

Body: `{"lines": [{"material_id": 10, "qty": 29}, {"material_id": 12, "qty": 5}]}`

201:

```json
{
  "created": [{"purchase_order_id": 41, "po_number": "PO-20261101-0001", "vendor_id": 3, "vendor": "Tukang Grosir Indomie", "line_count": 1, "total": "87000.00"}],
  "refused": [{"material_id": 12, "reason": "no_vendor"}]
}
```

Semua `refused` dan tidak ada yang dibuat → 422 dengan `refused` di badan. Idempotensi: header `Idempotency-Key` opsional; kunci yang sama mengembalikan hasil yang sama tanpa membuat PO lagi (pola `Idempotency-Key` yang ada).

## 5. GET /reports/materials/export

Gerbang: menu `reports`. Query = gabungan #1 dan #2. Respons: `.xlsx` tiga sheet (Posisi & risiko, Saran pembelian, Pemakaian periode) lewat `SafeStringExport`.

## 6. GET /dashboard/materials

Gerbang: menu `dashboard`. Query: `period` (`today|7d|30d`, sama dengan `summary`).

```json
{
  "counts": {"negative": 1, "out": 0, "low": 2, "at_risk_products": 2},
  "low_stock_products": 4,
  "stock_value": "250000.00",
  "wasted_value": "3000.00",
  "params": {"window_days": 7, "risk_days": 3},
  "computed_at": "2026-11-01T02:00:00+07:00"
}
```

`stock_value`/`wasted_value` hanya untuk pengguna dengan menu `reports` (kunci tidak ada sama sekali bagi yang lain). Cache 30 detik (research D7).
