package com.warkop.manager.domain

import com.warkop.manager.data.dto.RecipeBlockBody
import com.warkop.manager.data.dto.RecipeBody
import com.warkop.manager.data.dto.RecipeDetailDto
import com.warkop.manager.data.dto.RecipeLineBody
import kotlinx.serialization.Serializable
import java.math.BigDecimal
import java.time.LocalDate
import java.util.UUID

/**
 * 094 / T069 — isian resep, cermin `RecipeDrawer.vue` + `RecipeService::validate`. Satu resep = beberapa TABEL BAHAN
 * (blok): varian + pilihan modifier (null = resep dasar). Baris = bahan (`material_id`) ATAU produk-bahan (`variant_id`).
 * HPP dihitung server, tidak di sini.
 */
@Serializable data class RecipeLine(
    val key: String = UUID.randomUUID().toString(),
    /** "material" | "product" */
    val kind: String,
    val itemId: Long,
    val name: String,
    val unit: String? = null,
    val quantity: String = "",
    val shrinkage: String = "",
)

@Serializable data class RecipeBlock(
    val key: String = UUID.randomUUID().toString(),
    val variantId: Long? = null,
    val optionId: Long? = null,
    val lines: List<RecipeLine> = emptyList(),
)

@Serializable data class RecipeForm(
    val id: Long? = null,
    val name: String = "",
    val yield: String = "1",
    val effectiveFrom: String = LocalDate.now().toString(),
    /** Tanggal tersimpan saat formulir dibuka: tanggal lama yang TIDAK diubah dibebaskan dari batas 30 hari (RS:633-638). */
    val originalEffectiveFrom: String? = null,
    val note: String = "",
    val blocks: List<RecipeBlock> = emptyList(),
) {
    /** Validasi klien yang sama dengan server; kunci = kunci 422 server (`blocks.i.lines.j.quantity`, dst.). */
    fun localErrors(today: LocalDate = LocalDate.now()): Map<String, String> = buildMap {
        if (name.trim().isEmpty() || name.trim().length > NAME_MAX) put("name", "Beri nama resep (maksimal 100 karakter).")
        val y = yield.toBigDecimalOrNull()
        if (y == null || y.signum() <= 0) put("yield", "Porsi harus lebih dari 0.")
        val date = runCatching { LocalDate.parse(effectiveFrom) }.getOrNull()
        if (date == null) put("effective_from", "Tanggal tidak valid.")
        else if (effectiveFrom != originalEffectiveFrom && date.isBefore(today.minusDays(MAX_BACKDATE_DAYS))) put("effective_from", "Tanggal berlaku tidak boleh lebih dari $MAX_BACKDATE_DAYS hari ke belakang.")
        if (blocks.isEmpty()) put("blocks", "Pilih minimal satu varian.")
        val seen = mutableSetOf<Pair<Long, Long?>>()
        blocks.forEachIndexed { i, b ->
            if (b.variantId == null) { put("blocks.$i.variant_id", "Pilih varian dulu untuk mengisi bahan."); return@forEachIndexed }
            if (!seen.add(b.variantId to b.optionId)) put("blocks.$i.modifier_option_id", "Varian dan modifier ini sudah punya tabel bahan.")
            if (b.lines.isEmpty()) put("blocks.$i.lines", "Tambahkan minimal satu bahan.")
            val items = mutableSetOf<String>()
            b.lines.forEachIndexed { j, l ->
                val p = "blocks.$i.lines.$j"
                if (!items.add("${l.kind}:${l.itemId}")) put("$p.${if (l.kind == "product") "variant_id" else "material_id"}", "Bahan ini sudah ada di tabel ini.")
                val q = l.quantity.toBigDecimalOrNull()
                if (q == null || q.signum() <= 0) put("$p.quantity", "Jumlah harus lebih dari 0.")
                else if (l.kind == "product" && y != null && y.signum() > 0 && !wholePerPortion(q, y)) put("$p.quantity", "Produk-bahan harus bilangan bulat per porsi (jumlah ÷ porsi).")
                if (l.kind == "material") {
                    val s = l.shrinkage.ifBlank { "0" }.toBigDecimalOrNull()
                    if (s == null || s.signum() < 0 || s > BigDecimal("99.99")) put("$p.shrinkage_pct", "Susut harus antara 0 dan 99,99%.")
                }
            }
        }
    }

    fun toBody() = RecipeBody(
        name = name.trim(),
        yield = yield.toDouble(),
        effectiveFrom = effectiveFrom,
        blocks = blocks.map { b ->
            RecipeBlockBody(b.variantId!!, b.optionId, b.lines.map { l ->
                if (l.kind == "product") RecipeLineBody(variantId = l.itemId, quantity = l.quantity.toDouble(), shrinkagePct = 0.0)
                else RecipeLineBody(materialId = l.itemId, quantity = l.quantity.toDouble(), shrinkagePct = l.shrinkage.ifBlank { "0" }.toDouble())
            })
        },
        note = note.trim().ifEmpty { null },
    )

    companion object {
        const val NAME_MAX = 100
        const val NOTE_MAX = 500
        const val MAX_BACKDATE_DAYS = 30L

        /** jumlah ÷ porsi bulat ≥ 1 dengan toleransi 1e-6 (RS:754-757, `recipeIngredients.js`). */
        fun wholePerPortion(q: BigDecimal, y: BigDecimal): Boolean {
            val per = q.toDouble() / y.toDouble()
            return per >= 1 - 1e-6 && kotlin.math.abs(per - Math.round(per)) < 1e-6
        }

        /** Resep baru: nama = nama produk (≤ 100), porsi 1, satu tabel dasar per varian aktif tanpa BOM lengkap (RD:156-159). */
        fun new(productName: String, variants: List<Pair<Long, Boolean>>) = RecipeForm(
            name = productName.take(NAME_MAX),
            blocks = variants.filter { it.second }.map { RecipeBlock(variantId = it.first) },
        )

        fun of(d: RecipeDetailDto) = RecipeForm(
            id = d.id,
            name = d.name,
            yield = d.yield.toBigDecimalOrNull()?.stripTrailingZeros()?.toPlainString() ?: d.yield,
            effectiveFrom = d.effectiveFrom ?: LocalDate.now().toString(),
            originalEffectiveFrom = d.effectiveFrom,
            note = d.note.orEmpty(),
            blocks = d.blocks.map { b ->
                RecipeBlock(variantId = b.variantId, optionId = b.modifierOptionId, lines = b.lines.map { l ->
                    val product = l.kind == "product" || (l.materialId == null && l.variantId != null)
                    RecipeLine(
                        kind = if (product) "product" else "material",
                        itemId = (if (product) l.variantId else l.materialId) ?: 0,
                        name = l.name, unit = l.unit,
                        quantity = l.quantity.toBigDecimalOrNull()?.stripTrailingZeros()?.toPlainString() ?: l.quantity,
                        shrinkage = if (product) "" else (l.shrinkagePct?.toBigDecimalOrNull()?.stripTrailingZeros()?.toPlainString() ?: ""),
                    )
                })
            },
        )
    }
}
