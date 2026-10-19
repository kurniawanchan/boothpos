package com.warkop.pos.audio

import android.content.Context
import android.speech.tts.TextToSpeech
import android.speech.tts.UtteranceProgressListener
import java.util.Locale

/**
 * 061 / T009 — mesin ucap sistem Android. Bahasa Indonesia bila suaranya terpasang; bila tidak, bahasa default
 * perangkat (spesifikasi, asumsi). Inisialisasi bersifat asinkron: sebelum [ready], [announce] tidak bersuara.
 */
class TtsSpeechEngine(context: Context) : SpeechEngine {
    @Volatile private var initialized = false
    private val tts: TextToSpeech = TextToSpeech(context.applicationContext) { status ->
        if (status != TextToSpeech.SUCCESS) return@TextToSpeech
        val indonesian = Locale("id", "ID")
        val result = tts.setLanguage(indonesian)
        if (result == TextToSpeech.LANG_MISSING_DATA || result == TextToSpeech.LANG_NOT_SUPPORTED) {
            tts.setLanguage(Locale.getDefault())
        }
        initialized = true
    }

    override val ready: Boolean get() = initialized

    override fun speak(text: String, onDone: () -> Unit, onError: () -> Unit) {
        tts.setOnUtteranceProgressListener(object : UtteranceProgressListener() {
            override fun onStart(utteranceId: String?) = Unit
            override fun onDone(utteranceId: String?) = onDone()
            @Deprecated("Deprecated in Java")
            override fun onError(utteranceId: String?) = onError()
            override fun onError(utteranceId: String?, errorCode: Int) = onError()
        })
        val queued = tts.speak(text, TextToSpeech.QUEUE_FLUSH, null, "queue-call")
        if (queued == TextToSpeech.ERROR) onError()
    }
}
