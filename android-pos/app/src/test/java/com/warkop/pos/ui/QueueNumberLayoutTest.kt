package com.warkop.pos.ui

import org.junit.Assert.assertEquals
import org.junit.Test

/** 061 / T003 — ukuran nomor antrean: terbesar yang muat, mengecil bila sempit, tidak pernah terpotong. */
class QueueNumberLayoutTest {
    // Lebar fiktif: 10 px per sp pada setiap karakter, 4 karakter.
    private val widthAt: (Float) -> Int = { sp -> (sp * 10 * 4).toInt() }

    @Test fun `ukuran terbesar dipilih bila muat`() {
        assertEquals(168f, queueSizeFor(widthAt, available = 7_000), 0f)
    }

    @Test fun `lebar sempit memilih ukuran yang lebih kecil`() {
        // 88 sp * 40 = 3520 px muat; 104 sp * 40 = 4160 tidak.
        assertEquals(88f, queueSizeFor(widthAt, available = 3_600), 0f)
    }

    @Test fun `tidak ada yang muat memakai ukuran terkecil`() {
        assertEquals(64f, queueSizeFor(widthAt, available = 10), 0f)
    }
}
