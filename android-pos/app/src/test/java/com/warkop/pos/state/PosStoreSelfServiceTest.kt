package com.warkop.pos.state

import com.warkop.pos.audio.OrderCallQueue
import com.warkop.pos.audio.SpeechEngine
import com.warkop.pos.model.SelfServiceAlert
import com.warkop.pos.model.SelfServiceAlerts
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

/** 090 / US8 — spanduk, jumlah menunggu, dan suara per perangkat di PosStore. */
class PosStoreSelfServiceTest {
    private class RecordingEngine : SpeechEngine {
        override val ready = true
        val spoken = mutableListOf<String>()
        override fun speak(text: String, onDone: () -> Unit, onError: () -> Unit) { spoken += text; onDone() }
    }
    private fun alert(id: Long, table: String) = SelfServiceAlert(id, table, "SS-$id", "55000.00")
    private fun store(engine: RecordingEngine, voice: Boolean? = null) =
        PosStore(orderCalls = OrderCallQueue(engine), selfServicePref = SelfServicePreference({ voice }, {}))

    @Test fun `pesanan baru menampilkan spanduk dan mengucapkan meja satu per satu`() {
        val engine = RecordingEngine(); val s = store(engine)
        s.onSelfServiceResult(SelfServiceAlerts(listOf(alert(1, "5"), alert(2, "7")), 2, 2), announce = true)
        assertEquals(listOf("Pesanan dari meja 5", "Pesanan dari meja 7"), engine.spoken)
        assertEquals(2, s.selfServiceNotice.size); assertEquals(2, s.selfServiceWaiting)
        assertEquals("Rp 55.000", s.selfServiceNotice.first().totalLabel)
    }

    @Test fun `catch-up hanya memperbarui jumlah menunggu tanpa suara atau spanduk`() {
        val engine = RecordingEngine(); val s = store(engine)
        s.onSelfServiceResult(SelfServiceAlerts(listOf(alert(1, "5")), 4, 1), announce = false)
        assertTrue(engine.spoken.isEmpty()); assertTrue(s.selfServiceNotice.isEmpty()); assertEquals(4, s.selfServiceWaiting)
    }

    @Test fun `suara dimatikan tetap menampilkan spanduk`() {
        val engine = RecordingEngine(); val s = store(engine, voice = false)
        assertFalse(s.selfServiceVoice)
        s.onSelfServiceResult(SelfServiceAlerts(listOf(alert(1, "5")), 1, 1), announce = true)
        assertTrue(engine.spoken.isEmpty()); assertEquals(1, s.selfServiceNotice.size)
    }

    @Test fun `pengaturan suara diingat per perangkat`() {
        var saved: Boolean? = null
        val s = PosStore(selfServicePref = SelfServicePreference({ saved }, { saved = it }))
        assertTrue(s.selfServiceVoice)
        s.changeSelfServiceVoice(false)
        assertEquals(false, saved); assertFalse(s.selfServiceVoice)
        assertTrue(SelfServicePreference({ null }, {}).voiceEnabled)
    }

    @Test fun `spanduk dapat ditutup dan hanya menyimpan lima terakhir`() {
        val s = store(RecordingEngine())
        s.onSelfServiceResult(SelfServiceAlerts((1L..7L).map { alert(it, "$it") }, 7, 7), announce = true)
        assertEquals(listOf(3L, 4L, 5L, 6L, 7L), s.selfServiceNotice.map { it.id })
        s.dismissSelfServiceNotice()
        assertTrue(s.selfServiceNotice.isEmpty())
    }

    @Test fun `spanduk tidak memuat data pelanggan`() {
        val dump = alert(1, "5").toString()
        assertFalse(dump.contains("phone", ignoreCase = true)); assertFalse(dump.contains("email", ignoreCase = true))
    }
}
