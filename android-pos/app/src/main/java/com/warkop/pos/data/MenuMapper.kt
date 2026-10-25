package com.warkop.pos.data

import com.warkop.core.domain.Money
import com.warkop.core.network.dto.ModifierGroupDto
import com.warkop.core.network.dto.PosCategoryDto
import com.warkop.core.network.dto.ProductDto
import com.warkop.pos.model.MenuCatalog
import com.warkop.pos.model.MenuCategory
import com.warkop.pos.model.ModGroup
import com.warkop.pos.model.ModOption
import com.warkop.pos.model.Product
import com.warkop.pos.model.ProductEntry
import com.warkop.pos.model.Tone

/**
 * DTO server → model layar (research D3). Daftar datar [MenuCatalog.products] = satu [Product] per VARIAN aktif (server memberi harga per varian; item satu
 * varian tampil dengan nama produknya saja, item multi-varian "Produk — Varian"). Harga yang ditampilkan adalah harga
 * outlet bila ada, selain itu harga dasar — angka tampilan saja; penagihan selalu dihitung ulang server.
 */
object MenuMapper {
    private val TONES = listOf(Tone.INK, Tone.TINT, Tone.ACCENT, Tone.NEUTRAL, Tone.OUTLINE)

    /** Kelompok untuk produk yang kategorinya tidak ada di daftar server; produk tidak pernah dibuang diam-diam. */
    const val LAINNYA = "lainnya"

    /**
     * 055 / T010 — kategori datang dari server (sudah menurut mode aktif), termasuk kategori yang belum punya produk.
     * Tidak lagi diturunkan dari produk (research R1): itu penyebab kategori hilang dari tablet.
     */
    fun toCatalog(products: List<ProductDto>, serverCategories: List<PosCategoryDto>, serverBase: String? = null): MenuCatalog {
        val categories = LinkedHashMap<String, MenuCategory>()
        serverCategories.forEachIndexed { i, c ->
            val id = c.id.toString()
            categories[id] = MenuCategory(id, c.name, c.name.take(1).uppercase(), TONES[i % TONES.size])
        }
        val groups = LinkedHashMap<String, ModGroup>()
        val items = ArrayList<Product>()
        val entries = ArrayList<ProductEntry>()

        for (p in products.filter { it.isActive }) {
            val known = p.categoryId?.toString()?.takeIf { it in categories }
            val catId = known ?: LAINNYA
            if (known == null && LAINNYA !in categories) categories[LAINNYA] = MenuCategory(LAINNYA, "Lainnya", "L", Tone.OUTLINE)
            val catName = categories.getValue(catId).name

            val groupIds = p.modifierGroups.map { g -> toGroup(g).also { groups.putIfAbsent(it.id, it) }.id }
            val active = p.variants.filter { it.isActive }
            // 075 — produk tanpa varian aktif tidak ditampilkan (tidak bisa dipesan); sebelumnya pun tidak punya kartu.
            val productImage = ServerUrl.resolve(serverBase, p.imageUrl)
            val variants = ArrayList<Product>()
            for (v in active) {
                variants += Product(
                    id = v.id.toString(),
                    icon = iconFor(p.name, catName),
                    cat = catId,
                    name = if (active.size > 1 && v.variantName.isNotBlank()) "${p.name} — ${v.variantName}" else p.name,
                    price = Money.parse(v.outletPrice ?: v.sellPrice).toInt(),
                    groups = groupIds,
                    // 075 — URL dari server memakai host APP_URL (bisa localhost); pasang ke alamat server tablet.
                    imageUrl = ServerUrl.resolve(serverBase, v.imageUrl) ?: productImage,
                    variantName = v.variantName, code = v.sku.orEmpty(),
                    // Sama seperti POS web: saldo outlet bila ada, selain itu total; hidangan tanpa stok fisik tidak punya angka.
                    stock = if (v.trackStock) (v.outletStock ?: v.currentStock) else null,
                )
            }
            items += variants
            if (variants.isNotEmpty()) entries += ProductEntry(
                id = p.id.toString(), name = p.name, cat = catId, categoryName = catName,
                imageUrl = productImage ?: variants.firstNotNullOfOrNull { it.imageUrl }, variants = variants,
            )
        }
        return MenuCatalog(categories.values.toList(), items, groups, entries)
    }

    private fun toGroup(g: ModifierGroupDto) = ModGroup(
        id = g.id.toString(), name = g.name,
        // Server: is_required + min/max; grup wajib minimal 1 pilihan walau min_select tertulis 0.
        min = if (g.isRequired) maxOf(1, g.minSelect) else g.minSelect,
        max = maxOf(1, g.maxSelect),
        options = g.options.map { ModOption(it.name, Money.parse(it.priceDelta).toInt(), it.id) },
    )

    /** Ikon bawaan dari nama/kategori (foto belum dimuat di versi ini). */
    fun iconFor(name: String, category: String): String {
        val n = "$name $category".lowercase()
        return when {
            "kopi" in n || "coffee" in n || "americano" in n || "cappuccino" in n -> "coffee"
            "teh" in n || "tea" in n -> "cup-soda"
            "susu" in n -> "milk"
            "jeruk" in n || "lemon" in n -> "citrus"
            "air" in n -> "glass-water"
            "mie" in n || "indomie" in n -> "soup"
            "roti" in n -> "sandwich"
            "ayam" in n -> "drumstick"
            "telur" in n -> "egg"
            "nasi" in n || "makan" in n -> "utensils"
            "pisang" in n -> "banana"
            "goreng" in n || "camilan" in n -> "cookie"
            else -> "utensils"
        }
    }
}
