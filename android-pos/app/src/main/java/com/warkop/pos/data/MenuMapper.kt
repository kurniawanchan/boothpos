package com.warkop.pos.data

import com.warkop.core.domain.Money
import com.warkop.core.network.dto.ModifierGroupDto
import com.warkop.core.network.dto.ProductDto
import com.warkop.pos.model.MenuCatalog
import com.warkop.pos.model.MenuCategory
import com.warkop.pos.model.ModGroup
import com.warkop.pos.model.ModOption
import com.warkop.pos.model.Product
import com.warkop.pos.model.Tone

/**
 * DTO server → model layar (research D3). Satu kartu per VARIAN aktif (server memberi harga per varian; item satu
 * varian tampil dengan nama produknya saja, item multi-varian "Produk — Varian"). Harga yang ditampilkan adalah harga
 * outlet bila ada, selain itu harga dasar — angka tampilan saja; penagihan selalu dihitung ulang server.
 */
object MenuMapper {
    private val TONES = listOf(Tone.INK, Tone.TINT, Tone.ACCENT, Tone.NEUTRAL, Tone.OUTLINE)

    fun toCatalog(products: List<ProductDto>): MenuCatalog {
        val categories = LinkedHashMap<String, MenuCategory>()
        val groups = LinkedHashMap<String, ModGroup>()
        val items = ArrayList<Product>()

        for (p in products.filter { it.isActive }) {
            val catId = (p.categoryId ?: 0L).toString()
            val catName = p.categoryName ?: "Lainnya"
            categories.getOrPut(catId) { MenuCategory(catId, catName, catName.take(1).uppercase(), TONES[categories.size % TONES.size]) }

            val groupIds = p.modifierGroups.map { g -> toGroup(g).also { groups.putIfAbsent(it.id, it) }.id }
            val active = p.variants.filter { it.isActive }
            for (v in active) {
                items += Product(
                    id = v.id.toString(),
                    icon = iconFor(p.name, catName),
                    cat = catId,
                    name = if (active.size > 1 && v.variantName.isNotBlank()) "${p.name} — ${v.variantName}" else p.name,
                    price = Money.parse(v.outletPrice ?: v.sellPrice).toInt(),
                    groups = groupIds,
                    imageUrl = v.imageUrl ?: p.imageUrl,
                )
            }
        }
        return MenuCatalog(categories.values.toList(), items, groups)
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
