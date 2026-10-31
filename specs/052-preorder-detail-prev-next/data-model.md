**Klasifikasi**: INTERNAL

# Data Model: Navigasi Sebelumnya/Berikutnya di Detail Pre-order

**Tidak ada perubahan skema, tidak ada migrasi, tidak ada data baru yang disimpan.**

## Entitas yang dibaca

### Pre-order (`preorders`, sudah ada, `HasDataMode`)

Hanya dua atribut yang dipakai untuk navigasi:

| Atribut | Peran |
|---|---|
| `id` | identitas tujuan navigasi; pengikat urutan stabil (research Decision 3) |
| `preorder_number` | label tombol (`PO-YYYYMMDD-NNNN`) |

Kolom yang ikut menentukan urutan (sudah ada, tidak berubah): `preorder_number`, `status`, `fulfillment`, `total_amount`, `paid_amount` (lewat ekspresi `outstanding`), `created_at`, `updated_at`, dan `customers.name` (JOIN).

## Objek turunan (tidak disimpan)

### Konteks daftar

Parameter yang sudah dikirim SPA ke `GET /preorders` lewat `params` milik `usePaginatedList`:

| Parameter | Bentuk | Catatan |
|---|---|---|
| `status[]`, `dispatch_status[]`, `fulfillment[]`, `artist_id[]` | array | sama persis dengan `applyFilters()` |
| `event_id`, `customer_id` | integer | |
| `search` | string | |
| `sort_by` | whitelist `applySort()` | nilai tak dikenal → urutan bawaan |
| `sort_dir` | `asc`/`desc` | bawaan `desc` |
| `page`, `per_page` | — | **diabaikan** oleh endpoint tetangga (posisi dihitung atas seluruh daftar tersaring) |

### Tetangga (respons endpoint)

| Field | Tipe | Aturan |
|---|---|---|
| `in_list` | boolean | `true` bila pre-order yang dibuka lolos filter aktif |
| `position` | integer \| null | urutan 1-based dalam daftar tersaring; `null` bila `in_list = false` |
| `total` | integer | jumlah baris daftar tersaring (= `meta.total` daftar dengan filter yang sama) |
| `previous` | `{id, preorder_number}` \| null | `null` di posisi 1 atau `in_list = false` |
| `next` | `{id, preorder_number}` \| null | `null` di posisi terakhir atau `in_list = false` |

**Invarian**:
- Untuk konteks yang sama, `next` dari A = B ⇔ `previous` dari B = A (dijamin pengikat `preorders.id`).
- Menelusuri `next` dari posisi 1 sampai `next = null` menghasilkan urutan id yang identik dengan menggabungkan semua halaman `GET /preorders` berurutan (SC-003).
- Hanya pre-order dari mode DEMO/LIVE aktif yang pernah muncul (global scope `DataModeScope` pada builder yang sama).

## State SPA (PreordersView, tidak disimpan)

| State | Arti |
|---|---|
| `neighbors` | respons terakhir yang masih berlaku untuk `detail.id` |
| `neighborsLoading` | memuat tetangga; tombol nonaktif |
| `detailSeq` | penghitung permintaan; respons dengan nomor lama dibuang (FR-008) |

Tombol aktif hanya bila: tetangga ada, tidak sedang memuat, dan `showShipmentForm` tertutup (FR-011).
