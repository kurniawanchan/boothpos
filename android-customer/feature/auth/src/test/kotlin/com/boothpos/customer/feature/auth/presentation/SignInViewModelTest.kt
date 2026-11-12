package com.boothpos.customer.feature.auth.presentation

import androidx.lifecycle.SavedStateHandle
import app.cash.turbine.test
import com.boothpos.customer.core.data.domain.session.SessionState
import com.boothpos.customer.core.data.domain.validation.FieldIssue
import com.boothpos.customer.core.data.testing.FakeSessionRepository
import com.boothpos.customer.core.data.testing.MainDispatcherRule
import com.boothpos.customer.core.data.testing.TestData
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
class SignInViewModelTest {

    @get:Rule
    val mainRule = MainDispatcherRule()

    private val auth = FakeAuthRepository()
    private val session = FakeSessionRepository()
    private val savedState = SavedStateHandle()

    private fun viewModel() = SignInViewModel(auth, session, savedState)

    private fun SignInViewModel.fill(email: String = EMAIL, password: String = FAKE_PASSWORD) {
        onEmailChange(email)
        onPasswordChange(password)
    }

    @Test
    fun `masuk yang berhasil memancarkan satu kejadian dan membuang kata sandi dari state`() =
        runTest(mainRule.dispatcher) {
            val viewModel = viewModel()
            viewModel.fill()

            viewModel.events.test {
                viewModel.submit()
                assertEquals(AuthEvent.Authenticated, awaitItem())
                expectNoEvents()
            }

            assertEquals(listOf(EMAIL to FAKE_PASSWORD), auth.signIns)
            val state = viewModel.uiState.value
            assertFalse(state.submitting)
            assertEquals("", state.password)
            assertNull(state.formMessage)
        }

    @Test
    fun `kolom kosong atau e-mail salah bentuk ditandai di kolomnya tanpa memanggil server`() =
        runTest(mainRule.dispatcher) {
            val viewModel = viewModel()

            viewModel.submit()
            advanceUntilIdle()
            assertEquals(FieldErrorUi.Local(FieldIssue.Required), viewModel.uiState.value.emailError)
            assertEquals(FieldErrorUi.Local(FieldIssue.Required), viewModel.uiState.value.passwordError)

            viewModel.fill(email = "bukan-email")
            viewModel.submit()
            advanceUntilIdle()

            assertEquals(FieldErrorUi.Local(FieldIssue.InvalidFormat), viewModel.uiState.value.emailError)
            assertNull(viewModel.uiState.value.passwordError)
            assertTrue(auth.signIns.isEmpty())
        }

    @Test
    fun `mengetik lagi menghapus galat kolom dan pesan formulir`() = runTest(mainRule.dispatcher) {
        auth.result = ApiResult.Failure(ApiError.Http(401, ApiErrorCodes.INVALID_CREDENTIALS))
        val viewModel = viewModel()
        viewModel.fill()
        viewModel.submit()
        advanceUntilIdle()
        assertEquals(AuthFormMessage.InvalidCredentials, viewModel.uiState.value.formMessage)

        viewModel.onPasswordChange("sandi-palsu-lain")

        assertNull(viewModel.uiState.value.formMessage)
    }

    @Test
    fun `kredensial salah menampilkan pesan generik dan kata sandi tetap dapat diperbaiki`() =
        runTest(mainRule.dispatcher) {
            auth.result = ApiResult.Failure(ApiError.Http(401, ApiErrorCodes.INVALID_CREDENTIALS, detail = "x"))
            val viewModel = viewModel()
            viewModel.fill()

            viewModel.events.test {
                viewModel.submit()
                advanceUntilIdle()
                expectNoEvents()
            }

            val state = viewModel.uiState.value
            assertEquals(AuthFormMessage.InvalidCredentials, state.formMessage)
            // Tidak ada kolom yang ditandai: pesan tidak boleh mengisyaratkan mana yang salah.
            assertNull(state.emailError)
            assertNull(state.passwordError)
            assertEquals(FAKE_PASSWORD, state.password)
            assertFalse(state.submitting)
        }

    @Test
    fun `batas percobaan membawa petunjuk tunggu dari Retry-After`() = runTest(mainRule.dispatcher) {
        auth.result = ApiResult.Failure(
            ApiError.Http(429, ApiErrorCodes.TOO_MANY_REQUESTS, retryAfterSeconds = 42),
        )
        val viewModel = viewModel()
        viewModel.fill()

        viewModel.submit()
        advanceUntilIdle()

        assertEquals(AuthFormMessage.RateLimited(42), viewModel.uiState.value.formMessage)
    }

