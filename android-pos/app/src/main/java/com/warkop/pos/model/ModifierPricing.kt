package com.warkop.pos.model

import java.text.NumberFormat
import java.util.Locale

/**
 * 087 lanjutan (2026-11-01) — harga berjalan dan stok bahan di pemilih modifier tablet. HANYA tampilan: server
 * (ModifierResolver) yang menagih dan memotong bahan. Aturannya harus sama dengan server dan web (modifierRules.js):
 *
 * - Grup [ModGroup.portionPerOption]: pilihan pertama tertutup harga dasar, tiap pilihan berikutnya menambah SATU
 *   harga dasar. "Pertama" menurut urutan opsi grup, bukan urutan ketuk — sama dengan server.
 * - 089 jumlah per pilihan: kontribusi sebuah pilihan = selisih × jumlah, dan porsi dihitung per SATUAN jumlah (SOTO ×2 =
 *   dua porsi): pilihan pertama menanggung `jumlah − 1` harga dasar ekstra, pilihan berikutnya `jumlah`. Sama dengan
 *   ModifierResolver::resolve() di server dan `selectionDelta` di web (modifierRules.js).
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

    /** Kontribusi harga TOTAL pilihan [o] yang dipesan [qty] kali (089). Dengan [qty] = 1 sama dengan [optionDelta]. */
    fun contribution(base: Int, g: ModGroup, o: ModOption, picked: List<ModOption>, qty: Int): Int {
        val firstPicked = g.options.firstOrNull { it in picked }
        val extraPortions = if (g.portionPerOption) qty - (if (firstPicked == o) 1 else 0) else 0
        return o.delta * qty + extraPortions * base
    }

    /** [qtys] = jumlah per pilihan terpilih (tanpa entri = 1). */
    fun unitPrice(base: Int, groups: List<ModGroup>, picks: Map<String, List<ModOption>>, qtys: Map<ModOption, Int> = emptyMap()): Int =
        base + groups.sumOf { g ->
            val picked = picks[g.id].orEmpty()
            g.options.filter { it in picked }.sumOf { contribution(base, g, it, picked, qtys[it] ?: 1) }
        }

    /** Banyaknya porsi: di grup porsi tiap SATUAN jumlah = satu porsi; terbesar antar grup, minimal 1. */
    fun portionCount(groups: List<ModGroup>, picks: Map<String, List<ModOption>>, qtys: Map<ModOption, Int> = emptyMap()): Int =
        groups.filter { it.portionPerOption }.maxOfOrNull { g -> picks[g.id].orEmpty().sumOf { qtys[it] ?: 1 } }?.coerceAtLeast(1) ?: 1

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

    /** Total satuan jumlah pilihan terpilih di satu grup. */
    fun totalQty(sel: List<ModOption>, qtys: Map<ModOption, Int>): Int = sel.sumOf { qtys[it] ?: it.minQty }

    /** 2026-11-02 — MINIMUM grup dihitung dalam satuan jumlah (SOTO ×2 memenuhi "pilih 2"); sama dengan server dan web. */
    fun meetsMin(g: ModGroup, sel: List<ModOption>, qtys: Map<ModOption, Int>): Boolean = totalQty(sel, qtys) >= g.min

    /** Grup berganda yang pilihan berbedanya sudah sebanyak "pilih n" (n > 1): jumlah dikunci di minimum, pilihan lain mati. */
    fun isLocked(g: ModGroup, sel: List<ModOption>): Boolean = g.max in 2 until ModGroup.UNLIMITED && sel.size >= g.max

    /** Pilihan yang BELUM terpilih tak bisa ditambah: kuota pilihan penuh, atau jumlah total sudah di batas "pilih n". */
    fun isFull(g: ModGroup, sel: List<ModOption>, qtys: Map<ModOption, Int>): Boolean {
        if (g.max == ModGroup.UNLIMITED) return false
        return sel.size >= g.max || (g.max > 1 && totalQty(sel, qtys) >= g.max)
    }

    /** Jumlah pilihan [o] boleh naik: grup tak terkunci, di bawah batas pilihan itu, dan total di bawah "pilih n". */
    fun canIncrease(g: ModGroup, o: ModOption, sel: List<ModOption>, qtys: Map<ModOption, Int>): Boolean {
        if (isLocked(g, sel)) return false
        if (g.max in 2 until ModGroup.UNLIMITED && totalQty(sel, qtys) >= g.max) return false
        return (qtys[o] ?: o.minQty) < o.qtyCap
    }

    fun canDecrease(g: ModGroup, o: ModOption, sel: List<ModOption>, qtys: Map<ModOption, Int>): Boolean =
        !isLocked(g, sel) && (qtys[o] ?: o.minQty) > o.minQty

    fun qty(v: Double): String {
        val f = NumberFormat.getNumberInstance(Locale.forLanguageTag("id-ID")).apply { maximumFractionDigits = 3 }
        return (if (v < 0) "−" else "") + f.format(kotlin.math.abs(v))
    }
}
