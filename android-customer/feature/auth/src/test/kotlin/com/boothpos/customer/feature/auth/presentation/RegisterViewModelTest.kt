package com.boothpos.customer.feature.auth.presentation

import androidx.lifecycle.SavedStateHandle
import app.cash.turbine.test
import com.boothpos.customer.core.data.domain.validation.FieldIssue
import com.boothpos.customer.core.data.testing.MainDispatcherRule
import com.boothpos.customer.core.network.error.ApiError
import com.boothpos.customer.core.network.error.ApiErrorCodes
import com.boothpos.customer.core.network.error.ApiResult
import kotlinx.coroutines.CompletableDeferred
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.test.advanceUntilIdle
import kotlinx.coroutines.test.runCurrent
import kotlinx.coroutines.test.runTest
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Rule
import org.junit.Test

@OptIn(ExperimentalCoroutinesApi::class)
class RegisterViewModelTest {

    @get:Rule
    val mainRule = MainDispatcherRule()

    private val auth = FakeAuthRepository()
    private val savedState = SavedStateHandle()

    private fun viewModel() = RegisterViewModel(auth, savedState)

    private fun RegisterViewModel.fill(
        name: String = NAME,
        email: String = EMAIL,
        phone: String = "",
        password: String = FAKE_PASSWORD,
    ) {
        onNameChange(name)
        onEmailChange(email)
        onPhoneChange(phone)
        onPasswordChange(password)
    }

    @Test
    fun `pendaftaran yang berhasil memancarkan satu kejadian dan mengirim data formulir`() =
        runTest(mainRule.dispatcher) {
            val viewModel = viewModel()
            viewModel.fill(phone = "0800-0000-0000")

            viewModel.events.test {
                viewModel.submit()
                assertEquals(AuthEvent.Authenticated, awaitItem())
                expectNoEvents()
            }

            val sent = auth.registrations.single()
            assertEquals(NAME, sent.name)
            assertEquals(EMAIL, sent.email)
            assertEquals(FAKE_PASSWORD, sent.password)
            assertEquals("0800-0000-0000", sent.phone)
            assertEquals("", viewModel.uiState.value.password)
            assertFalse(viewModel.uiState.value.submitting)
        }

    @Test
    fun `telepon kosong dikirim sebagai tidak ada`() = runTest(mainRule.dispatcher) {
        val viewModel = viewModel()
        viewModel.fill(phone = "   ")

        viewModel.submit()
        advanceUntilIdle()

        assertNull(auth.registrations.single().phone)
    }

    @Test
    fun `aturan server dicerminkan di kolom tanpa memanggil server`() = runTest(mainRule.dispatcher) {
        val viewModel = viewModel()
        viewModel.fill(name = "  ", email = "bukan-email", phone = "0".repeat(31), password = "1234567")

        viewModel.submit()
        advanceUntilIdle()

        val state = viewModel.uiState.value
        assertEquals(FieldErrorUi.Local(FieldIssue.Required), state.nameError)
        assertEquals(FieldErrorUi.Local(FieldIssue.InvalidFormat), state.emailError)
        assertEquals(FieldErrorUi.Local(FieldIssue.TooLong(30)), state.phoneError)
        assertEquals(FieldErrorUi.Local(FieldIssue.TooShort(8)), state.passwordError)
        assertFalse(state.submitting)
        assertTrue(auth.registrations.isEmpty())
    }

    @Test
    fun `galat kolom dari server dipetakan ke kolomnya`() = runTest(mainRule.dispatcher) {
        auth.result = ApiResult.Failure(
            ApiError.Http(
                422,
                ApiErrorCodes.VALIDATION_FAILED,
                fieldErrors = mapOf(
                    "name" to listOf("Nama terlalu panjang."),
                    "phone" to listOf("Nomor telepon terlalu panjang."),
                    "password" to listOf("Kata sandi minimal 8 karakter."),
                ),
            ),
        )
        val viewModel = viewModel()
        viewModel.fill()

        viewModel.submit()
        advanceUntilIdle()

        val state = viewModel.uiState.value
        assertEquals(FieldErrorUi.Server("Nama terlalu panjang."), state.nameError)
        assertEquals(FieldErrorUi.Server("Nomor telepon terlalu panjang."), state.phoneError)
        assertEquals(FieldErrorUi.Server("Kata sandi minimal 8 karakter."), state.passwordError)
        assertNull(state.emailError)
        assertNull(state.formMessage)
    }