    @Test
    fun `galat jaringan dan toko tidak tersedia menjadi pesan formulir`() = runTest(mainRule.dispatcher) {
        val viewModel = viewModel()
        viewModel.fill()

        auth.result = ApiResult.Failure(ApiError.Network)
        viewModel.submit()
        advanceUntilIdle()
        assertEquals(AuthFormMessage.Other(ApiError.Network), viewModel.uiState.value.formMessage)

        auth.result = ApiResult.Failure(ApiError.Http(423, ApiErrorCodes.STORE_LOCKED))
        viewModel.submit()
        advanceUntilIdle()
        assertEquals(AuthFormMessage.StoreUnavailable, viewModel.uiState.value.formMessage)
        assertEquals("Coba lagi mengirim permintaan baru", 2, auth.signIns.size)
    }

    @Test
    fun `sesi yang gagal disimpan di perangkat menjadi pesan formulir dan formulir tetap dapat dikirim lagi`() =
        runTest(mainRule.dispatcher) {
            val viewModel = viewModel()
            viewModel.fill()

            auth.result = ApiResult.Failure(ApiError.SessionStorage)
            viewModel.submit()
            advanceUntilIdle()

            val state = viewModel.uiState.value
            assertEquals(AuthFormMessage.Other(ApiError.SessionStorage), state.formMessage)
            assertFalse(state.submitting)
            assertEquals("Kata sandi tetap ada supaya pelanggan cukup menekan lagi", FAKE_PASSWORD, state.password)
            assertNull(state.emailError)

            auth.result = ApiResult.Success(TestData.account())
            viewModel.submit()
            advanceUntilIdle()
            assertEquals(2, auth.signIns.size)
        }

    @Test
    fun `galat validasi server dipetakan ke kolomnya`() = runTest(mainRule.dispatcher) {
        auth.result = ApiResult.Failure(
            ApiError.Http(
                422,
                ApiErrorCodes.VALIDATION_FAILED,
                fieldErrors = mapOf("email" to listOf("Format e-mail tidak valid."), "lain" to listOf("abaikan")),
            ),
        )
        val viewModel = viewModel()
        viewModel.fill()

        viewModel.submit()
        advanceUntilIdle()

        val state = viewModel.uiState.value
        assertEquals(FieldErrorUi.Server("Format e-mail tidak valid."), state.emailError)
        assertNull(state.passwordError)
        assertNull(state.formMessage)
    }

    @Test
    fun `ketukan ganda saat permintaan berjalan hanya mengirim satu permintaan`() = runTest(mainRule.dispatcher) {
        val gate = CompletableDeferred<Unit>()
        auth.gate = gate
        val viewModel = viewModel()
        viewModel.fill()

        viewModel.submit()
        runCurrent()
        assertTrue(viewModel.uiState.value.submitting)
        viewModel.submit()
        viewModel.submit()
        runCurrent()

        assertEquals(1, auth.signIns.size)
        gate.complete(Unit)
        advanceUntilIdle()
        assertFalse(viewModel.uiState.value.submitting)
    }

    @Test
    fun `sesi yang berakhir ditampilkan sebagai pemberitahuan dan hilang setelah masuk`() =
        runTest(mainRule.dispatcher) {
            session.set(SessionState.SignedIn(TestData.account(), expiresAt = null))
            val viewModel = viewModel()
            runCurrent()
            assertFalse(viewModel.uiState.value.sessionExpired)

            session.expire()
            runCurrent()
            assertTrue(viewModel.uiState.value.sessionExpired)

            session.set(SessionState.SignedIn(TestData.account(), expiresAt = null))
            runCurrent()
            assertFalse(viewModel.uiState.value.sessionExpired)
        }

    @Test
    fun `keluar atas permintaan pengguna bukan sesi berakhir`() = runTest(mainRule.dispatcher) {
        session.set(SessionState.SignedIn(TestData.account(), expiresAt = null))
        val viewModel = viewModel()

        session.signOut()
        runCurrent()

        assertFalse(viewModel.uiState.value.sessionExpired)
    }

    @Test
    fun `hanya e-mail yang masuk saved state dan kata sandi tidak pernah`() = runTest(mainRule.dispatcher) {
        val first = viewModel()
        first.fill()

        assertFalse(
            "Kata sandi tidak boleh ada di saved state",
            savedState.keys().any { savedState.get<Any?>(it) == FAKE_PASSWORD },
        )
        // Proses dipulihkan sistem: ViewModel baru dengan saved state yang sama.
        val restored = viewModel()
        assertEquals(EMAIL, restored.uiState.value.email)
        assertEquals("", restored.uiState.value.password)
    }

    @Test
    fun `state tidak membocorkan e-mail atau kata sandi lewat toString`() = runTest(mainRule.dispatcher) {
        val viewModel = viewModel()
        viewModel.fill()

        val text = viewModel.uiState.value.toString()

        assertFalse(text.contains(FAKE_PASSWORD))
        assertFalse(text.contains(EMAIL))
    }

    private companion object {
        const val EMAIL = "pelanggan.uji@example.test"

        // Nilai uji yang jelas-jelas palsu; bukan kredensial sungguhan.
        const val FAKE_PASSWORD = "sandi-palsu-uji"
    }
}
