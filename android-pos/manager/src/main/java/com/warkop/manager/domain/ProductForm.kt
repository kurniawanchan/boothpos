package com.warkop.manager.domain

import kotlinx.serialization.Serializable
import java.math.BigDecimal
import java.math.RoundingMode
import java.util.UUID

/**
 * 094 / T065 — isian formulir produk, cermin `ProductFormDrawer.vue` + `StoreProductRequest`/`UpdateProductRequest`.
 * Uang disimpan sebagai teks rupiah bulat ("15000") saat mengetik dan dikirim "15000.00" (`toMoneyString` web).
 */
@Serializable data class VariantRow(
    val key: String = UUID.randomUUID().toString(),
    val id: Long? = null,
    val name: String = "",
    val sellPrice: String = "",
    val costPrice: String = "0",
    val lowStockAlert: String = "",
    val trackStock: Boolean = true,
    val isActive: Boolean = true,
    /** Stok di outlet terpilih saat formulir dibuka (atau setelah koreksi berhasil) — dasar menghitung selisih. */
    val originalStock: String = "",
    val currentStock: String = "",
    /** BOM lengkap → harga modal dikunci server (409 bila diubah manual). */
    val bomComplete: Boolean = false,
    /** Foto baru yang belum diunggah (content URI); dikosongkan setelah unggah berhasil. */
    val photo: String? = null,
) {
    fun stockDelta(): Int = (currentStock.toIntOrNull() ?: 0) - (originalStock.toIntOrNull() ?: 0)
}

@Serializable data class ProductForm(
    val id: Long? = null,
    val outletIds: Set<Long> = emptySet(),
    val homeOutletId: Long? = null,
    val categoryId: Long? = null,
    val segment: String = "",
    val name: String = "",
    val description: String = "",
    val shortName: String = "",
    val taxCategory: String? = null,
    val prepMinutes: String = "",
    val isRetail: Boolean = false,
    val stockSource: String = "variant",
    val useAsIngredient: Boolean = false,
    val ingredientUnit: String = "",
    val isActive: Boolean = true,
    val stationId: Long? = null,
    val variants: List<VariantRow> = listOf(VariantRow()),
    /** Hanya saat UBAH dan stok berubah (web: `stock_adjustment_reason`). Saat buat dipakai teks tetap [OPENING_REASON]. */
    val stockReason: String = "",
    val photo: String? = null,
    /** Isi kiriman terakhir yang BERHASIL per langkah (`product`, `variant:<key>`) — dasar "ulangi hanya yang gagal/berubah". */
    val sent: Map<String, String> = emptyMap(),
    /** Produk dibuat oleh simpan di formulir ini: stok awal tetap memakai alasan [OPENING_REASON] saat diulang. */
    val createdHere: Boolean = false,
) {
    val editing: Boolean get() = id != null

    /** Koreksi stok hanya untuk produk bersumber stok varian (`stock_source = modifier` → stok varian tidak dilacak). */
    fun stockDeltas(): List<Pair<VariantRow, Int>> =
        if (stockSource == "modifier") emptyList() else variants.mapNotNull { r -> r.id?.let { r to r.stockDelta() } }.filter { it.second != 0 }

    val stockChanged: Boolean get() = stockSource != "modifier" && variants.any { it.stockDelta() != 0 }

    /** Validasi klien yang SAMA dengan web (FD:411-440); server tetap penegak. Kunci = kunci payload/422 server. */
    fun localErrors(): Map<String, String> = buildMap {
        if (name.isBlank()) put("name", "Wajib diisi.")
        if (homeOutletId == null) put("home_outlet_id", "Wajib diisi.")
        if (categoryId == null) put("category_id", "Wajib diisi.")
        if (!editing && outletIds.isEmpty()) put("outlet_ids", "Pilih minimal satu outlet.")
        variants.forEachIndexed { i, v ->
            if (v.sellPrice.toBigDecimalOrNull()?.let { it.signum() >= 0 } != true) put("variants.$i.sell_price", "Harga jual wajib, minimal 0.")
            if (variants.size > 1 && v.name.isBlank()) put("variants.$i.variant_name", "Nama varian wajib bila lebih dari satu varian.")
        }
        if (editing && !createdHere && stockChanged && stockReason.isBlank()) put("stock_adjustment_reason", "Alasan wajib karena stok diubah.")
    }

    companion object {
        const val NAME_MAX = 150
        const val SHORT_NAME_MAX = 24
        const val INGREDIENT_UNIT_MAX = 20
        const val VARIANT_NAME_MAX = 100
        const val OPENING_REASON = "Stok awal item baru"

        /** Nilai dan label `tax_category` dari web (FD:98, id.json); server hanya membatasi panjang 40. */
        val TAX_CATEGORIES = linkedMapOf(
            "restaurant_food_beverage" to "Makanan & minuman restoran",
            "retail" to "Retail",
            "other" to "Lainnya",
        )

        /** `toMoneyString` web: angka → "x.00", bukan angka → "0.00". */
        fun money(v: String): String = (v.replace(',', '.').toBigDecimalOrNull() ?: BigDecimal.ZERO).setScale(2, RoundingMode.HALF_UP).toPlainString()

        /** "15000.00" dari server → "15000" untuk diketik. */
        fun moneyInput(v: String?): String = v?.toBigDecimalOrNull()?.stripTrailingZeros()?.toPlainString() ?: ""

        /** Segmen: 3 huruf (server `size:3|alpha`), huruf besar saat mengetik. */
        fun cleanSegment(raw: String) = raw.filter { it.isLetter() && it.code < 128 }.take(3).uppercase()
    }
}
