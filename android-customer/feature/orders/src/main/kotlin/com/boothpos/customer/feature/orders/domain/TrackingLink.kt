package com.boothpos.customer.feature.orders.domain

import java.net.URI
import java.net.URISyntaxException

/**
 * Satu-satunya aturan "tautan pelacakan mana yang boleh dibuka". Server sudah membentuk
 * `tracking_url` dari templat kurir yang diizinkan (kontrak §8), tetapi jawaban server tetap
 * diperlakukan sebagai data tak tepercaya: aplikasi hanya membuka tautan `https://` yang wajar,
 * dan hanya di peramban luar (tidak pernah WebView).
 */
object TrackingLink {
    private const val SCHEME_PREFIX = "https://"
    private const val MAX_LENGTH = 2048

    fun safeOrNull(raw: String?): String? {
        if (raw == null || raw.length > MAX_LENGTH) return null
        // Persis "https://" (huruf kecil), tanpa spasi di depan: skema lain (http, intent,
        // javascript, file, content, …) tidak pernah lolos.
        if (!raw.startsWith(SCHEME_PREFIX)) return null
        // Spasi atau karakter kendali di mana pun menandakan tautan yang rusak atau dimanipulasi.
        if (raw.any { it.isWhitespace() || it.isISOControl() }) return null
        val uri = try {
            URI(raw)
        } catch (_: URISyntaxException) {
            return null
        }
        if (uri.isOpaque || uri.host.isNullOrBlank()) return null
        // `https://kurir.example@lain.example/…` menampilkan satu nama tetapi membuka host lain.
        if (uri.rawUserInfo != null) return null
        return raw
    }
}
