package com.warkop.pos.print

import kotlinx.serialization.Serializable
import kotlinx.serialization.json.Json
import java.io.File

/**
 * 050 / T012 — printer yang dipilih di tablet ini. Kertas selalu 58 mm dan tidak bisa diubah pengguna (FR-003),
 * sehingga tidak disimpan sebagai field. Tidak ada file = belum dipasang.
 */
@Serializable
data class PrinterConfig(val name: String, val address: String)

class PrinterSettings(private val file: File) {
    private val json = Json { ignoreUnknownKeys = true }

    /** null bila belum dipasang atau file rusak (rusak = dipasang ulang; tidak menggagalkan aplikasi). */
    fun load(): PrinterConfig? {
        if (!file.exists()) return null
        return runCatching { json.decodeFromString(PrinterConfig.serializer(), file.readText()) }.getOrNull()
    }

    fun save(config: PrinterConfig) {
        file.writeText(json.encodeToString(PrinterConfig.serializer(), config))
    }

    fun clear() {
        file.delete()
    }
}
