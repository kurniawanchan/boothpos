package com.boothpos.customer.core.data.domain.validation

/**
 * Masalah bentuk masukan yang dapat diketahui di perangkat. Hanya untuk UX (galat langsung di
 * kolomnya): server tetap memvalidasi ulang dan keputusannya yang berlaku.
 */
sealed interface FieldIssue {
    data object Required : FieldIssue

    data object InvalidFormat : FieldIssue

    data class TooShort(val min: Int) : FieldIssue

    data class TooLong(val max: Int) : FieldIssue
}

/** Panjang seperti yang dihitung aturan `max:`/`min:` Laravel (`mb_strlen`): per karakter, bukan per unit UTF-16. */
fun String.characterCount(): Int = codePointCount(0, length)

/**
 * Aturan nama dan nomor telepon pelanggan (kontrak §5: `name` wajib, maks 100; `phone` opsional,
 * maks 30). Dipakai pendaftaran dan ubah profil, yang berada di modul fitur berbeda.
 */
object ContactRules {
    const val NAME_MAX = 100
    const val PHONE_MAX = 30

    fun name(raw: String): FieldIssue? {
        val value = raw.trim()
        return when {
            value.isEmpty() -> FieldIssue.Required
            value.characterCount() > NAME_MAX -> FieldIssue.TooLong(NAME_MAX)
            else -> null
        }
    }

    /** Kosong diperbolehkan. Server tidak menetapkan format, jadi di sini pun hanya panjangnya. */
    fun phone(raw: String): FieldIssue? =
        if (raw.trim().characterCount() > PHONE_MAX) FieldIssue.TooLong(PHONE_MAX) else null
}
