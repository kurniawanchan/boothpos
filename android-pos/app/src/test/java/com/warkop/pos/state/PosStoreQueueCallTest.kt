package com.warkop.pos.state

import com.warkop.pos.audio.QueueAnnouncer
import com.warkop.pos.audio.SpeechEngine
import com.warkop.pos.model.Order
import com.warkop.pos.model.OrderKind
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Test

/** 061 / T012 — "Panggil pelanggan" di toko: diucapkan sekali, pesan tetap muncul, tanpa nomor tidak bersuara. */
class PosStoreQueueCallTest {
    private class FakeSpeech(override val ready: Boolean = true) : SpeechEngine {
        val spoken = mutableListOf<String>()
        override fun speak(text: String, onDone: () -> Unit, onError: () -> Unit) { spoken += text }
    }

    private fun order(queue: String?) = Order(
        id = "1", kind = OrderKind.COUNTER, table = null, pax = 0, queue = queue, waiter = "Budi", openedAt = "14:02",
    )

    @Test fun `panggil mengucapkan nomor dan menampilkan pesan`() {
        val engine = FakeSpeech()
        val store = PosStore(announcer = QueueAnnouncer(engine))
        store.callQueue(order("A-012"))
        assertEquals(listOf("A, nol, satu, dua"), engine.spoken)
        assertEquals("Dipanggil A-012", store.toast)
    }

    @Test fun `tanpa nomor antrean tidak bersuara dan tidak ada pesan`() {
        val engine = FakeSpeech()
        val store = PosStore(announcer = QueueAnnouncer(engine))
        store.callQueue(order(null))
        assertEquals(emptyList<String>(), engine.spoken)
        assertNull(store.toast)
    }

    @Test fun `tap kedua saat masih berbicara tidak bersuara lagi tetapi pesan tetap muncul`() {
        val engine = FakeSpeech()
        val store = PosStore(announcer = QueueAnnouncer(engine))
        store.callQueue(order("A-012"))
        store.callQueue(order("A-012"))
        assertEquals(1, engine.spoken.size)
        assertEquals("Dipanggil A-012", store.toast)
    }

    @Test fun `mesin tidak siap tetap menampilkan pesan dan tidak melempar`() {
        val store = PosStore(announcer = QueueAnnouncer(FakeSpeech(ready = false)))
        store.callQueue(order("B-7"))
        assertEquals("Dipanggil B-7", store.toast)
    }
}
