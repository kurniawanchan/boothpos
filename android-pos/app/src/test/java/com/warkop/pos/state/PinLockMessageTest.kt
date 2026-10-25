package com.warkop.pos.state

import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test
import java.time.Instant

/** 073 / US2 — pesan kunci PIN memakai `locked_until` server (kini 5 menit), bukan angka yang tertulis di aplikasi. */
class PinLockMessageTest {
    private val now = Instant.parse("2026-10-26T10:00:00Z").toEpochMilli()

    @Test fun `lima menit penuh tampil 5 menit`() {
        assertEquals("PIN terkunci karena salah 5 kali. Coba lagi dalam 5 menit.", PinLockMessage.text("2026-10-26T10:05:00Z", now))
    }

    @Test fun `sisa dibulatkan ke atas`() {
        assertTrue(PinLockMessage.text("2026-10-26T10:02:01Z", now).endsWith("dalam 3 menit."))
        assertTrue(PinLockMessage.text("2026-10-26T10:00:10Z", now).endsWith("dalam 1 menit."))
    }

    @Test fun `tidak ada angka 15 yang tertulis lagi`() {
        assertTrue("15" !in PinLockMessage.text("2026-10-26T10:05:00Z", now))
    }

    @Test fun `waktu kosong, rusak, atau sudah lewat memakai pesan tanpa angka`() {
        val generic = "PIN terkunci karena salah 5 kali. Coba lagi sebentar lagi."
        assertEquals(generic, PinLockMessage.text(null, now))
        assertEquals(generic, PinLockMessage.text("bukan-tanggal", now))
        assertEquals(generic, PinLockMessage.text("2026-10-26T09:59:00Z", now))
    }
}
