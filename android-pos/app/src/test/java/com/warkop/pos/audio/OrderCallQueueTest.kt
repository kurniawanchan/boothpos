package com.warkop.pos.audio

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

/** 090 / US8 — pengumuman pesanan mandiri satu per satu, tanpa saling menimpa dan tanpa menahan antrean saat mesin gagal. */
class OrderCallQueueTest {
    /** Mesin palsu yang TIDAK selesai sendiri: uji menyelesaikan tiap ucapan secara manual. */
    private class ManualEngine(override var ready: Boolean = true) : SpeechEngine {
        val spoken = mutableListOf<String>()
        private val done = ArrayDeque<Pair<() -> Unit, () -> Unit>>()
        override fun speak(text: String, onDone: () -> Unit, onError: () -> Unit) { spoken += text; done.addLast(onDone to onError) }
        fun finishCurrent() = done.removeFirst().first()
        fun failCurrent() = done.removeFirst().second()
    }

    @Test fun `satu pengumuman diucapkan segera`() {
        val e = ManualEngine(); val q = OrderCallQueue(e)
        assertTrue(q.enqueue("Pesanan dari meja 5"))
        assertEquals(listOf("Pesanan dari meja 5"), e.spoken)
    }

    @Test fun `tiga pesanan berdekatan diucapkan berurutan dan tidak saling menimpa`() {
        val e = ManualEngine(); val q = OrderCallQueue(e)
        q.enqueue("A"); q.enqueue("B"); q.enqueue("C")
        assertEquals(listOf("A"), e.spoken)
        assertEquals(2, q.waiting)
        e.finishCurrent(); assertEquals(listOf("A", "B"), e.spoken)
        e.finishCurrent(); assertEquals(listOf("A", "B", "C"), e.spoken)
        e.finishCurrent(); assertEquals(0, q.waiting)
    }

    @Test fun `kegagalan mesin tidak menahan antrean`() {
        val e = ManualEngine(); val q = OrderCallQueue(e)
        q.enqueue("A"); q.enqueue("B")
        e.failCurrent()
        assertEquals(listOf("A", "B"), e.spoken)
    }

    @Test fun `mesin belum siap membuang pengumuman tanpa menumpuk`() {
        val e = ManualEngine(ready = false); val q = OrderCallQueue(e)
        assertFalse(q.enqueue("A"))
        assertTrue(e.spoken.isEmpty()); assertEquals(0, q.waiting)
        e.ready = true
        assertTrue(q.enqueue("B"))
        assertEquals(listOf("B"), e.spoken)
    }

    @Test fun `antrean dibatasi dan yang tertua dibuang`() {
        val e = ManualEngine(); val q = OrderCallQueue(e, maxPending = 2)
        q.enqueue("A")                      // sedang diucapkan
        q.enqueue("B"); q.enqueue("C"); q.enqueue("D")
        assertEquals(2, q.waiting)
        e.finishCurrent(); e.finishCurrent(); e.finishCurrent()
        assertEquals(listOf("A", "C", "D"), e.spoken)
    }

    @Test fun `kalimat hanya memuat nama meja`() {
        assertEquals("Pesanan dari meja 5", SelfServicePhrase.spoken("5"))
        assertEquals("Pesanan dari meja A12", SelfServicePhrase.spoken("  A12 "))
        assertEquals("Pesanan dari meja tidak diketahui", SelfServicePhrase.spoken(""))
    }
}
