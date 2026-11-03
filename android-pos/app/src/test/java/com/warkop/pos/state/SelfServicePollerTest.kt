package com.warkop.pos.state

import com.warkop.pos.model.SelfServiceAlert
import com.warkop.pos.model.SelfServiceAlerts
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.test.TestScope
import kotlinx.coroutines.test.advanceTimeBy
import kotlinx.coroutines.test.runCurrent
import kotlinx.coroutines.test.runTest
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

/** 090 / US8 — catch-up tanpa suara, pengumuman hanya untuk yang baru, jeda naik saat gagal, tanpa sesi tidak polling. */
@OptIn(ExperimentalCoroutinesApi::class)
class SelfServicePollerTest {
    private fun alert(id: Long, table: String = "5") = SelfServiceAlert(id, table, "SS-$id", "10000.00")

    private class Rig(val scope: kotlinx.coroutines.CoroutineScope) {
        val calls = mutableListOf<Long?>()
        val results = mutableListOf<Pair<SelfServiceAlerts, Boolean>>()
        var next: (Long?) -> SelfServiceAlerts? = { SelfServiceAlerts(emptyList(), 0, it ?: 0) }
        val poller = SelfServicePoller(scope, intervalMs = 5_000, maxBackoffMs = 60_000, fetch = { calls += it; next(it) }, onResult = { r, a -> results += r to a })
    }

    @Test fun `panggilan pertama adalah catch-up tanpa item dan tanpa pengumuman`() = runTest {
        val rig = Rig(this)
        rig.next = { SelfServiceAlerts(if (it == null) listOf(alert(1), alert(2)) else emptyList(), 2, 2) }
        rig.poller.tick()
        assertEquals(listOf<Long?>(null), rig.calls)
        val (r, announce) = rig.results.single()
        assertTrue(r.items.isEmpty()); assertFalse(announce); assertEquals(2, r.waiting)
    }

    @Test fun `sesudah catch-up pesanan baru diumumkan dengan kursor yang benar`() = runTest {
        val rig = Rig(this)
        rig.next = { SelfServiceAlerts(emptyList(), 1, 7) }
        rig.poller.tick()
        rig.next = { SelfServiceAlerts(listOf(alert(8), alert(9)), 3, 9) }
        rig.poller.tick()
        assertEquals(listOf<Long?>(null, 7L), rig.calls)
        val (r, announce) = rig.results.last()
        assertEquals(listOf(8L, 9L), r.items.map { it.id }); assertTrue(announce)
        rig.next = { SelfServiceAlerts(emptyList(), 3, 9) }
        rig.poller.tick()
        assertEquals(9L, rig.calls.last())
    }

    @Test fun `sesudah gangguan jaringan hasil pertama kembali catch-up tanpa suara`() = runTest {
        val rig = Rig(this)
        rig.poller.tick()                               // catch-up awal
        rig.next = { throw java.io.IOException("offline") }
        rig.poller.tick()                               // gagal
        rig.next = { SelfServiceAlerts(listOf(alert(5), alert(6)), 2, 6) }
        rig.poller.tick()                               // tersambung lagi
        val (r, announce) = rig.results.last()
        assertTrue("tidak memutar ulang pesanan lama", r.items.isEmpty()); assertFalse(announce)
        assertNull(rig.calls.last())                    // minta inisialisasi, bukan kursor lama
    }

    @Test fun `jeda naik eksponensial saat gagal dan dibatasi satu menit`() = runTest {
        val rig = Rig(this)
        rig.next = { throw java.io.IOException("x") }
        assertEquals(5_000L, rig.poller.nextDelayMs())
        rig.poller.tick(); assertEquals(10_000L, rig.poller.nextDelayMs())
        rig.poller.tick(); assertEquals(20_000L, rig.poller.nextDelayMs())
        repeat(6) { rig.poller.tick() }
        assertEquals(60_000L, rig.poller.nextDelayMs())
        rig.next = { SelfServiceAlerts(emptyList(), 0, 0) }
        rig.poller.tick()
        assertEquals(5_000L, rig.poller.nextDelayMs())
    }

    @Test fun `tanpa sesi fetch null tidak dihitung gagal dan kursor tidak disentuh`() = runTest {
        val rig = Rig(this)
        rig.next = { null }
        rig.poller.tick(); rig.poller.tick()
        assertTrue(rig.results.isEmpty())
        assertEquals(5_000L, rig.poller.nextDelayMs())
        rig.next = { SelfServiceAlerts(emptyList(), 0, 3) }
        rig.poller.tick()
        assertNull(rig.calls.last())                    // masih catch-up
        assertEquals(1, rig.results.size)
    }

    @Test fun `loop berjalan tiap lima detik dan berhenti saat stop`() = runTest {
        val rig = Rig(this)
        rig.poller.start()
        runCurrent()
        assertEquals(1, rig.calls.size)
        advanceTimeBy(5_001); runCurrent()
        assertEquals(2, rig.calls.size)
        rig.poller.stop()
        advanceTimeBy(20_000); runCurrent()
        assertEquals(2, rig.calls.size)
        assertFalse(rig.poller.running)
    }

    @Test fun `start ulang selalu catch-up lagi`() = runTest {
        val rig = Rig(this)
        rig.poller.start(); runCurrent(); rig.poller.stop()
        rig.poller.start(); runCurrent(); rig.poller.stop()
        assertEquals(listOf<Long?>(null, null), rig.calls)
        assertTrue(rig.results.all { !it.second })
    }
}
