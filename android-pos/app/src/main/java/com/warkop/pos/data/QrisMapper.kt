package com.warkop.pos.data

import com.warkop.core.network.dto.PaymentChannelDto
import com.warkop.pos.model.QrisChannel

/**
 * 056 / T005 — aturan kanal QRIS statis di satu tempat: judul lembar dan pemetaan dari DTO server. Fungsi murni
 * supaya bisa diuji tanpa perangkat (research R1, R3).
 */
object QrisMapper {
    private const val FALLBACK_PROVIDER = "QRIS"

    /** Judul "penyedia pemilik"; tanpa nama pemilik cukup penyedia; penyedia kosong menjadi "QRIS". */
    fun sheetTitle(provider: String, accountName: String?): String {
        val p = provider.trim().ifBlank { FALLBACK_PROVIDER }
        val a = accountName?.trim().orEmpty()
        return if (a.isBlank()) p else "$p $a"
    }

    /**
     * 056 amandemen — server menyimpan URL gambar dengan host dari APP_URL (sering `localhost`), yang di tablet adalah
     * perangkat itu sendiri. Yang dipakai hanya lokasi (path + query), disambungkan ke alamat server yang sedang
     * dipakai tablet ([serverBase]). Tanpa alamat server, URL asli dipakai apa adanya.
     */
    /** Aturan URL dipindah ke [ServerUrl] (dipakai juga oleh logo struk, 059). */
    fun resolveQrUrl(serverBase: String?, qrImageUrl: String?): String? = ServerUrl.resolve(serverBase, qrImageUrl)

    fun toChannel(dto: PaymentChannelDto, serverBase: String? = null): QrisChannel = QrisChannel(
        id = dto.id,
        provider = dto.provider?.trim().orEmpty().ifBlank { FALLBACK_PROVIDER },
        accountName = dto.accountName?.trim()?.takeIf { it.isNotBlank() },
        qrImageUrl = resolveQrUrl(serverBase, dto.qrImageUrl),
        canManageQr = dto.canManageQr,
    )

    /** 056 amandemen — server menerima JPEG dan PNG saja (PaymentChannelController: mimes:jpeg,png). */
    fun isAllowedQrMime(mime: String?): Boolean = mime == "image/jpeg" || mime == "image/png"
}
