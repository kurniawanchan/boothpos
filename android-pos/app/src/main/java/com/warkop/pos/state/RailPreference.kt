package com.warkop.pos.state

/**
 * 084 — tampil/sembunyi sidebar navigasi (tombol hamburger), disimpan di perangkat seperti [SplitPreference] dan
 * [TotalsPreference]: penyimpanan disuntikkan lewat dua lambda supaya teruji tanpa Context. Bawaan tampil.
 */
class RailPreference(private val read: () -> Boolean?, private val write: (Boolean) -> Unit) {
    var visible: Boolean
        get() = read() ?: true
        set(value) = write(value)
}
