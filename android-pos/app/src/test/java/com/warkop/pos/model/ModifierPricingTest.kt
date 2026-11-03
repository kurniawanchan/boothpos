package com.warkop.pos.model

import org.junit.Assert.*
import org.junit.Test

/**
 * 087 lanjutan (2026-11-01) — aturan yang sama dengan ModifierResolver (server) dan modifierRules.js (web):
 * grup "tiap pilihan = 1 porsi" menambah satu harga dasar per pilihan tambahan (urutan opsi, bukan urutan ketuk),
 * dan stok produk bersumber bahan = jumlah porsi pilihan grup wajib berbahan, grup terkecil.
 */
class ModifierPricingTest {
    private val mat = listOf(OptionMaterial("Indomie Soto", "pcs", 1.0, 17.0))
    private val soto = ModOption("SOTO", 0, 50, portions = 17, materials = mat)
    private val ayam = ModOption("AYAM BAWANG", 0, 51, portions = 0, materials = listOf(OptionMaterial("Indomie Ayam Bawang", "pcs", 1.0, -1.0)))
    private val rasa = ModGroup("5", "Rasa", 1, 2, listOf(soto, ayam), portionPerOption = true)
    private val telur = ModOption("Telur", 3000, 60, portions = 4, materials = listOf(OptionMaterial("Telur", "butir", 1.0, 4.0)))
    private val topping = ModGroup("6", "Topping", 0, 3, listOf(telur))

    @Test fun `satu pilihan di grup porsi = harga dasar`() {
        assertEquals(8000, ModifierPricing.unitPrice(8000, listOf(rasa), mapOf("5" to listOf(soto))))
    }

    @Test fun `pilihan kedua menambah satu harga dasar, urutan ketuk tidak berpengaruh`() {
        assertEquals(16000, ModifierPricing.unitPrice(8000, listOf(rasa), mapOf("5" to listOf(ayam, soto))))
        assertEquals(19000, ModifierPricing.unitPrice(8000, listOf(rasa, topping), mapOf("5" to listOf(soto, ayam), "6" to listOf(telur))))
        assertEquals(2, ModifierPricing.portionCount(listOf(rasa, topping), mapOf("5" to listOf(soto, ayam))))
    }

    @Test fun `label tambahan pilihan hanya untuk pilihan bukan-pertama di grup porsi`() {
        val picks = mapOf("5" to listOf(ayam, soto))
        assertEquals(0, ModifierPricing.optionDelta(8000, rasa, soto, picks["5"]!!))
        assertEquals(8000, ModifierPricing.optionDelta(8000, rasa, ayam, picks["5"]!!))
        assertEquals(3000, ModifierPricing.optionDelta(8000, topping, telur, listOf(telur)))
    }

    @Test fun `grup biasa tetap harga dasar ditambah selisih`() {
        val plain = rasa.copy(portionPerOption = false)
        assertEquals(8000, ModifierPricing.unitPrice(8000, listOf(plain), mapOf("5" to listOf(soto, ayam))))
    }

    @Test fun `stok bahan produk = jumlah porsi grup wajib, grup terkecil, null bila tak diketahui`() {
        assertEquals(17, ModifierPricing.materialPortions(listOf(rasa, topping)))
        val kuah = ModGroup("7", "Kuah", 1, 1, listOf(ModOption("Kuah", 0, 70, portions = 5, materials = mat)))
        assertEquals(5, ModifierPricing.materialPortions(listOf(rasa, kuah)))
        val withPlain = rasa.copy(options = rasa.options + ModOption("Tanpa bahan", 0, 52))
        assertNull(ModifierPricing.materialPortions(listOf(withPlain)))
        assertNull(ModifierPricing.materialPortions(listOf(topping)))
    }


    // 2026-11-01 — BUG: grup tanpa batas atas di tablet hanya bisa dipilih 1. Kini tanpa batas + teks aturan jujur.
    @Test fun `teks aturan grup termasuk tanpa batas`() {
        assertEquals("Pilih 1", ModGroup("1", "Rasa", 1, 1, emptyList()).rule)
        assertEquals("Pilih 3", ModGroup("1", "Topping", 0, 3, emptyList()).rule)
        assertEquals("Pilih 2", ModGroup("1", "Rasa", 1, 2, emptyList()).rule)
        assertEquals("Pilih bebas", ModGroup("1", "Topping", 0, ModGroup.UNLIMITED, emptyList()).rule)
        assertEquals("Pilih minimal 2", ModGroup("1", "Topping", 2, ModGroup.UNLIMITED, emptyList()).rule)
        assertTrue(ModGroup("1", "Topping", 0, ModGroup.UNLIMITED, emptyList()).allows(10))
        assertFalse(ModGroup("1", "Topping", 0, 3, emptyList()).allows(3))
    }

    // 089 — jumlah per pilihan: harus sama dengan ModifierResolver (server) dan selectionDelta (web).
    @Test fun `jumlah mengalikan selisih harga pilihan`() {
        val telur3 = telur.copy(maxQty = 3)
        val grup = topping.copy(options = listOf(telur3))   // grup harus memuat opsi yang SAMA dengan yang dipilih
        assertEquals(8000 + 3000 * 2, ModifierPricing.unitPrice(8000, listOf(grup), mapOf("6" to listOf(telur3)), mapOf(telur3 to 2)))
        assertEquals("tanpa entri = 1", 8000 + 3000, ModifierPricing.unitPrice(8000, listOf(grup), mapOf("6" to listOf(telur3))))
    }

