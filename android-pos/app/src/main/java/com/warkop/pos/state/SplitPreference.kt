package com.warkop.pos.state

/** 077 / US5 — porsi lebar area produk di Counter (sisanya untuk panel order). Bawaan sama dengan tata letak lama 60/40. */
const val SPLIT_DEFAULT = 0.6f
const val SPLIT_MIN = 0.35f
const val SPLIT_MAX = 0.75f

/** Lebar minimum tiap sisi (dp): di bawah ini kartu produk atau panel order tidak lagi terpakai. */
const val SPLIT_PANE_MIN_DP = 320f

/**
 * 077 — batasi [fraction] ke [SPLIT_MIN]..[SPLIT_MAX] dan pastikan kedua sisi tetap ≥ [SPLIT_PANE_MIN_DP] pada lebar [totalDp].
 * Nilai tersimpan dari tablet lain dipotong lagi saat dibaca, jadi layar yang lebih sempit tidak menyembunyikan satu sisi.
 * Bila lebar terlalu sempit untuk memenuhi dua batas sekaligus, kembali ke bagi dua.
 */
fun clampSplit(fraction: Float, totalDp: Float): Float {
    var lo = SPLIT_MIN
    var hi = SPLIT_MAX
    if (totalDp > 0f) {
        lo = maxOf(lo, SPLIT_PANE_MIN_DP / totalDp)
        hi = minOf(hi, 1f - SPLIT_PANE_MIN_DP / totalDp)
    }
    if (lo > hi) return 0.5f
    return fraction.coerceIn(lo, hi)
}

/**
 * 077 — pilihan lebar Counter, disimpan di perangkat (bukan per pengguna/outlet, spec). Penyimpanan disuntikkan lewat dua lambda
 * seperti [TotalsPreference] supaya bisa diuji tanpa Context; nilai rusak (NaN/di luar 0..1) dianggap tidak ada.
 */
class SplitPreference(private val read: () -> Float?, private val write: (Float) -> Unit) {
    var fraction: Float
        get() = read()?.takeIf { it.isFinite() && it > 0f && it < 1f } ?: SPLIT_DEFAULT
        set(value) = write(value)
}