    @Test
    fun `pendaftaran ditolak tampil netral dan tidak pernah menandai kolom e-mail`() =
        runTest(mainRule.dispatcher) {
            // Walau server (keliru) menyertakan galat kolom e-mail, layar tidak boleh menandainya.
            auth.result = ApiResult.Failure(
                ApiError.Http(
                    422,
                    ApiErrorCodes.REGISTRATION_FAILED,
                    detail = "Pendaftaran tidak dapat diproses.",
                    fieldErrors = mapOf("email" to listOf("E-mail sudah terdaftar.")),
                ),
            )
            val viewModel = viewModel()
            viewModel.fill()

            viewModel.events.test {
                viewModel.submit()
                advanceUntilIdle()
                expectNoEvents()
            }

            val state = viewModel.uiState.value
            assertEquals(AuthFormMessage.RegistrationFailed, state.formMessage)
            assertNull(state.emailError)
        }

    @Test
    fun `batas percobaan toko tidak tersedia dan galat jaringan menjadi pesan formulir`() =
        runTest(mainRule.dispatcher) {
            val viewModel = viewModel()
            viewModel.fill()

            auth.result = ApiResult.Failure(ApiError.Http(429, ApiErrorCodes.TOO_MANY_REQUESTS, retryAfterSeconds = 60))
            viewModel.submit()
            advanceUntilIdle()
            assertEquals(AuthFormMessage.RateLimited(60), viewModel.uiState.value.formMessage)

            auth.result = ApiResult.Failure(ApiError.Http(503, ApiErrorCodes.CUSTOMER_COMMERCE_DISABLED))
            viewModel.submit()
            advanceUntilIdle()
            assertEquals(AuthFormMessage.StoreUnavailable, viewModel.uiState.value.formMessage)

            auth.result = ApiResult.Failure(ApiError.Timeout)
            viewModel.submit()
            advanceUntilIdle()
            assertEquals(AuthFormMessage.Other(ApiError.Timeout), viewModel.uiState.value.formMessage)
            // Kata sandi dipertahankan supaya pelanggan dapat mencoba lagi tanpa mengetik ulang.
            assertEquals(FAKE_PASSWORD, viewModel.uiState.value.password)
        }

    @Test
    fun `ketukan ganda saat permintaan berjalan tidak membuat dua pendaftaran`() = runTest(mainRule.dispatcher) {
        val gate = CompletableDeferred<Unit>()
        auth.gate = gate
        val viewModel = viewModel()
        viewModel.fill()

        viewModel.submit()
        runCurrent()
        assertTrue(viewModel.uiState.value.submitting)
        viewModel.submit()
        runCurrent()

        assertEquals(1, auth.registrations.size)
        gate.complete(Unit)
        advanceUntilIdle()
        assertFalse(viewModel.uiState.value.submitting)
    }

    @Test
    fun `nama e-mail dan telepon bertahan di saved state tetapi kata sandi tidak`() = runTest(mainRule.dispatcher) {
        viewModel().fill(phone = "0800-0000-0000")

        assertFalse(
            "Kata sandi tidak boleh ada di saved state",
            savedState.keys().any { savedState.get<Any?>(it) == FAKE_PASSWORD },
        )
        val restored = viewModel().uiState.value
        assertEquals(NAME, restored.name)
        assertEquals(EMAIL, restored.email)
        assertEquals("0800-0000-0000", restored.phone)
        assertEquals("", restored.password)
    }

    @Test
    fun `state tidak membocorkan data pribadi atau kata sandi lewat toString`() = runTest(mainRule.dispatcher) {
        val viewModel = viewModel()
        viewModel.fill()

        val text = viewModel.uiState.value.toString()

        assertFalse(text.contains(FAKE_PASSWORD))
        assertFalse(text.contains(EMAIL))
        assertFalse(text.contains(NAME))
    }

    private companion object {
        const val NAME = "Pelanggan Uji"
        const val EMAIL = "pelanggan.uji@example.test"

        // Nilai uji yang jelas-jelas palsu; bukan kredensial sungguhan.
        const val FAKE_PASSWORD = "sandi-palsu-uji"
    }
}
