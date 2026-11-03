package com.warkop.pos.audio

/**
 * 090 / US8 — antrean ucapan pesanan mandiri: satu pengumuman selesai dulu baru yang berikutnya, sehingga beberapa pesanan yang
 * masuk berdekatan terdengar satu per satu dan tidak saling menimpa (FR-040). Berbeda dari [QueueAnnouncer] (panggilan nomor
 * antrean) yang sengaja MENGABAIKAN permintaan saat masih berbicara; di sini tidak boleh ada pesanan yang terlewat.
 *
 * Kegagalan mesin ucap tidak pernah menahan antrean: [onError] diperlakukan seperti selesai. Mesin yang belum siap = tidak
 * bersuara (pengumuman dibuang; pemberitahuan visual tetap menjadi jalur utama). Antrean dibatasi supaya gelombang besar tidak
 * berbicara berjam-jam; yang tertua dibuang lebih dulu.
 */
class OrderCallQueue(private val engine: SpeechEngine, private val maxPending: Int = MAX_PENDING) {
    private val pending = ArrayDeque<String>()
    private var busy = false

    /** true bila pengumuman diterima (diucapkan sekarang atau menunggu giliran); false bila mesin belum siap. */
    @Synchronized
    fun enqueue(text: String): Boolean {
        if (!engine.ready) return false
        pending.addLast(text)
        while (pending.size > maxPending) pending.removeFirst()
        drain()
        return true
    }

    /** Jumlah pengumuman yang masih menunggu giliran (tidak termasuk yang sedang diucapkan). Untuk uji. */
    @get:Synchronized val waiting: Int get() = pending.size

    @Synchronized
    private fun drain() {
        if (busy) return
        val next = pending.removeFirstOrNull() ?: return
        busy = true
        // Mesin memanggil onDone/onError tepat sekali; keduanya melepas giliran dan melanjutkan antrean.
        engine.speak(next, onDone = ::finished, onError = ::finished)
    }

    @Synchronized
    private fun finished() {
        busy = false
        drain()
    }

    companion object {
        const val MAX_PENDING = 20
    }
}
