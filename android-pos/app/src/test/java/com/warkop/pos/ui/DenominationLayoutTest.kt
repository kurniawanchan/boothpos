package com.warkop.pos.ui

import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test

/** 065 / US4 — aturan kolom pecahan: label tidak pernah terjepit (satu per baris bila sel tak muat dua). */
class DenominationLayoutTest {
    @Test fun `panel sempit tablet 800 dp memakai satu pecahan per baris`() {
        assertEquals(1, DenominationLayout.columnsFor(800f))
    }

    @Test fun `lebar di bawah dua sel tetap satu kolom`() {
        assertEquals(1, DenominationLayout.columnsFor(DenominationLayout.MIN_CELL_DP * 2 - 1))
    }

    @Test fun `dua sel penuh memberi dua kolom`() {
        assertEquals(2, DenominationLayout.columnsFor(DenominationLayout.MIN_CELL_DP * 2))
        assertEquals(2, DenominationLayout.columnsFor(1000f))
    }

    @Test fun `kolom dibatasi tiga dan tidak pernah nol`() {
        assertEquals(3, DenominationLayout.columnsFor(5000f))
        assertEquals(1, DenominationLayout.columnsFor(0f))
        assertEquals(1, DenominationLayout.columnsFor(-10f))
    }

    @Test fun `tutup shift tanpa subtotal boleh lebih rapat lewat minCell kecil`() {
        assertEquals(3, DenominationLayout.columnsFor(1000f, minCellDp = 300f))
    }

    @Test fun `label punya lebar minimum yang cukup untuk Rp 100 000`() {
        assertTrue(DenominationLayout.LABEL_MIN_DP >= 120f)
        assertEquals("Rp 100.000", denominationLabel(100_000))
        assertEquals("Koin Rp 500", denominationLabel(500))
    }
}