    @Test fun `grup porsi menghitung tiap satuan jumlah sebagai satu porsi`() {
        // dasar 8.000: SOTO ×2 → 1 porsi ekstra (+8.000); AYAM ×1 → +8.000. Total 24.000 = tiga porsi.
        val picks = mapOf("5" to listOf(soto, ayam))
        val qtys = mapOf(soto to 2, ayam to 1)
        assertEquals(24000, ModifierPricing.unitPrice(8000, listOf(rasa), picks, qtys))
        assertEquals(3, ModifierPricing.portionCount(listOf(rasa), picks, qtys))
        // satu pilihan ×1 = perilaku lama
        assertEquals(8000, ModifierPricing.unitPrice(8000, listOf(rasa), mapOf("5" to listOf(soto)), mapOf(soto to 1)))
        assertEquals(1, ModifierPricing.portionCount(listOf(rasa), mapOf("5" to listOf(soto)), mapOf(soto to 1)))
    }

    @Test fun `kontribusi dengan jumlah 1 sama dengan label lama`() {
        val picked = listOf(ayam, soto)
        assertEquals(ModifierPricing.optionDelta(8000, rasa, ayam, picked), ModifierPricing.contribution(8000, rasa, ayam, picked, 1))
        assertEquals(ModifierPricing.optionDelta(8000, rasa, soto, picked), ModifierPricing.contribution(8000, rasa, soto, picked, 1))
    }

    @Test fun `batas jumlah pilihan dan label baris`() {
        assertFalse(telur.allowsQty)                                   // bawaan 1..1: pilih atau tidak
        val bebas = telur.copy(minQty = 2, maxQty = null)
        assertTrue(bebas.allowsQty)
        assertEquals(ModOption.MAX_QTY, bebas.qtyCap)
        assertEquals(3, telur.copy(maxQty = 3).qtyCap)
        assertEquals("Telur", telur.label)
        assertEquals("Telur ×2", telur.copy(qty = 2).label)
        val line = OrderLine(1, "x", "Nasi Goreng", 20000, 1, mods = listOf(telur.copy(qty = 2, delta = 6000), ModOption("Kerupuk", 1000)))
        assertEquals("Telur ×2, Kerupuk", line.modLabel)
        assertEquals(27000, line.unit)
    }

    // 2026-11-02 — batas jumlah per grup di tablet ("pilih 2").
    private val sotoQ = ModOption("SOTO", 0, 70, minQty = 1, maxQty = 2)
    private val ayamQ = ModOption("AYAM", 0, 71, minQty = 1, maxQty = 2)
    private val rasaQ = ModGroup("9", "Rasa", 2, 2, listOf(sotoQ, ayamQ, ModOption("KARI", 0, 72, minQty = 1, maxQty = 2)))

    @Test fun `label aturan selalu Pilih n dari batas web`() {
        assertEquals("Pilih 2", rasaQ.rule)
        assertEquals("Pilih 1", ModGroup("1", "Size", 1, 1, emptyList()).rule)
    }

    @Test fun `satu pilihan di jumlah maksimum memenuhi minimum dan menutup pilihan lain`() {
        assertFalse(ModifierPricing.meetsMin(rasaQ, listOf(sotoQ), mapOf(sotoQ to 1)))
        assertTrue(ModifierPricing.meetsMin(rasaQ, listOf(sotoQ), mapOf(sotoQ to 2)))
        assertTrue(ModifierPricing.isFull(rasaQ, listOf(sotoQ), mapOf(sotoQ to 2)))
        assertFalse(ModifierPricing.isFull(rasaQ, listOf(sotoQ), mapOf(sotoQ to 1)))
        assertFalse(ModifierPricing.canIncrease(rasaQ, sotoQ, listOf(sotoQ), mapOf(sotoQ to 2)))
    }

    @Test fun `dua pilihan berbeda = terkunci, jumlah tak bisa diubah, lepas satu = terbuka lagi`() {
        val both = listOf(sotoQ, ayamQ)
        assertTrue(ModifierPricing.isLocked(rasaQ, both))
        assertTrue(ModifierPricing.isFull(rasaQ, both, emptyMap()))
        assertFalse(ModifierPricing.canIncrease(rasaQ, sotoQ, both, mapOf(sotoQ to 1, ayamQ to 1)))
        assertFalse(ModifierPricing.canDecrease(rasaQ, sotoQ, both, mapOf(sotoQ to 2, ayamQ to 1)))
        assertFalse(ModifierPricing.isLocked(rasaQ, listOf(sotoQ)))
        assertTrue(ModifierPricing.canIncrease(rasaQ, sotoQ, listOf(sotoQ), mapOf(sotoQ to 1)))
    }

    @Test fun `grup tunggal tidak pernah terkunci`() {
        val single = ModGroup("1", "Size", 1, 1, listOf(sotoQ, ayamQ))
        assertFalse(ModifierPricing.isLocked(single, listOf(sotoQ)))
        assertTrue(ModifierPricing.canIncrease(single, sotoQ, listOf(sotoQ), mapOf(sotoQ to 1)))
    }
}
