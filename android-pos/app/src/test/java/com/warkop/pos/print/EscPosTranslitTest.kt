package com.warkop.pos.print

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Test

/** 084 / US4 — karakter di luar ASCII tidak lagi tercetak "?": diganti padanan, aksen dibuang, sisanya dibuang. */
class EscPosTranslitTest {
    @Test fun `tanda kutip tipografis menjadi kutip lurus`() {
        assertEquals("'Kopi' \"Susu\"", EscPos.sanitize("‘Kopi’ “Susu”"))
    }

    @Test fun `tanda hubung panjang dan titik tengah menjadi strip`() {
        assertEquals("Kopi - Standard", EscPos.sanitize("Kopi — Standard"))
        assertEquals("Kopi - Standard", EscPos.sanitize("Kopi – Standard"))
        assertEquals("Meja 5 - Budi", EscPos.sanitize("Meja 5 · Budi"))
    }

    @Test fun `elipsis dan tanda kali punya padanan`() {
        assertEquals("Tunggu...", EscPos.sanitize("Tunggu…"))
        assertEquals("2 x Kopi", EscPos.sanitize("2 × Kopi"))
    }

    @Test fun `huruf beraksen menjadi huruf dasar`() {
        assertEquals("Cafe creme brulee", EscPos.sanitize("Café crème brûlée"))
        assertEquals("Bebek goreng", EscPos.sanitize("Bèbèk góreng"))
    }

    @Test fun `spasi tak putus menjadi spasi biasa`() {
        // Kartu harga memakai NBSP agar tidak terpotong; di kertas harus spasi biasa, bukan "?".
        assertEquals("Rp 7.000 - Rp 15.000", EscPos.sanitize("Rp 7.000 – Rp 15.000"))
    }

    @Test fun `emoji dan aksara tak terpetakan dibuang tanpa spasi ganda dan tanpa tanda tanya`() {
        val out = EscPos.sanitize("Es 🧊 manis 中文 enak")
        assertEquals("Es manis enak", out)
        assertFalse(out.contains('?'))
    }

    @Test fun `tanda tanya asli di masukan tetap`() {
        assertEquals("Ada apa?", EscPos.sanitize("Ada apa?"))
    }

    @Test fun `ASCII biasa dan baris baru tidak berubah`() {
        assertEquals("Total Rp 50.000", EscPos.sanitize("Total Rp 50.000"))
        assertEquals("a b c", EscPos.sanitize("a\nb\r\nc"))
    }

    @Test fun `hasil selalu murni ASCII yang dapat dicetak`() {
        val messy = "Es é—… 🧊 中 ©"
        assertEquals("", EscPos.sanitize(messy).filter { it.code !in 0x20..0x7E })
    }
}
