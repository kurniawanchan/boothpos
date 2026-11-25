package com.warkop.manager.domain

import com.warkop.core.domain.ApiError

/**
 * 094 / T038 — keadaan penyimpanan formulir, satu bentuk untuk semua layar (FR-016): 422 → galat per kolom dengan kunci
 * SAMA dengan payload web, 409 → pesan konflik dari server, 403 → pesan akses. Nilai isian disimpan oleh ViewModel
 * masing-masing di SavedStateHandle; kelas ini hanya status simpan.
 */
data class FormStatus(
    val saving: Boolean = false,
    val fieldErrors: Map<String, String> = emptyMap(),
    val message: String? = null,
) {
    fun error(field: String): String? = fieldErrors[field]

    /** Galat untuk kolom ini ATAU anak-anaknya (mis. `links.0.max_select` untuk baris tautan ke-0). */
    fun errorUnder(prefix: String): String? = fieldErrors.entries.firstOrNull { it.key == prefix || it.key.startsWith("$prefix.") }?.value

    companion object {
        fun saving() = FormStatus(saving = true)

        fun from(e: ApiError): FormStatus = when (e) {
            is ApiError.Validation -> FormStatus(
                fieldErrors = e.fields.mapValues { (field, msgs) -> readable(field, msgs.firstOrNull()) },
                message = if (e.fields.isEmpty()) e.message else null,
            )
            else -> FormStatus(message = e.message)
        }

        /**
         * Server mengembalikan kunci terjemahan mentah (`validation.required`) bila berkas terjemahan Indonesia tidak ada
         * (temuan evidence.md #1). Kunci seperti itu diganti teks umum, tidak pernah ditampilkan apa adanya.
         */
        fun readable(field: String, message: String?): String {
            if (message.isNullOrBlank()) return "Isian tidak valid."
            if (!RAW_KEY.matches(message)) return message
            return when (message.substringAfter("validation.").substringBefore(' ')) {
                "required", "present" -> "Wajib diisi."
                "max" -> "Terlalu panjang atau terlalu besar."
                "min" -> "Terlalu pendek atau terlalu kecil."
                "unique" -> "Sudah dipakai."
                "exists" -> "Tidak ditemukan."
                "integer", "numeric" -> "Harus berupa angka."
                "email" -> "Format surel tidak valid."
                "date" -> "Tanggal tidak valid."
                else -> "Isian tidak valid."
            }
        }

        private val RAW_KEY = Regex("^validation\\.[a-z_.]+( \\(.*\\))?$")
    }
}
