package com.boothpos.customer.feature.auth.presentation

import com.boothpos.customer.core.network.error.ApiError
import com.boothpos.customer.core.network.error.ApiErrorCodes
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test

/** Pemetaan galat API ke formulir: satu tempat untuk layar masuk dan daftar. */
class AuthFormFailureTest {

    private val fields = setOf("email", "password")

    @Test
    fun `validasi tanpa kolom yang dikenal jatuh ke pesan formulir`() {
        val error = ApiError.Http(
            422,
            ApiErrorCodes.VALIDATION_FAILED,
            detail = "Data tidak valid.",
            fieldErrors = mapOf("captcha" to listOf("Wajib diisi.")),
        )

        val failure = error.toFormFailure(fields)

        assertTrue(failure.fieldErrors.isEmpty())
        assertEquals(AuthFormMessage.Other(error), failure.message)
    }

    @Test
    fun `pesan kosong dari server tidak dijadikan galat kolom`() {
        val error = ApiError.Http(
            422,
            ApiErrorCodes.VALIDATION_FAILED,
            fieldErrors = mapOf("email" to listOf(" ", "E-mail wajib diisi.")),
        )

        assertEquals(
            mapOf("email" to FieldErrorUi.Server("E-mail wajib diisi.")),
            error.toFormFailure(fields).fieldErrors,
        )
    }

    @Test
    fun `kolom yang pesannya hanya kunci terjemahan mentah jatuh ke pesan formulir, bukan kunci itu`() {
        // ProblemJsonParser membuang `validation.*` dan menyisakan kolom dengan daftar kosong.
        val error = ApiError.Http(
            422,
            ApiErrorCodes.VALIDATION_FAILED,
            detail = "Periksa kembali isian Anda.",
            fieldErrors = mapOf("email" to emptyList()),
        )

        val failure = error.toFormFailure(fields)

        assertTrue(failure.fieldErrors.isEmpty())
        assertEquals(AuthFormMessage.Other(error), failure.message)
    }

    @Test
    fun `sesi yang gagal disimpan menjadi pesan baku`() {
        assertEquals(
            AuthFormMessage.Other(ApiError.SessionStorage),
            ApiError.SessionStorage.toFormFailure(fields).message,
        )
    }

    @Test
    fun `galat tak dikenal dan galat server menjadi pesan baku`() {
        val server = ApiError.Http(500, ApiErrorCodes.SERVER_ERROR)

        assertEquals(AuthFormMessage.Other(ApiError.Unknown), ApiError.Unknown.toFormFailure(fields).message)
        assertEquals(AuthFormMessage.Other(server), server.toFormFailure(fields).message)
    }
}
