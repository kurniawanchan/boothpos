package com.warkop.pos.model

import java.text.NumberFormat
import java.util.Locale

/**
 * 087 lanjutan (2026-11-01) — harga berjalan dan stok bahan di pemilih modifier tablet. HANYA tampilan: server
 * (ModifierResolver) yang menagih dan memotong bahan. Aturannya harus sama dengan server dan web (modifierRules.js):
 *
 * - Grup [ModGroup.portionPerOption]: pilihan pertama tertutup harga dasar, tiap pilihan berikutnya menambah SATU
 *   harga dasar. "Pertama" menurut urutan opsi grup, bukan urutan ketuk — sama dengan server.
 * - Stok produk bersumber bahan = untuk tiap grup WAJIB yang memetakan bahan, jumlah porsi semua pilihannya; hasilnya
 *   grup terkecil. Null bila tak diketahui. Angka ini tidak pernah memblokir jual (penjualan tidak ditolak karena bahan).
 */
object ModifierPricing {
    /** Selisih satu pilihan [o] di grup [g] bila [picked] terpilih (untuk label "+Rp" dan total). */
    fun optionDelta(base: Int, g: ModGroup, o: ModOption, picked: List<ModOption>): Int {
        val firstPicked = g.options.firstOrNull { it in picked }
        val extraPortion = g.portionPerOption && firstPicked != null && firstPicked != o
        return o.delta + if (extraPortion) base else 0
    }

    fun unitPrice(base: Int, groups: List<ModGroup>, picks: Map<String, List<ModOption>>): Int =
        base + groups.sumOf { g ->
            val picked = picks[g.id].orEmpty()
            g.options.filter { it in picked }.sumOf { optionDelta(base, g, it, picked) }
        }

    /** Banyaknya porsi: terbesar dari jumlah pilihan di grup porsi, minimal 1. */
    fun portionCount(groups: List<ModGroup>, picks: Map<String, List<ModOption>>): Int =
        groups.filter { it.portionPerOption }.maxOfOrNull { picks[it.id].orEmpty().size }?.coerceAtLeast(1) ?: 1

    fun materialPortions(groups: List<ModGroup>): Int? {
        var result: Int? = null
        for (g in groups) {
            if (g.min < 1 || g.options.none { it.materials.isNotEmpty() }) continue
            if (g.options.any { it.portions == null }) return null
            val sum = g.options.sumOf { maxOf(0, it.portions ?: 0) }
            result = result?.let { minOf(it, sum) } ?: sum
        }
        return result
    }

    /** "Bahan: stok 17 pcs · pakai 1 pcs" per bahan yang saldonya diketahui. */
    fun stockLines(o: ModOption): List<String> = o.materials.filter { it.onHand != null }.map {
        "${it.name}: stok ${qty(it.onHand!!)} ${it.unit} · pakai ${qty(it.quantity)} ${it.unit}"
    }

    /** Pilihan yang bahannya sudah tidak cukup untuk satu porsi (ditandai merah; tetap boleh dipilih). */
    fun isShort(o: ModOption): Boolean = o.portions != null && o.portions <= 0

    fun qty(v: Double): String {
        val f = NumberFormat.getNumberInstance(Locale.forLanguageTag("id-ID")).apply { maximumFractionDigits = 3 }
        return (if (v < 0) "−" else "") + f.format(kotlin.math.abs(v))
    }
}
