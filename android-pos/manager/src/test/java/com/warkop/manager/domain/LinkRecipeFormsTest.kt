package com.warkop.manager.domain

import com.warkop.manager.data.dto.ModifierLinkDto
import com.warkop.manager.data.dto.RecipeBlockDto
import com.warkop.manager.data.dto.RecipeDetailDto
import com.warkop.manager.data.dto.RecipeLineDto
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test
import java.time.LocalDate

/** 094 / T068–T069 — aturan editor tautan modifier dan resep (cermin web + server). */
class LinkRecipeFormsTest {
    private val single = GroupChoice(1, "Suhu", single = true, isRequired = true, minSelect = 1, maxSelect = 1)
    private val multi = GroupChoice(2, "Topping", single = false, isRequired = false, minSelect = 0, maxSelect = 3)

    @Test fun `tambah grup - tunggal max 1 dan min ikut wajib, multi salin aturan grup`() {
        val f = ModifierLinksForm().add(single).add(multi)
        assertEquals(LinkRow(1, "Suhu", true, true, "1", "1"), f.rows[0])
        assertEquals(LinkRow(2, "Topping", false, false, "0", "3"), f.rows[1])
        assertEquals(listOf(multi), ModifierLinksForm().add(single).available(listOf(single, multi)))
    }

    @Test fun `aturan pilihan tunggal C1 dan batas min max`() {
        val base = ModifierLinksForm().add(single)
        assertTrue(base.localErrors().isEmpty())
        assertEquals("Grup pilihan tunggal: maksimum 1 dan minimum 0 atau 1.", base.copy(rows = listOf(base.rows[0].copy(max = ""))).localErrors()["links.0.max_select"])
        val m = ModifierLinksForm().add(multi)
        assertTrue("links.0.max_select" in m.copy(rows = listOf(m.rows[0].copy(min = "4", max = "3"))).localErrors())
        assertTrue("links.0.min_select" in m.copy(rows = listOf(m.rows[0].copy(min = ""))).localErrors())
        assertTrue(m.copy(rows = listOf(m.rows[0].copy(max = ""))).localErrors().isEmpty()) // kosong = tanpa batas
    }

    @Test fun `centang wajib menaikkan min ke 1, lepas tidak menurunkan`() {
        val f = ModifierLinksForm().add(multi).setRequired(2, true)
        assertEquals("1", f.rows[0].min)
        assertEquals("1", f.setRequired(2, false).rows[0].min)
    }

    @Test fun `excluded_group_ids dikirim hanya bila berubah, display_order mulai 1, max kosong null`() {
        val links = listOf(
            ModifierLinkDto(2, "Topping", "product", false, 0, null, 1),
            ModifierLinkDto(5, "Gula", "category", true, 1, 1, 2),
        )
        val f = ModifierLinksForm.of(links, excluded = listOf(ModifierLinkDto(9, "Es", "category")), singleIds = emptySet())
        assertEquals(1, f.rows.size); assertEquals(1, f.inherited.size)
        val body = f.toBody()
        assertNull(body.excludedGroupIds)
        assertEquals(1, body.links[0].displayOrder)
        assertNull(body.links[0].maxSelect)
        assertEquals(listOf(5L, 9L), f.release(5).toBody().excludedGroupIds)
        assertEquals(emptyList<Long>(), f.restore(9).toBody().excludedGroupIds) // [] = pulihkan semua
    }

    private val today = LocalDate.of(2026, 11, 25)
    private fun mat(q: String, s: String = "") = RecipeLine(kind = "material", itemId = 1, name = "Gula", quantity = q, shrinkage = s)

    @Test fun `resep baru - nama produk, satu tabel dasar per varian aktif tanpa BOM lengkap`() {
        val f = RecipeForm.new("Kopi Susu", listOf(10L to true, 11L to false))
        assertEquals("Kopi Susu", f.name)
        assertEquals(listOf(10L), f.blocks.map { it.variantId })
        assertNull(f.blocks[0].optionId)
    }

    @Test fun `validasi resep - porsi, tanggal 30 hari, tabel ganda, baris kosong, susut`() {
        val ok = RecipeForm(name = "Dasar", yield = "2", effectiveFrom = "2026-11-25", blocks = listOf(RecipeBlock(variantId = 10, lines = listOf(mat("10", "5")))))
        assertTrue(ok.localErrors(today).isEmpty())
        assertTrue("yield" in ok.copy(yield = "0").localErrors(today))
        assertTrue("effective_from" in ok.copy(effectiveFrom = "2026-10-20").localErrors(today))
        // Tanggal lama yang TIDAK diubah saat ubah resep dibebaskan.
        assertFalse("effective_from" in ok.copy(effectiveFrom = "2026-01-01", originalEffectiveFrom = "2026-01-01").localErrors(today))
        val dup = ok.copy(blocks = ok.blocks + ok.blocks[0].copy(key = "x"))
        assertTrue("blocks.1.modifier_option_id" in dup.localErrors(today))
        assertTrue("blocks.0.lines" in ok.copy(blocks = listOf(RecipeBlock(variantId = 10))).localErrors(today))
        assertTrue("blocks.0.lines.0.shrinkage_pct" in ok.copy(blocks = listOf(RecipeBlock(variantId = 10, lines = listOf(mat("1", "100"))))).localErrors(today))
        assertTrue("blocks" in ok.copy(blocks = emptyList()).localErrors(today))
    }

    @Test fun `produk-bahan harus bulat per porsi dan susut selalu 0`() {
        val prod = RecipeLine(kind = "product", itemId = 77, name = "Sirup", quantity = "3")
        val f = RecipeForm(name = "A", yield = "2", effectiveFrom = "2026-11-25", blocks = listOf(RecipeBlock(variantId = 10, lines = listOf(prod))))
        assertTrue("blocks.0.lines.0.quantity" in f.localErrors(today)) // 3 ÷ 2 = 1,5
        val ok = f.copy(blocks = listOf(f.blocks[0].copy(lines = listOf(prod.copy(quantity = "4")))))
        assertTrue(ok.localErrors(today).isEmpty())
        val line = ok.toBody().blocks[0].lines[0]
        assertEquals(77L, line.variantId); assertNull(line.materialId); assertEquals(0.0, line.shrinkagePct, 0.0)
    }

    @Test fun `isi dari detail - jenis baris dari kind, angka tanpa nol berlebih`() {
        val d = RecipeDetailDto(5, "Dasar", yield = "2.000", effectiveFrom = "2026-11-01", blocks = listOf(
            RecipeBlockDto(10, 3, listOf(RecipeLineDto(materialId = 1, name = "Gula", quantity = "12.5000", shrinkagePct = "5.00"), RecipeLineDto(variantId = 77, name = "Sirup", kind = "product", quantity = "2.0000"))),
        ))
        val f = RecipeForm.of(d)
        assertEquals("2", f.yield)
        assertEquals("2026-11-01", f.originalEffectiveFrom)
        assertEquals(listOf("material", "product"), f.blocks[0].lines.map { it.kind })
        assertEquals("12.5", f.blocks[0].lines[0].quantity)
        assertEquals("5", f.blocks[0].lines[0].shrinkage)
        assertEquals(3L, f.blocks[0].optionId)
    }
}
