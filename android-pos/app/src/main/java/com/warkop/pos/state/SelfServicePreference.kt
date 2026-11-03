package com.warkop.pos.state

/**
 * 090 / US8 — suara notifikasi pesanan mandiri hidup/mati, disimpan per PERANGKAT (FR-041), seperti [RailPreference]:
 * penyimpanan disuntikkan lewat dua lambda supaya teruji tanpa Context. Bawaan hidup. Mematikan suara tidak mematikan
 * pemberitahuan visual.
 */
class SelfServicePreference(private val read: () -> Boolean?, private val write: (Boolean) -> Unit) {
    var voiceEnabled: Boolean
        get() = read() ?: true
        set(value) = write(value)
}
