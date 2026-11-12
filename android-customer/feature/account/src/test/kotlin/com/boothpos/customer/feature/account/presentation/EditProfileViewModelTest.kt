package com.boothpos.customer.feature.account.presentation

import androidx.lifecycle.SavedStateHandle
import app.cash.turbine.test
import com.boothpos.customer.core.data.domain.session.SessionState
import com.boothpos.customer.core.data.domain.validation.FieldIssue
import com.boothpos.customer.core.data.testing.FakeSessionRepository
import com.boothpos.customer.core.data.testing.MainDispatcherRule
import com.boothpos.customer.core.data.testing.TestData
import com.boothpos.customer.core.network.error.ApiError
import com.boothpos.customer.core.network.error.ApiErrorCodes
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
class EditProfileViewModelTest {

    @get:Rule
    val mainRule = MainDispatcherRule()

    private val account = TestData.account(name = "Pelanggan Uji", phone = "0800-0000-0000")
    private val session = FakeSessionRepository(SessionState.SignedIn(account, expiresAt = null))
    private val profile = FakeProfileRepository(session)
    private val savedState = SavedStateHandle()

    private fun viewModel() = EditProfileViewModel(session, profile, savedState)

    private fun EditProfileViewModel.form() = uiState.value as EditProfileUiState.Form

    @Test
    fun `formulir diisi dari akun di sesi dan e-mail hanya ditampilkan`() = runTest(mainRule.dispatcher) {
        val viewModel = viewModel()
        runCurrent()

        val form = viewModel.form()
        assertEquals("Pelanggan Uji", form.name)
        assertEquals("0800-0000-0000", form.phone)
        assertEquals(account.email, form.email)
    }

    @Test
    fun `menunggu sesi selesai dibaca sebelum menampilkan formulir`() = runTest(mainRule.dispatcher) {
        session.set(SessionState.Loading)
        val viewModel = viewModel()
        runCurrent()
        assertEquals(EditProfileUiState.Loading, viewModel.uiState.value)

        session.set(SessionState.SignedIn(account, expiresAt = null))
        runCurrent()

        assertEquals("Pelanggan Uji", viewModel.form().name)
    }

    @Test
    fun `dibuka tanpa sesi menutup layar`() = runTest(mainRule.dispatcher) {
        session.expire()
        val viewModel = viewModel()

        viewModel.events.test {
            assertEquals(EditProfileEvent.SessionMissing, awaitItem())
            expectNoEvents()
        }
        assertEquals(EditProfileUiState.Loading, viewModel.uiState.value)
    }

    @Test
    fun `simpan yang berhasil mengirim nilai formulir dan memancarkan satu kejadian`() =
        runTest(mainRule.dispatcher) {
            val viewModel = viewModel()
            runCurrent()
            viewModel.onNameChange("Nama Baru")
            viewModel.onPhoneChange("")

            viewModel.events.test {
                viewModel.submit()
                assertEquals(EditProfileEvent.Saved, awaitItem())
                expectNoEvents()
            }

            // Telepon yang dikosongkan dikirim sebagai "tidak ada", bukan string kosong.
            assertEquals(listOf<Pair<String, String?>>("Nama Baru" to null), profile.updates)
            assertFalse(viewModel.form().submitting)
            assertEquals("Nama Baru", (session.state.value as SessionState.SignedIn).account.name)
        }

    @Test
    fun `nama kosong dan telepon terlalu panjang ditandai di kolomnya tanpa memanggil server`() =
        runTest(mainRule.dispatcher) {
            val viewModel = viewModel()
            runCurrent()
            viewModel.onNameChange("   ")
            viewModel.onPhoneChange("0".repeat(31))

            viewModel.submit()
            advanceUntilIdle()

            assertEquals(ProfileFieldError.Local(FieldIssue.Required), viewModel.form().nameError)
            assertEquals(ProfileFieldError.Local(FieldIssue.TooLong(30)), viewModel.form().phoneError)
            assertTrue(profile.updates.isEmpty())

            viewModel.onNameChange("Pelanggan Uji")
            assertNull("Mengetik lagi menghapus galat kolom itu", viewModel.form().nameError)
        }

    @Test
    fun `galat kolom dari server dipetakan ke kolomnya`() = runTest(mainRule.dispatcher) {
        profile.failure = ApiError.Http(
            422,
            ApiErrorCodes.VALIDATION_FAILED,
            detail = "Data tidak valid.",
            fieldErrors = mapOf("phone" to listOf("Nomor telepon maksimal 30 karakter.")),
        )
        val viewModel = viewModel()
        runCurrent()

        viewModel.submit()
        advanceUntilIdle()

        val form = viewModel.form()
        assertEquals(ProfileFieldError.Server("Nomor telepon maksimal 30 karakter."), form.phoneError)
        assertNull(form.nameError)
        assertNull(form.formError)
        assertFalse(form.submitting)
    }

    @Test
    fun `galat jaringan batas percobaan dan sesi berakhir menjadi galat formulir`() = runTest(mainRule.dispatcher) {
        val viewModel = viewModel()
        runCurrent()

        listOf(
            ApiError.Network,
            ApiError.Http(429, ApiErrorCodes.TOO_MANY_REQUESTS, retryAfterSeconds = 30),
            ApiError.Http(401, ApiErrorCodes.UNAUTHENTICATED),
            // Validasi untuk kolom yang tidak ada di formulir ini tetap harus terlihat.
            ApiError.Http(422, ApiErrorCodes.VALIDATION_FAILED, fieldErrors = mapOf("email" to listOf("x"))),
        ).forEach { error ->
            profile.failure = error
            viewModel.submit()
            advanceUntilIdle()

            assertEquals(error, viewModel.form().formError)
            assertFalse(viewModel.form().submitting)
        }
        assertEquals(4, profile.updates.size)
    }

    @Test
    fun `ketukan ganda saat permintaan berjalan hanya mengirim satu permintaan`() = runTest(mainRule.dispatcher) {
        val gate = CompletableDeferred<Unit>().also { profile.gate = it }
        val viewModel = viewModel()
        runCurrent()

        viewModel.submit()
        runCurrent()
        assertTrue(viewModel.form().submitting)
        viewModel.submit()
        runCurrent()

        assertEquals(1, profile.updates.size)
        gate.complete(Unit)
        advanceUntilIdle()
        assertFalse(viewModel.form().submitting)
    }

    @Test
    fun `suntingan yang belum disimpan bertahan saat proses dipulihkan`() = runTest(mainRule.dispatcher) {
        val first = viewModel()
        runCurrent()
        first.onNameChange("Nama Sedang Diketik")
        first.onPhoneChange("")

        val restored = viewModel()
        runCurrent()

        assertEquals("Nama Sedang Diketik", restored.form().name)
        assertEquals("", restored.form().phone)
    }

    @Test
    fun `state formulir tidak membocorkan data pribadi lewat toString`() = runTest(mainRule.dispatcher) {
        val viewModel = viewModel()
        runCurrent()

        val text = viewModel.uiState.value.toString()

        assertFalse(text.contains(account.email))
        assertFalse(text.contains(account.name))
    }
}
