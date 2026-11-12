package com.boothpos.customer.feature.auth.presentation

import androidx.compose.runtime.Composable
import androidx.compose.ui.res.pluralStringResource
import androidx.compose.ui.res.stringResource
import com.boothpos.customer.core.data.domain.validation.FieldIssue
import com.boothpos.customer.core.network.error.ApiError
import com.boothpos.customer.core.network.error.ApiErrorCodes
import com.boothpos.customer.core.network.error.isStoreUnavailable
import com.boothpos.customer.core.ui.component.apiErrorMessage
import com.boothpos.customer.feature.auth.R

/** Galat satu kolom: dari pemeriksaan di perangkat, atau pesan server (422, sudah dilokalkan). */
sealed interface FieldErrorUi {
    data class Local(val issue: FieldIssue) : FieldErrorUi

    data class Server(val message: String) : FieldErrorUi
}

/** Pesan tingkat formulir. Layar memilih teksnya; ViewModel tidak menyentuh resource Android. */
sealed interface AuthFormMessage {
    /** 401 `invalid_credentials`: sengaja generik, sama untuk e-mail tak dikenal dan sandi salah. */
    data object InvalidCredentials : AuthFormMessage

    /** 422 `registration_failed`: netral, TIDAK menyatakan bahwa e-mail sudah terdaftar. */
    data object RegistrationFailed : AuthFormMessage

    /** 429 `too_many_requests`; [retryAfterSeconds] dari header `Retry-After` bila ada. */
    data class RateLimited(val retryAfterSeconds: Long?) : AuthFormMessage

    /** 503 `customer_commerce_disabled` atau 423: bukan kesalahan pengguna. */
    data object StoreUnavailable : AuthFormMessage

    /** Lainnya (jaringan, batas waktu, galat server): pesan baku bersama. */
    data class Other(val error: ApiError) : AuthFormMessage
}

/** Kejadian sekali-jalan dari ViewModel auth. */
sealed interface AuthEvent {
    /** Sesi sudah dimulai; keluar dari alur auth. */
    data object Authenticated : AuthEvent
}

/** Hasil pemetaan satu galat API ke formulir: galat per kolom ATAU satu pesan formulir. */
internal data class FormFailure(
    val fieldErrors: Map<String, FieldErrorUi> = emptyMap(),
    val message: AuthFormMessage? = null,
)

/**
 * Satu-satunya pemetaan galat API untuk formulir masuk dan daftar. [fields] adalah nama kolom
 * kawat yang dimiliki formulir; galat untuk kolom lain jatuh ke pesan formulir.
 */
internal fun ApiError.toFormFailure(fields: Set<String>): FormFailure {
    if (this !is ApiError.Http) return FormFailure(message = AuthFormMessage.Other(this))
    return when {
        code == ApiErrorCodes.INVALID_CREDENTIALS -> FormFailure(message = AuthFormMessage.InvalidCredentials)
        // Galat kolom yang mungkin ikut dikirim sengaja diabaikan: menandai kolom e-mail akan
        // memberi tahu siapa pun bahwa alamat itu sudah punya akun.
        code == ApiErrorCodes.REGISTRATION_FAILED -> FormFailure(message = AuthFormMessage.RegistrationFailed)
        code == ApiErrorCodes.TOO_MANY_REQUESTS -> FormFailure(message = AuthFormMessage.RateLimited(retryAfterSeconds))
        isStoreUnavailable -> FormFailure(message = AuthFormMessage.StoreUnavailable)
        code == ApiErrorCodes.VALIDATION_FAILED -> {
            val known = fieldErrors
                .filterKeys { it in fields }
                .mapNotNull { (field, messages) ->
                    messages.firstOrNull { it.isNotBlank() }?.let { field to FieldErrorUi.Server(it) }
                }
                .toMap()
            if (known.isEmpty()) FormFailure(message = AuthFormMessage.Other(this)) else FormFailure(fieldErrors = known)
        }
        else -> FormFailure(message = AuthFormMessage.Other(this))
    }
}

@Composable
internal fun fieldErrorText(error: FieldErrorUi?, fieldLabel: String): String? = when (error) {
    null -> null
    is FieldErrorUi.Server -> error.message
    is FieldErrorUi.Local -> when (val issue = error.issue) {
        FieldIssue.Required -> stringResource(R.string.auth_error_required, fieldLabel)
        FieldIssue.InvalidFormat -> stringResource(R.string.auth_error_email_format)
        is FieldIssue.TooShort ->
            pluralStringResource(R.plurals.auth_error_too_short, issue.min, fieldLabel, issue.min)
        is FieldIssue.TooLong ->
            pluralStringResource(R.plurals.auth_error_too_long, issue.max, fieldLabel, issue.max)
    }
}

@Composable
internal fun formMessageText(message: AuthFormMessage): String = when (message) {
    AuthFormMessage.InvalidCredentials -> stringResource(R.string.auth_error_invalid_credentials)
    AuthFormMessage.RegistrationFailed -> stringResource(R.string.auth_error_registration_failed)
    AuthFormMessage.StoreUnavailable -> stringResource(R.string.auth_error_store_unavailable)
    is AuthFormMessage.RateLimited -> rateLimitText(message.retryAfterSeconds)
    is AuthFormMessage.Other -> apiErrorMessage(message.error)
}

/** Petunjuk tunggu statis dari `Retry-After` (bukan hitung mundur): detik, atau menit dibulatkan ke atas. */
@Composable
private fun rateLimitText(retryAfterSeconds: Long?): String = when {
    retryAfterSeconds == null || retryAfterSeconds <= 0 -> stringResource(R.string.auth_error_rate_limited)
    retryAfterSeconds < SECONDS_PER_MINUTE -> {
        val seconds = retryAfterSeconds.toInt()
        pluralStringResource(R.plurals.auth_error_rate_limited_seconds, seconds, seconds)
    }
    else -> {
        val minutes = ((retryAfterSeconds + SECONDS_PER_MINUTE - 1) / SECONDS_PER_MINUTE)
            .coerceAtMost(Int.MAX_VALUE.toLong()).toInt()
        pluralStringResource(R.plurals.auth_error_rate_limited_minutes, minutes, minutes)
    }
}

private const val SECONDS_PER_MINUTE = 60L
