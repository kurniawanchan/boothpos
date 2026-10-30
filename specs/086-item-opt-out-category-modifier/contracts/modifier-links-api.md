# Kontrak: perubahan API pengecualian grup modifier

**Klasifikasi**: INTERNAL · Sumber kebenaran akhir: `docs/openapi-pos-mvp.yaml` (diubah di commit yang sama saat implementasi).

## `PUT /products/{id}/modifier-links` (diperluas)

Izin: `ProductPolicy::update` (sama dengan sekarang). Tanpa izin: 403.

Permintaan:

| Field | Aturan |
|-------|--------|
| `links` | tetap seperti sekarang (`present`, array ≤ 30) |
| `excluded_group_ids` | OPSIONAL, array bilangan bulat ≤ 30. Tidak ada kunci: pengecualian TIDAK berubah. Ada (termasuk `[]`): menggantikan seluruh pengecualian item. Tiap id harus grup yang ada (422 `excluded_group_ids.N` bila tidak); id ganda ditolak 422 |

Respons: tetap `{ applied, change }`. Rilis aktif: `applied:false` dan `change` berjenis `modifier_link` yang payload-nya membawa `excluded_group_ids` bila dikirim.

Kode status mengikuti konvensi: 422 bentuk/validasi, 403 izin, tanpa 409 baru.

Efek: dalam SATU transaksi dengan penggantian tautan; satu entri log `modifier_links_updated` (nilai lama/baru pengecualian ikut bila dikirim).

## `GET /products/{id}` (detail halaman Produk, diperluas)

- `modifier_links`: grup EFEKTIF (baris `source:category` untuk grup yang dikecualikan tidak lagi muncul).
- `modifier_excluded`: `[{group_id, name, options[]}]` untuk grup yang masih diwarisi kategori tetapi dikecualikan item ini. Kosong bila tidak ada.

## Yang tidak berubah

- `GET /products?with_modifiers=1` dan `GET /variants/lookup?with_modifiers=1` (bentuk sama; grup yang dikecualikan tidak ada di `modifier_groups`).
- `POST /orders`, `POST /tabs/{id}/items`: opsi dari grup yang dikecualikan ditolak seperti opsi tak dikenal (`restaurant.modifier_option_invalid`).
- Android: tidak ada perubahan kode; membaca grup efektif dari server.
