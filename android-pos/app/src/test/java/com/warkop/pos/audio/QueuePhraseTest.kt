package com.warkop.pos.audio

import org.junit.Assert.assertEquals
import org.junit.Test

/** 061 / T005 — teks ucapan nomor antrean. */
class QueuePhraseTest {
    @Test fun `huruf dan tiga angka dibaca satu per satu`() {
        assertEquals("A, nol, satu, dua", QueuePhrase.spoken("A-012"))
    }

    @Test fun `angka tanpa tanda hubung sama hasilnya`() {
        assertEquals("B, tujuh", QueuePhrase.spoken("B-7"))
        assertEquals(QueuePhrase.spoken("A-012"), QueuePhrase.spoken("A012"))
    }

    @Test fun `huruf kecil dibaca sebagai huruf besar`() {
        assertEquals("A, satu", QueuePhrase.spoken("a-1"))
    }

    @Test fun `tanpa angka hanya huruf`() {
        assertEquals("A", QueuePhrase.spoken("A-"))
    }

    @Test fun `kosong menghasilkan kosong`() {
        assertEquals("", QueuePhrase.spoken("   "))
    }
}
