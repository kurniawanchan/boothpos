package com.warkop.pos.state

/** 057 / US3 — aturan nomor salinan: salinan asli = 1, cetak ulang berikutnya = jumlah yang sudah diterima server + 2. */
object ReprintRules {
    fun nextCopyNo(reprintsAlreadyReceived: Int): Int = reprintsAlreadyReceived + 2
}
