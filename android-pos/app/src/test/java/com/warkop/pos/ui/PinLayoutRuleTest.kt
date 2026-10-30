package com.warkop.pos.ui

import org.junit.Assert.assertEquals
import org.junit.Test

/** 085 / US2 — dua kolom bila lebarnya cukup, selain itu bertumpuk dengan urutan isi yang sama. Tiga kolom (078) sudah dihapus. */
class PinLayoutRuleTest {
    @Test fun `tablet lanskap memakai dua kolom, termasuk yang lebar`() {
        assertEquals(PinLayout.TWO_COLUMN, pinLayoutFor(1280f))
        assertEquals(PinLayout.TWO_COLUMN, pinLayoutFor(960f))
        assertEquals(PinLayout.TWO_COLUMN, pinLayoutFor(840f))
    }

    @Test fun `tepat di batas dua kolom`() {
        assertEquals(PinLayout.STACKED, pinLayoutFor(PIN_TWO_COLUMN_MIN_DP - 0.5f))
        assertEquals(PinLayout.TWO_COLUMN, pinLayoutFor(PIN_TWO_COLUMN_MIN_DP))
        assertEquals(720f, PIN_TWO_COLUMN_MIN_DP, 0f)
    }

    @Test fun `ponsel dan layar sempit bertumpuk`() {
        assertEquals(PinLayout.STACKED, pinLayoutFor(411f))
        assertEquals(PinLayout.STACKED, pinLayoutFor(719f))
    }

    @Test fun `hanya ada dua bentuk`() {
        assertEquals(setOf("TWO_COLUMN", "STACKED"), PinLayout.values().map { it.name }.toSet())
    }
}
