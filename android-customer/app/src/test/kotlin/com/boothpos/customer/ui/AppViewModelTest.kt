package com.boothpos.customer.ui

import app.cash.turbine.test
import com.boothpos.customer.core.data.domain.model.CartItem
import com.boothpos.customer.core.data.domain.session.SessionState
import com.boothpos.customer.core.data.testing.FakeCartRepository
import com.boothpos.customer.core.data.testing.FakeSessionRepository
import com.boothpos.customer.core.data.testing.FakeStoreConfigRepository
import com.boothpos.customer.core.data.testing.MainDispatcherRule
import com.boothpos.customer.core.data.testing.TestData
import com.boothpos.customer.core.network.error.ApiError
import com.boothpos.customer.core.network.error.ApiErrorCodes
import com.boothpos.customer.core.network.error.ApiResult
import com.boothpos.customer.feature.auth.domain.OnboardingRepository
import java.math.BigDecimal
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.launch
import kotlinx.coroutines.test.TestScope
import kotlinx.coroutines.test.advanceUntilIdle
import kotlinx.coroutines.test.runTest
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Rule
import org.junit.Test

@OptIn(ExperimentalCoroutinesApi::class)
class AppViewModelTest {

    @get:Rule
    val mainRule = MainDispatcherRule()

    private val storeConfig = FakeStoreConfigRepository()
    private val cart = FakeCartRepository()
    private val session = FakeSessionRepository()

    private class FakeOnboardingRepository(var pending: Boolean = false) : OnboardingRepository {
        var markedSeen = 0
            private set

        override suspend fun isWelcomePending(): Boolean = pending

        override suspend fun markWelcomeSeen() {
            markedSeen++
            pending = false
        }
    }

    private val onboarding = FakeOnboardingRepository()

    /** `uiState` memakai WhileSubscribed, jadi tes berlangganan dulu lalu membaca nilai terakhirnya. */
    private fun TestScope.viewModel(): AppViewModel {
        val viewModel = AppViewModel(storeConfig, cart, session, onboarding)
        backgroundScope.launch { viewModel.uiState.collect { } }
        advanceUntilIdle()
        return viewModel
    }

    @Test
    fun `toko yang aktif berstatus buka dan nama toko tersedia`() = runTest(mainRule.dispatcher) {
        storeConfig.refreshResult = ApiResult.Success(TestData.storeConfig(enabled = true))

        val state = viewModel().uiState.value

        assertEquals(StoreStatus.Open, state.storeStatus)
        assertEquals("Toko Uji", state.storeName)
        assertFalse(state.checkingStore)
    }

    @Test
    fun `fitur yang dimatikan lewat konfigurasi berstatus tutup`() = runTest(mainRule.dispatcher) {
        storeConfig.refreshResult = ApiResult.Success(TestData.storeConfig(enabled = false))

        assertEquals(StoreStatus.Closed, viewModel().uiState.value.storeStatus)
    }

    @Test
    fun `423 atau 503 menutup toko walau konfigurasi tersimpan masih aktif`() = runTest(mainRule.dispatcher) {
        val cached = FakeStoreConfigRepository(TestData.storeConfig(enabled = true)).apply {
            refreshResult = ApiResult.Failure(ApiError.Http(423, ApiErrorCodes.STORE_LOCKED))
        }
        val viewModel = AppViewModel(cached, cart, session, onboarding)
        backgroundScope.launch { viewModel.uiState.collect { } }
        advanceUntilIdle()

        assertEquals(StoreStatus.Closed, viewModel.uiState.value.storeStatus)
    }

    @Test
    fun `galat jaringan bukan toko tutup`() = runTest(mainRule.dispatcher) {
        storeConfig.refreshResult = ApiResult.Failure(ApiError.Network)

        val state = viewModel().uiState.value

        assertEquals(StoreStatus.Unknown, state.storeStatus)
        assertNull(state.storeName)
    }

    @Test
    fun `coba lagi membuka toko setelah fitur diaktifkan kembali`() = runTest(mainRule.dispatcher) {
        storeConfig.refreshResult = ApiResult.Failure(ApiError.Http(503, ApiErrorCodes.CUSTOMER_COMMERCE_DISABLED))
        val viewModel = viewModel()
        assertEquals(StoreStatus.Closed, viewModel.uiState.value.storeStatus)

        storeConfig.refreshResult = ApiResult.Success(TestData.storeConfig(enabled = true))
        viewModel.refreshStore()
        viewModel.refreshStore()
        advanceUntilIdle()

        assertEquals(StoreStatus.Open, viewModel.uiState.value.storeStatus)
        assertEquals("Ketukan ganda hanya mengirim satu permintaan", 2, storeConfig.refreshCalls)
    }

    @Test
    fun `lencana keranjang mengikuti jumlah barang`() = runTest(mainRule.dispatcher) {
        val viewModel = viewModel()

        cart.add(CartItem(31, "slug", "Produk", "Varian", null, BigDecimal("1000.00"), maxQuantity = 5), quantity = 3)
        advanceUntilIdle()

        assertEquals(3, viewModel.uiState.value.cartItemCount)
    }

    @Test
    fun `sesi yang berakhir saat dipakai memancarkan satu kejadian`() = runTest(mainRule.dispatcher) {
        session.set(SessionState.SignedIn(TestData.account(), expiresAt = null))
        val viewModel = viewModel()

        viewModel.events.test {
            session.expire()
            assertEquals(AppEvent.SessionExpired, awaitItem())
            expectNoEvents()
        }
    }

    @Test
    fun `keluar atas permintaan pengguna tidak memancarkan kejadian sesi berakhir`() = runTest(mainRule.dispatcher) {
        session.set(SessionState.SignedIn(TestData.account(), expiresAt = null))
        val viewModel = viewModel()

        viewModel.events.test {
            session.signOut()
            advanceUntilIdle()
            expectNoEvents()
        }
    }

    @Test
    fun `sesi yang sudah kedaluwarsa sejak awal tidak memaksa ke layar masuk`() = runTest(mainRule.dispatcher) {
        session.expire()
        val viewModel = viewModel()

        viewModel.events.test {
            advanceUntilIdle()
            expectNoEvents()
        }
    }

    @Test
    fun `pembukaan pertama tanpa sesi menawarkan sambutan tepat satu kali`() = runTest(mainRule.dispatcher) {
        onboarding.pending = true
        val first = viewModel()

        first.events.test {
            assertEquals(AppEvent.ShowWelcome, awaitItem())
            expectNoEvents()
        }
        assertEquals(1, onboarding.markedSeen)
        assertFalse(onboarding.pending)

        // Pembukaan berikutnya (ViewModel baru): sambutan tidak muncul lagi.
        val second = viewModel()
        second.events.test {
            advanceUntilIdle()
            expectNoEvents()
        }
    }

    @Test
    fun `sambutan menunggu sesi selesai dibaca dan tidak muncul bila pelanggan sudah masuk`() =
        runTest(mainRule.dispatcher) {
            onboarding.pending = true
            session.set(SessionState.Loading)
            val viewModel = viewModel()

            viewModel.events.test {
                expectNoEvents()
                session.set(SessionState.SignedIn(TestData.account(), expiresAt = null))
                advanceUntilIdle()
                expectNoEvents()
            }
            assertTrue("Tetap ditandai sudah tampil", onboarding.markedSeen == 1)
        }
}
