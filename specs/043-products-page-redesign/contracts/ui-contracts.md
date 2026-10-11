**Classification**: INTERNAL

# Contract: Products UI

Source of look and structure: the nine screenshots listed in `spec.md` ("Design reference"). Tokens only (no hex), shared page kit (041), hidden-not-disabled, Indonesian source copy with English parity.

## List page (`/products`)

1. `PageHeader`: title "Produk"; description "Master item · harga belum termasuk pajak" + (only when releases are on) "· rilis menu v{n} aktif"; actions *Impor / ekspor* (existing import/export), *Ubah harga massal* (permission `menu.price.bulk`), *+ Tambah item* (menu `products`).
2. `ReleaseBanner` (only when `release_enabled` and unreleased > 0): "{n} perubahan belum dirilis" + summary + *Rilis v{next} ke outlet…* (permission `menu.release.publish`); a link to pending approvals for approvers.
3. Figure strip (`KpiStrip`-style, four cells, same grid as 042): Menu item aktif · 86 hari ini · Belum dirilis (hidden when releases off; a three-cell strip then) · Kualitas data; each cell has label, big value, one muted line; "Kualitas data" and "86 hari ini" act as filters.
4. Item-type `TabBar` with counts: Menu item, Bahan baku, Setengah jadi, Kemasan, Item ritel, Modifier & combo. Non-menu tabs reuse the existing materials list (filtered by kind) and the modifier-groups list inside the same frame; "Item ritel" lists retail products with the same grid/table.
5. `FilterBar`: search (name or SKU), Kategori select, Status select, "{n} data", `ViewToggle` (Grid | Tabel; persisted per user), *+ Menu item*.
6. **Grid** (`ItemGrid`/`ItemCard`): 5 columns on wide screens, fewer on narrow; photo area with the placeholder when no image; status badge top-right; name + base price; one muted line (variants/modifiers); `AvailabilityChips` (Stage A: one chip "Ada"/"86"; B: one per outlet with outlet code). The whole card opens the item page; keyboard-focusable.
7. **Table** (`ItemTable` on `DataTable`): select checkbox, Item (thumbnail, name, "Kategori · Station"), SKU, Varian · modifier, Harga dasar (right-aligned), Ketersediaan (chips), Status (pill); sortable headers (name, SKU, price, status); selection shows `BulkBar` with the bulk actions the role may use (bulk 86, bulk discontinue are out of scope; the bar offers *Ubah harga massal* for the selection and *Ekspor*).
8. Pagination "Menampilkan 1–15 dari 24" + pager; empty state with "Atur ulang filter"; per-region loading/error-with-Retry.
9. URL state (`useQueryState`): `tab, q, category, status, quality, view, sort, page`; unknown values ignored; reload restores.

## Item page (`/products/:id`)

- "← Kembali ke Produk" (returns with the previous list query).
- Header card: "Menu item · {code}", name, badges (status; 86 badge with reason when marked; category); action row: *Edit item* (primary), *Tandai 86 di outlet…* (Stage A: "Tandai 86"), *Discontinue* (danger outline) — each hidden when the role cannot do it; *Kembalikan (undo 86)* replaces the 86 button while marked; *Aktifkan lagi* replaces Discontinue when discontinued.
- Facts grid: Nama pendek, Kategori pajak, Station (with "mengikuti kategori" note), Estimasi persiapan ("3 menit"), Harga dasar, Rilis menu (when on).
- Sections, each with its own loading/error state and an action button on the right: **Varian & harga** (table; per-outlet price columns appear in Stage B with the grey "mengikuti harga dasar" rule; *Kelola varian*), **Grup modifier** (order, group, rule "Opsional · 0–1" / "Wajib · 1–2", options; *Kelola modifier*), **Ketersediaan per outlet** (Stage A: one row for the install; B: per outlet with daily quota and channels), **Resep (BOM) v{n}** (header "Biaya porsi Rp … · food cost x%", lines table Bahan/Qty/UOM/Susut/Biaya, totals "Biaya porsi teoretis" and "Margin kotor"; *Edit resep*; for a BOM-driven item the existing BOM summary and its button), **Riwayat harga** (timeline, newest first, date, price, "Diajukan … · disetujui … · rilis v…", final "Item dibuat").

## Dialogs and drawers

| Piece | Contract |
|---|---|
| `SoldOutDialog` | "Tandai {name} sold out?"; text states what the system does (terminals within 5 s, until the business day ends; online channels only if an integration exists); required *Reason code* select from `GET /reason-codes?category=sold_out` + optional note; *Batal* / *Tandai 86* (disabled until a reason is chosen); (B) outlet checkboxes. |
| `DiscontinueDialog` | "Discontinue {name}?"; "Item tidak dapat ditambahkan ke order baru, tetapi tetap tampil di histori dan laporan." + (release on) "Berlaku setelah rilis menu berikutnya."; reason select; *Batal* / *Discontinue* (danger). |
| `BulkPriceDrawer` | sections *Cakupan* (Kategori multi-checkbox, Outlet checkboxes — "Semua (harga dasar)" only in A), *Perubahan* (Persentase/Nominal toggle, Nilai, Berlaku mulai date, rounding switch "Bulatkan ke Rp 500 terdekat"), a **preview table** (old → new, count) fetched on a debounce; footer *Batal* / *Ajukan perubahan* (release+approval on) or *Terapkan perubahan* (off). |
| `VariantsDrawer` | table Varian · SKU · Harga dasar · (B: price per outlet) · Aktif · remove-for-new-only; "+ Tambah varian"; guidance text about variants vs modifiers; rule text about the approval threshold (only when releases on); *Berlaku mulai* date (empty = next release / immediately); *Simpan & masukkan ke rilis* / *Simpan*. |
| `ModifierLinksDrawer` | table Grup (select), Wajib, Min, Maks, remove; "+ Tautkan grup"; helper text about the POS asking required groups; *Simpan modifier*. |
| `RecipeDrawer` | versioning sentence (no stock-deduction promise), Yield + unit "porsi", Berlaku mulai, variant checkboxes, ingredients table Bahan (select, grouped by kind) · Qty · UOM (read-only from the material) · Susut % · Biaya, "+ Tambah bahan", live "Biaya porsi", change note, *Simpan sebagai v{n+1}*; shows "biaya belum lengkap" when a line has no cost. |
| `ReleaseDialog` (release on) | list of changes to include with checkboxes (awaiting-approval ones shown disabled with the reason), publish now or schedule, note; (B) outlet targets with per-outlet result after publishing; *Rilis v{n}*. |
| `ApprovalList` (release on, approvers) | pending price approvals: item, old → new, difference %, requester, *Setujui* / *Tolak* (reason). |

## New kit pieces

`ViewToggle` (segmented, `role="radiogroup"`), `AvailabilityChips` (text + colour, "Ada"/"86"), `ReleaseBanner`, `PriceHistoryTimeline`; reuse `PageHeader`, `KpiStrip`, `TabBar`, `FilterBar`, `BulkBar`, `DataTable`, `FormDrawer`, `ReasonDialog`, `ConfirmDialog`, `ErrorState`, `LoadingState`, `EmptyState`.

## Accessibility and permissions

Status is never colour-only (text label + icon); chips carry text; focus rings visible; tabs/toggle keyboard operable; the grid card is a single link target. Controls the role cannot use are not rendered; the server refuses the same actions.
