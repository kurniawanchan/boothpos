package com.warkop.pos.state

/**
 * 055 / T034 — tampil/sembunyi area total di counter, disimpan di perangkat (spec: diingat sampai diubah). Penyimpanan
 * disuntikkan lewat dua lambda supaya logika ini bisa diuji tanpa Context; instance Android dibuat di PosApplication
 * memakai shared preferences. Bukan TokenVault: ini pilihan tampilan, bukan rahasia.
 */
class TotalsPreference(private val read: () -> Boolean?, private val write: (Boolean) -> Unit) {
    var visible: Boolean
        get() = read() ?: true
        set(value) = write(value)
}
