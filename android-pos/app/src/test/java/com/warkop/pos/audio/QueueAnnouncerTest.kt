package com.warkop.pos.audio

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

/** 061 / T010 — penjaga panggilan ganda dan kegagalan mesin ucap, dengan mesin palsu. */
class QueueAnnouncerTest {
    /** Mesin palsu: mencatat ucapan dan membiarkan uji menyelesaikannya (done atau error). */
    private class FakeSpeechEngine(override val ready: Boolean = true) : SpeechEngine {
        val spoken = mutableListOf<String>()
        private var pendingDone: (() -> Unit)? = null
        private var pendingError: (() -> Unit)? = null
        override fun speak(text: String, onDone: () -> Unit, onError: () -> Unit) {
            spoken += text; pendingDone = onDone; pendingError = onError
        }
        fun finish() { pendingDone?.invoke() }
        fun fail() { pendingError?.invoke() }
    }

    @Test fun `bicara saat tidak sedang berbicara`() {
        val engine = FakeSpeechEngine()
        assertTrue(QueueAnnouncer(engine).announce("A, nol, satu, dua"))
        assertEquals(listOf("A, nol, satu, dua"), engine.spoken)
    }

    @Test fun `panggilan kedua saat masih berbicara diabaikan`() {
        val engine = FakeSpeechEngine()
        val announcer = QueueAnnouncer(engine)
        announcer.announce("A, nol, satu, dua")
        assertFalse(announcer.announce("A, nol, satu, dua"))
        assertEquals(1, engine.spoken.size)
    }

    @Test fun `selesai berbicara membuka panggilan berikutnya`() {
        val engine = FakeSpeechEngine()
        val announcer = QueueAnnouncer(engine)
        announcer.announce("B, tujuh")
        engine.finish()
        assertTrue(announcer.announce("B, tujuh"))
        assertEquals(2, engine.spoken.size)
    }

    @Test fun `gagal berbicara juga membuka panggilan berikutnya`() {
        val engine = FakeSpeechEngine()
        val announcer = QueueAnnouncer(engine)
        announcer.announce("B, tujuh")
        engine.fail()
        assertTrue(announcer.announce("B, tujuh"))
    }

    @Test fun `mesin belum siap tidak bersuara dan tidak melempar`() {
        val engine = FakeSpeechEngine(ready = false)
        assertFalse(QueueAnnouncer(engine).announce("A, nol, satu, dua"))
        assertTrue(engine.spoken.isEmpty())
    }

    @Test fun `mesin kosong tidak pernah bersuara`() {
        assertFalse(QueueAnnouncer(SilentSpeech).announce("A, satu"))
    }
}
