package com.boothpos.customer.feature.account.presentation

import com.boothpos.customer.core.data.domain.session.SessionState
import com.boothpos.customer.core.data.testing.FakeSessionRepository
import com.boothpos.customer.core.data.testing.MainDispatcherRule
import com.boothpos.customer.core.data.testing.TestData
import com.boothpos.customer.core.network.error.ApiError
import com.boothpos.customer.core.network.error.ApiErrorCodes
import kotlinx.coroutines.CompletableDeferred
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.launch
import kotlinx.coroutines.test.TestScope
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
class AccountViewModelTest {

    @get:Rule
    val mainRule = MainDispatcherRule()

    private val account = TestData.account()
    private val session = FakeSessionRepository()
    private val profile = FakeProfileRepository(session)

    private fun signIn() = session.set(SessionState.SignedIn(account, expiresAt = null))

    /** `uiState` memakai WhileSubscribed, jadi tes berlangganan dulu lalu membaca nilai terakhirnya. */
    private fun TestScope.viewModel(): AccountViewModel {
        val viewModel = AccountViewModel(session, profile)
        backgroundScope.launch { viewModel.uiState.collect { } }
        runCurrent()
        return viewModel
    }

    private fun AccountViewModel.profileState() = uiState.value as AccountUiState.Profile

    @Test
    fun `tanpa sesi tampil ajakan masuk dan tidak ada permintaan profil`() = runTest(mainRule.dispatcher) {
        val viewModel = viewModel()
        advanceUntilIdle()

        assertEquals(AccountUiState.SignedOut(sessionExpired = false), viewModel.uiState.value)
        assertEquals(0, profile.refreshCalls)
    }

    @Test
    fun `sesi tersimpan yang belum selesai dibaca tampil sebagai memuat`() = runTest(mainRule.dispatcher) {
        session.set(SessionState.Loading)
        val viewModel = viewModel()
        assertEquals(AccountUiState.Loading, viewModel.uiState.value)

        signIn()
        advanceUntilIdle()

        assertEquals(account.name, viewModel.profileState().name)
        assertEquals("Profil disegarkan setelah sesi terbaca", 1, profile.refreshCalls)
    }

    @Test
    fun `profil dari sesi langsung tampil lalu disegarkan sekali dari server`() = runTest(mainRule.dispatcher) {
        signIn()
        profile.serverAccount = account.copy(name = "Nama Dari Server", phone = null)
        val gate = CompletableDeferred<Unit>().also { profile.gate = it }
        val viewModel = viewModel()

        // Data sesi tampil sementara permintaan masih berjalan.
        assertEquals(account.name, viewModel.profileState().name)
        assertEquals(account.email, viewModel.profileState().email)
        assertTrue(viewModel.profileState().refreshing)

        gate.complete(Unit)
        advanceUntilIdle()

        val state = viewModel.profileState()
        assertEquals("Nama Dari Server", state.name)
        assertNull(state.phone)
        assertFalse(state.refreshing)
        assertNull(state.refreshError)
        assertEquals(1, profile.refreshCalls)
    }

    @Test
    fun `gagal menyegarkan tetap menampilkan profil sesi dengan galat dan coba lagi`() = runTest(mainRule.dispatcher) {
        signIn()
        profile.failure = ApiError.Network
        val viewModel = viewModel()
        advanceUntilIdle()

        assertEquals(ApiError.Network, viewModel.profileState().refreshError)
        assertEquals(account.name, viewModel.profileState().name)

        profile.failure = null
        viewModel.refresh()
        advanceUntilIdle()

        assertNull(viewModel.profileState().refreshError)
        assertEquals(2, profile.refreshCalls)
    }

    @Test
    fun `coba lagi saat permintaan berjalan tidak mengirim permintaan kedua`() = runTest(mainRule.dispatcher) {
        signIn()
        val gate = CompletableDeferred<Unit>().also { profile.gate = it }
        val viewModel = viewModel()

        viewModel.refresh()
        viewModel.refresh()
        runCurrent()

        assertEquals(1, profile.refreshCalls)
        gate.complete(Unit)
        advanceUntilIdle()
        assertFalse(viewModel.profileState().refreshing)
    }

    @Test
    fun `sesi yang ditolak server saat menyegarkan beralih ke ajakan masuk dengan pesan sesi berakhir`() =
        runTest(mainRule.dispatcher) {
            signIn()
            profile.failure = ApiError.Http(401, ApiErrorCodes.UNAUTHENTICATED)
            val gate = CompletableDeferred<Unit>().also { profile.gate = it }
            val viewModel = viewModel()

            // Lapisan sesi menghapus sesi begitu 401 diterima (ditiru di sini), lalu panggilan selesai.
            session.expire()
            gate.complete(Unit)
            advanceUntilIdle()

            assertEquals(AccountUiState.SignedOut(sessionExpired = true), viewModel.uiState.value)

            // Masuk lagi: galat 401 lama tidak ikut tampil sebagai "gagal menyegarkan".
            signIn()
            runCurrent()
            assertNull(viewModel.profileState().refreshError)
        }

    @Test
    fun `masuk setelah layar dibuka tidak memicu penyegaran karena akun baru saja diterima`() =
        runTest(mainRule.dispatcher) {
            val viewModel = viewModel()
            advanceUntilIdle()

            signIn()
            advanceUntilIdle()

            assertEquals(account.name, viewModel.profileState().name)
            assertEquals(0, profile.refreshCalls)
        }

    @Test
    fun `keluar meminta konfirmasi dulu dan dapat dibatalkan`() = runTest(mainRule.dispatcher) {
        signIn()
        val viewModel = viewModel()
        advanceUntilIdle()

        viewModel.requestSignOut()
        runCurrent()
        assertTrue(viewModel.profileState().confirmingSignOut)

        viewModel.dismissSignOut()
        runCurrent()

        assertFalse(viewModel.profileState().confirmingSignOut)
        assertEquals(0, session.signOutCalls)
    }

    @Test
    fun `konfirmasi keluar mengakhiri sesi tepat satu kali walau ditekan dua kali`() = runTest(mainRule.dispatcher) {
        signIn()
        val viewModel = viewModel()
        advanceUntilIdle()
        viewModel.requestSignOut()

        viewModel.confirmSignOut()
        viewModel.confirmSignOut()
        advanceUntilIdle()

        assertEquals(1, session.signOutCalls)
        // Keluar atas permintaan sendiri: ajakan masuk biasa, bukan "sesi berakhir".
        assertEquals(AccountUiState.SignedOut(sessionExpired = false), viewModel.uiState.value)
    }

    @Test
    fun `state profil tidak membocorkan data pribadi lewat toString`() = runTest(mainRule.dispatcher) {
        signIn()
        val viewModel = viewModel()
        advanceUntilIdle()

        val text = viewModel.uiState.value.toString()

        assertFalse(text.contains(account.email))
        assertFalse(text.contains(account.name))
    }
}
