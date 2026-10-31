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

    @Test fun `baris stok per pilihan menyebut saldo dan pemakaian, minus pakai tanda minus`() {
        assertEquals(listOf("Indomie Soto: stok 17 pcs · pakai 1 pcs"), ModifierPricing.stockLines(soto))
        assertEquals(listOf("Indomie Ayam Bawang: stok −1 pcs · pakai 1 pcs"), ModifierPricing.stockLines(ayam))
        assertTrue(ModifierPricing.isShort(ayam))
        assertFalse(ModifierPricing.isShort(soto))
        assertTrue(ModifierPricing.stockLines(ModOption("Sedang", 0, 1)).isEmpty())
    }

    // 2026-11-01 — BUG: grup tanpa batas atas di tablet hanya bisa dipilih 1. Kini tanpa batas + teks aturan jujur.
    @Test fun `teks aturan grup termasuk tanpa batas`() {
        assertEquals("Pilih 1", ModGroup("1", "Rasa", 1, 1, emptyList()).rule)
        assertEquals("Hingga 3", ModGroup("1", "Topping", 0, 3, emptyList()).rule)
        assertEquals("Pilih 1–2", ModGroup("1", "Rasa", 1, 2, emptyList()).rule)
        assertEquals("Pilih bebas", ModGroup("1", "Topping", 0, ModGroup.UNLIMITED, emptyList()).rule)
        assertEquals("Pilih minimal 2", ModGroup("1", "Topping", 2, ModGroup.UNLIMITED, emptyList()).rule)
        assertTrue(ModGroup("1", "Topping", 0, ModGroup.UNLIMITED, emptyList()).allows(10))
        assertFalse(ModGroup("1", "Topping", 0, 3, emptyList()).allows(3))
    }
}
