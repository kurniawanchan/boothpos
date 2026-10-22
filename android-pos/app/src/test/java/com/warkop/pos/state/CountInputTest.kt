package com.warkop.pos.state

import com.warkop.pos.model.ShiftCount
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

/** 065 / research D9 — buka dan tutup shift: stepper ATAU total ketikan; yang terakhir disentuh menang. */
class CountInputTest {
    @Test fun `stepper menjumlah per pecahan`() {
        val c = CountInput().setCount(100_000, 2).setCount(5_000, 3)
        assertEquals(215_000, c.total)
        assertFalse(c.isTyped)
        assertEquals(ShiftCount.ByDenomination(mapOf(100_000 to 2, 5_000 to 3)), c.toCount())
    }

    @Test fun `mengetik total mengosongkan stepper dan menjadi satu-satunya total`() {
        val c = CountInput().setCount(100_000, 2).type("150000")
        assertTrue(c.isTyped)
        assertEquals(150_000, c.total)
        assertTrue(c.counts.isEmpty())
        assertEquals(ShiftCount.Typed(150_000), c.toCount())
    }

    @Test fun `menyentuh stepper mengosongkan ketikan`() {
        val c = CountInput().type("150000").setCount(50_000, 1)
        assertFalse(c.isTyped)
        assertEquals("", c.typed)
        assertEquals(50_000, c.total)
        assertEquals(ShiftCount.ByDenomination(mapOf(50_000 to 1)), c.toCount())
    }

    @Test fun `ketikan hanya angka, tanpa nol di depan, dan dibatasi 9 digit`() {
        assertEquals("1500", CountInput().type("Rp 1.500").typed)
        assertEquals("25", CountInput().type("0025").typed)
        assertEquals("999999999", CountInput().type("99999999999").typed)
    }

    @Test fun `mengosongkan ketikan tidak menghapus stepper yang sudah ada`() {
        val c = CountInput(counts = mapOf(20_000 to 2)).type("")
        assertEquals(mapOf(20_000 to 2), c.counts)
        assertEquals(40_000, c.total)
    }

    @Test fun `tampilan ketikan memakai pemisah ribuan titik`() {
        assertEquals("1.500.000", CountInput().type("1500000").typedDisplay)
        assertEquals("500", CountInput().type("500").typedDisplay)
        assertEquals("", CountInput().typedDisplay)
    }

    @Test fun `total nol dari ketikan berbeda dari tidak mengisi apa pun`() {
        // "0" dibuang oleh trimStart sehingga dianggap kosong → jatuh ke stepper (total 0); keduanya Rp 0.
        assertEquals(0, CountInput().type("0").total)
        assertEquals(ShiftCount.ByDenomination(emptyMap()), CountInput().type("0").toCount())
    }

    @Test fun `denominasi yang jumlahnya nol tidak ikut dalam rincian`() {
        val c = CountInput().setCount(50_000, 2).setCount(50_000, 0)
        assertTrue((c.toCount() as ShiftCount.ByDenomination).rows.isEmpty())
    }
}
