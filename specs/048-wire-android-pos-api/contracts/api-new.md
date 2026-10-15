# Kontrak: rute baru/diubah (server)

**Classification**: INTERNAL. Konvensi: 422 validasi, 409 konflik aturan, 403 izin/outlet, 401 sesi, uang berupa string `"0.00"`. Setiap rute di sini WAJIB masuk `docs/openapi-pos-mvp.yaml` pada commit yang sama.

## S1
| Rute | Tujuan | Catatan |
|---|---|---|
| `POST /devices/pairings` | daftarkan terminal `{name, code?}` → `{id, code}` | owner/admin; `outlet_id` dari konteks, bukan body; kode otomatis `T##` bila kosong |
| `GET /devices` | daftar perangkat outlet | owner/admin |
| `DELETE /devices/{id}` | cabut | token perangkat dicabut |
| `POST /tabs` (diubah) | + `guest_count` (1–99), `device_id` diturunkan dari token/header `X-Device-Id` | idempoten dengan `local_ref` (ada) |
| `POST /tabs/{id}/prebill` | tandai pra-bill (`prebill_at`, `prebill_count`) → TabResource | tidak mengubah status; tidak butuh shift |
| `GET /tabs/{id}` / `TabResource` (diubah) | + `tax_amount`, `tax_rate`, `queue_no`, `receipt_no`, `guest_count`, `prebill_at`, `device` | field baru selalu ada (nilai `null`/`"0.00"` bila belum ada) |
| `GET /floor` (diubah) | + `guest_count`, `bill_printed`, `device_code` per order terbuka | `version` tetap bebas nilai bergantung jam |
| `GET /orders/{id}/receipt` (diubah) | + `receipt_no`, `queue_no`, `tax`, `copy_no` | |
| `GET /settings/features` (diubah) | + `tax_rate`, `tax_label`, `variance_tolerance`, `cash_out_approval_threshold` | dapat dibaca semua peran (hanya angka tampilan) |

Pengiriman ke dapur, penambahan baris, pembayaran tunai: **rute yang sudah ada dipakai apa adanya** (`/tabs/{id}/send|items|payments`).

## S2
| Rute | Tujuan |
|---|---|
| `POST /sessions` (diubah) | + `opening_denominations: [{denomination, qty}]`; `opening_cash` diturunkan dari jumlahnya (tetap diterima untuk klien lama) |
| `POST /sessions/{id}/cash-movements` | `{direction, reason_code, reason_note?, amount, override_token?}`; kas keluar > ambang tanpa token → 409 `override_required` |
| `GET /sessions/{id}/cash-movements` | daftar |
| `GET /sessions/{id}/x-report` | angka shift dihitung langsung (tidak tersimpan) |
| `POST /sessions/{id}/close` (diubah) | + `counted_by_denomination`, `edc_batch_total`, `variance_override_token?`; menolak: QRIS tertunda (409 `pending_payments`), selisih > toleransi tanpa token (409 `override_required`); respons memuat hasil + `z_report` |

## S3
| Rute | Tujuan |
|---|---|
| `POST /overrides` | `{action, subject_hash, approver_user_id, pin}` → `{override_token, expires_at}`; 401 `invalid_pin` (hitung kunci sama dengan login), 403 `approver_not_permitted`, 409 `same_person` |
| `GET /overrides/approvers?action=` | daftar penyetuju yang memenuhi syarat untuk aksi (tanpa peminta) |
| `DELETE /tabs/{id}/items/{item}` (diubah) | + `reason_code`, `kitchen_status` (`not_started|started|made`), `stock_decision` (`return|waste`), `override_token` (wajib untuk baris terkirim bila peminta bukan pemegang izin) |
| `POST /tabs/{id}/cancel` | `{reason_code, override_token?}`; 409 bila ada pembayaran (`has_payments`) |
| `POST /tabs/{id}/payments` (diubah) | + metode `edc` (`approval_code`) dan `qris` (tertunda); 409 `duplicate_edc` (dengan `confirm_duplicate=true` boleh lanjut); 409 `pending_qris` memblokir non-tunai lain |
| `POST /tabs/{id}/payments/{pay}/verify` (diubah) | + `reference` (≥4), `override_token`; dan `POST …/fail` (tandai tidak ditemukan) |
| `POST /products/{id}/sold-out` (diubah) | + `override_token` untuk peran tanpa izin `menu.availability.set` |

## S4
| Rute | Tujuan |
|---|---|
| `POST /tabs/{id}/discounts` | `{percent, reason_code, scope: order\|line, line_id?, override_token?}`; batas per peran ditegakkan; di atas batas tanpa token → 409 `override_required` |
| `DELETE /tabs/{id}/discounts` | hapus diskon order |
| `POST /tabs/{id}/split` (diubah) | + `mode: equal, parts` (2–5) |
| `POST /orders/{id}/reprints` | cetak ulang: salinan pertama gratis; berikutnya wajib `override_token`; menambah `copy_no` |
| `POST /orders/{id}/refund` | refund penuh order lunas; online saja; `override_token`; menambah `return` stok dan membalik kas tunai shift |
| `POST /devices/heartbeat` | `{app_version, clock_ms, queue_depth}` → `{server_time_ms, skew_seconds, menu_release}` |

## Kode galat mesin-terbaca
`override_required`, `approver_not_permitted`, `same_person`, `override_expired`, `override_mismatch`, `pending_payments`, `pending_qris`, `duplicate_edc`, `has_payments`, `shift_required`, `pin_locked`, `invalid_pin`, `schema_outdated`. Setiap respons galat membawa `message` (Indonesia, ramah) dan `code`.
