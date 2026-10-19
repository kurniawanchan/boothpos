package com.warkop.pos.audio

/**
 * 061 / T008 — mesin ucap yang bisa diganti. Implementasi nyata ada di [TtsSpeechEngine]; uji memakai versi palsu.
 * [onDone] dan [onError] wajib dipanggil tepat sekali per [speak] yang diterima.
 */
interface SpeechEngine {
    val ready: Boolean
    fun speak(text: String, onDone: () -> Unit, onError: () -> Unit)
}

/** Mesin kosong: dipakai bila tidak ada mesin ucap (dan sebagai default uji). Tidak pernah bersuara. */
object SilentSpeech : SpeechEngine {
    override val ready: Boolean = false
    override fun speak(text: String, onDone: () -> Unit, onError: () -> Unit) = onError()
}

/**
 * 061 / T008 — panggilan nomor antrean, satu per satu. Tap kedua saat masih berbicara diabaikan (FR-008), dan
 * kegagalan mesin tidak pernah melempar ke layar (FR-007). Satu instance per proses: mesin ucap dibuat sekali.
 */
class QueueAnnouncer(private val engine: SpeechEngine) {
    private var busy = false

    /** true bila ucapan dimulai; false bila masih berbicara atau mesin belum siap. */
    fun announce(text: String): Boolean {
        if (busy || !engine.ready) return false
        busy = true
        engine.speak(text, onDone = { busy = false }, onError = { busy = false })
        return true
    }
}
