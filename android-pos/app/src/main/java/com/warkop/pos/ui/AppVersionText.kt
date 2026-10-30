package com.warkop.pos.ui

/**
 * 085 / US1 — sel "Versi aplikasi" di layar Koneksi: nilai = versi, catatan = penanda pembangunan. Penanda kosong atau
 * "dev" (git tidak terbaca saat membangun) dijelaskan apa adanya, bukan disembunyikan, supaya pemilik tahu APK itu
 * tidak bisa dicocokkan dengan commit.
 */
fun appVersionCell(version: String, build: String): Triple<String, String, String> {
    val b = build.trim()
    val note = if (b.isEmpty() || b == "dev") "Pembangunan tanpa penanda git (dev)" else "Build $b"
    return Triple("Versi aplikasi", version, note)
}
