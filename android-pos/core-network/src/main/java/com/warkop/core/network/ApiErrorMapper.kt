package com.warkop.core.network

import com.warkop.core.domain.ApiError
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.contentOrNull
import kotlinx.serialization.json.intOrNull
import kotlinx.serialization.json.jsonArray
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import java.io.IOException

/**
 * Satu-satunya tempat kode HTTP + badan galat → [ApiError] (tabel di contracts/android-client.md). Badan galat
 * server berbentuk `{message, code?, errors?, locked_until?, attempts_remaining?}`; badan yang bukan JSON (mis.
 * halaman HTML dari proksi) diganti pesan bawaan, tidak pernah ditampilkan mentah.
 */
object ApiErrorMapper {
    private val json = Json { ignoreUnknownKeys = true }

    fun fromHttp(status: Int, body: String?): ApiError {
        val obj: JsonObject? = runCatching { body?.let { json.parseToJsonElement(it).jsonObject } }.getOrNull()
        val message = obj?.get("message")?.jsonPrimitive?.contentOrNull?.takeIf { it.isNotBlank() }
        val code = obj?.get("code")?.jsonPrimitive?.contentOrNull

        val error: ApiError = when (status) {
            401 -> ApiError.Unauthorized(message ?: "Sesi berakhir. Masuk kembali.", code)
            403 -> ApiError.Forbidden(localMessage(code) ?: message ?: "Anda tidak memiliki izin untuk aksi ini.", code)
            404 -> ApiError.NotFound(message ?: "Data tidak ditemukan.")
            409 -> ApiError.Conflict(localMessage(code) ?: message ?: "Aksi tidak dapat dilakukan saat ini.", code)
            422 -> ApiError.Validation(message ?: "Data tidak valid.", fieldErrors(obj))
            423 -> ApiError.Locked(message ?: "Perangkat belum diaktivasi.")
            429 -> ApiError.TooMany(message ?: "Terlalu banyak percobaan. Coba lagi nanti.", code, obj?.get("locked_until")?.jsonPrimitive?.contentOrNull)
            503 -> if (code == "schema_outdated") ApiError.SchemaOutdated(message ?: "Server perlu diperbarui.") else ApiError.Server()
            else -> ApiError.Server()
        }
        error.attemptsRemaining = obj?.get("attempts_remaining")?.jsonPrimitive?.intOrNull
        return error
    }

    fun fromThrowable(t: Throwable): ApiError = when (t) {
        is ApiError -> t
        is IOException -> ApiError.Network()
        else -> ApiError.Server()
    }

    private fun fieldErrors(obj: JsonObject?): Map<String, List<String>> {
        val errors = obj?.get("errors") as? JsonObject ?: return emptyMap()
        return errors.mapValues { (_, v) -> runCatching { v.jsonArray.map { it.jsonPrimitive.content } }.getOrDefault(emptyList()) }
    }
}

/**
 * Teks Indonesia untuk kode mesin aturan shift dan tab. Server menjawab dalam bahasa akun pengguna (default
 * Inggris), sedangkan aplikasi ini berbahasa Indonesia; kode yang tidak dikenal memakai pesan server apa adanya.
 */
internal fun localMessage(code: String?): String? = when (code) {
    "override_required" -> "Aksi ini butuh persetujuan, dan persetujuan belum tersedia di aplikasi ini."
    "session_closed" -> "Shift sudah ditutup."
    "shift_required" -> "Shift belum dibuka. Buka shift dulu untuk menerima pembayaran."
    "pending_payments" -> "Ada pembayaran QRIS yang belum dicek. Cek dulu sebelum menutup shift."
    "session_not_owner" -> "Kas hanya bisa dicatat di shift Anda sendiri."
    "outlet_forbidden" -> "Outlet ini tidak termasuk outlet yang boleh Anda akses."
    "open_tabs" -> "Shift hanya bisa ditutup setelah semua tab terbuka dibayar, dibatalkan, atau diserahkan."
    else -> null
}
