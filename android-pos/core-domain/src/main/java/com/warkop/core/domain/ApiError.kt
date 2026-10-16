package com.warkop.core.domain

/**
 * Galat API yang sudah dipetakan (contracts/android-client.md). Layar hanya berurusan dengan tipe ini, tidak
 * dengan kode HTTP mentah. `message` selalu teks ramah (Indonesia dari server, atau bawaan klien) tanpa detail teknis.
 */
sealed class ApiError(override val message: String, val code: String? = null) : Exception(message) {
    /** Tidak ada jaringan / server tak terjangkau / timeout. Tulis yang timeout berstatus TIDAK DIKETAHUI. */
    class Network(message: String = "Tidak dapat menjangkau server. Periksa koneksi lalu coba lagi.") : ApiError(message, "network")

    /** 401 — sesi tidak sah/habis. */
    class Unauthorized(message: String, code: String? = null) : ApiError(message, code)

    /** 403 — izin/outlet. */
    class Forbidden(message: String, code: String? = null) : ApiError(message, code)

    /** 404. */
    class NotFound(message: String) : ApiError(message, "not_found")

    /** 409 — konflik aturan bisnis; `code` menentukan alur (mis. override_required, duplicate_edc). */
    class Conflict(message: String, code: String?) : ApiError(message, code)

    /** 422 — galat per kolom. */
    class Validation(message: String, val fields: Map<String, List<String>>) : ApiError(message, "validation")

    /** 423 — perangkat belum diaktivasi lisensi. */
    class Locked(message: String) : ApiError(message, "license_locked")

    /** 429 — terkunci / terlalu sering; `lockedUntil` ISO-8601 bila server menyebut. */
    class TooMany(message: String, code: String?, val lockedUntil: String?) : ApiError(message, code)

    /** 503 schema_outdated — server perlu diperbarui. */
    class SchemaOutdated(message: String) : ApiError(message, "schema_outdated")

    /** Respons tak terduga (5xx lain / badan tak terbaca). */
    class Server(message: String = "Terjadi gangguan di server. Coba lagi sebentar lagi.") : ApiError(message, "server")

    /** Sisa percobaan PIN dari 401 invalid_pin (null bila tak ada). */
    var attemptsRemaining: Int? = null
}
