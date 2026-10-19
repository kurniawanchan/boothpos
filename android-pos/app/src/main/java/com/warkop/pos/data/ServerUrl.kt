package com.warkop.pos.data

/**
 * 056 / 059 — satu aturan untuk URL gambar yang dikirim server (QR kanal, logo struk). Server boleh memberi URL
 * dengan host `localhost` (APP_URL di server), yang tidak bisa dijangkau tablet. Jalur dan query dipertahankan,
 * lalu dipasang ke alamat server yang sedang dipakai tablet. Dipindahkan dari QrisMapper agar tidak ada dua salinan.
 */
object ServerUrl {
    /** [serverBase] null/kosong → URL dikembalikan apa adanya; [url] kosong → null. */
    fun resolve(serverBase: String?, url: String?): String? {
        val raw = url?.trim()?.takeIf { it.isNotBlank() } ?: return null
        val base = serverBase?.trim()?.trimEnd('/')?.takeIf { it.isNotBlank() } ?: return raw
        val uri = runCatching { java.net.URI(raw) }.getOrNull() ?: return raw
        val location = buildString {
            append(uri.rawPath ?: "")
            uri.rawQuery?.let { append('?').append(it) }
        }.ifBlank { return raw }
        return base + (if (location.startsWith("/")) location else "/$location")
    }
}
