package com.boothpos.customer.feature.auth.domain

import com.boothpos.customer.core.data.domain.validation.FieldIssue
import com.boothpos.customer.core.data.domain.validation.characterCount

/**
 * Aturan bentuk e-mail dan kata sandi, mencerminkan validasi server (kontrak §5: e-mail wajib,
 * format e-mail, maks 100; kata sandi min 8, maks 72). Hanya untuk galat langsung di kolom;
 * server tetap penentu.
 */
object CredentialRules {
    const val EMAIL_MAX = 100
    const val PASSWORD_MIN = 8
    const val PASSWORD_MAX = 72

    // Sengaja longgar: satu "@", tanpa spasi, domain bertitik. Server yang memutuskan sah-tidaknya.
    private val EMAIL_SHAPE = Regex("^[^\\s@]+@[^\\s@.]+(\\.[^\\s@.]+)+$")

    /** Server memangkas dan mengecilkan huruf e-mail; aplikasi cukup memangkasnya. */
    fun normaliseEmail(raw: String): String = raw.trim()

    fun email(raw: String): FieldIssue? {
        val value = normaliseEmail(raw)
        return when {
            value.isEmpty() -> FieldIssue.Required
            value.characterCount() > EMAIL_MAX -> FieldIssue.TooLong(EMAIL_MAX)
            !EMAIL_SHAPE.matches(value) -> FieldIssue.InvalidFormat
            else -> null
        }
    }

    /** Kata sandi baru (pendaftaran). Tidak dipangkas: spasi adalah karakter kata sandi yang sah. */
    fun newPassword(raw: String): FieldIssue? = when {
        raw.isEmpty() -> FieldIssue.Required
        raw.characterCount() < PASSWORD_MIN -> FieldIssue.TooShort(PASSWORD_MIN)
        raw.characterCount() > PASSWORD_MAX -> FieldIssue.TooLong(PASSWORD_MAX)
        else -> null
    }

    /**
     * Kata sandi saat masuk: hanya wajib diisi. Aturan panjang tidak diterapkan di sini supaya
     * layar masuk tidak membocorkan kebijakan kata sandi dan akun lama tetap bisa masuk.
     */
    fun currentPassword(raw: String): FieldIssue? = if (raw.isEmpty()) FieldIssue.Required else null
}
