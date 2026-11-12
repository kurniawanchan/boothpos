package com.boothpos.customer.feature.orders.presentation

import com.boothpos.customer.core.data.domain.session.SessionState
import com.boothpos.customer.core.data.testing.FakeSessionRepository
import com.boothpos.customer.core.data.testing.MainDispatcherRule
import com.boothpos.customer.core.data.testing.TestData
import com.boothpos.customer.core.network.error.ApiError
import com.boothpos.customer.core.network.error.ApiErrorCodes
import com.boothpos.customer.core.network.error.ApiResult
import com.boothpos.customer.core.network.error.isStoreUnavailable
import com.boothpos.customer.feature.orders.domain.OrderStatus
import com.boothpos.customer.feature.orders.presentation.OrdersTestData.number
import com.boothpos.customer.feature.orders.presentation.OrdersTestData.page
import com.boothpos.customer.feature.orders.presentation.OrdersTestData.summaries
import com.boothpos.customer.feature.orders.presentation.OrdersTestData.summary
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.delay
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
class OrdersViewModelTest {

    @get:Rule
    val mainRule = MainDispatcherRule()

    private val repository = FakeOrdersRepository()
    private val session = FakeSessionRepository(SessionState.SignedIn(TestData.account(), expiresAt = null))

    private fun viewModel() = OrdersViewModel(session, repository)

    private fun OrdersViewModel.shown() = uiState.value as OrdersUiState.Orders
    private fun OrdersViewModel.numbers() = shown().items.map { it.orderNumber }

    /** Halaman ke-n berisi pesanan (n-1)*20+1 .. n*20, terbaru (nomor kecil) lebih dulu. */
    private fun servePages(lastPage: Int = 3) {
        repository.listHandler = { page -> ApiResult.Success(page(summaries((page - 1) * 20 + 1..page * 20), page, lastPage)) }
    }

    private val unauthenticated = ApiError.Http(401, ApiErrorCodes.UNAUTHENTICATED)
    private val serverError = ApiError.Http(500, ApiErrorCodes.SERVER_ERROR)

    // --- sesi ---

    @Test
    fun `belum masuk menampilkan ajakan masuk tanpa memanggil server`() = runTest(mainRule.dispatcher) {
        session.set(SessionState.SignedOut())

        val viewModel = viewModel()
        advanceUntilIdle()

        assertEquals(OrdersUiState.SignedOut(sessionExpired = false), viewModel.uiState.value)
        assertTrue(repository.listCalls.isEmpty())
    }

    @Test
    fun `sesi yang belum terbaca tetap memuat lalu daftar dimuat begitu akun masuk`() = runTest(mainRule.dispatcher) {
        servePages(lastPage = 1)
        session.set(SessionState.Loading)

        val viewModel = viewModel()
        advanceUntilIdle()
        assertEquals(OrdersUiState.Loading, viewModel.uiState.value)
        assertTrue(repository.listCalls.isEmpty())

        session.set(SessionState.SignedIn(TestData.account(), expiresAt = null))
        advanceUntilIdle()

        assertEquals(summaries(1..20), viewModel.shown().items)
    }

    @Test
    fun `keluar mengosongkan daftar dan akun lain memuat daftarnya sendiri`() = runTest(mainRule.dispatcher) {
        servePages(lastPage = 1)
        val viewModel = viewModel()
        advanceUntilIdle()
        assertEquals(20, viewModel.shown().items.size)

        session.signOut()
        advanceUntilIdle()
        assertEquals(OrdersUiState.SignedOut(sessionExpired = false), viewModel.uiState.value)

        repository.listHandler = { ApiResult.Success(page(listOf(summary(99)))) }
        session.set(SessionState.SignedIn(TestData.account(id = 2), expiresAt = null))
        advanceUntilIdle()

        assertEquals(listOf(number(99)), viewModel.numbers())
        assertEquals(listOf(1, 1), repository.listCalls)
    }

    @Test
    fun `perubahan profil akun yang sama tidak memuat ulang daftar`() = runTest(mainRule.dispatcher) {
        servePages(lastPage = 1)
        val viewModel = viewModel()
        advanceUntilIdle()

        session.updateAccount(TestData.account(name = "Nama Baru Uji"))
        advanceUntilIdle()

        assertEquals(listOf(1), repository.listCalls)
        assertEquals(20, viewModel.shown().items.size)
    }

    @Test
    fun `sesi berakhir saat daftar dimuat membuang jawaban yang terlambat`() = runTest(mainRule.dispatcher) {
        repository.listHandler = { page ->
            delay(1_000)
            ApiResult.Success(page(summaries(1..20), page))
        }
        val viewModel = viewModel()
        runCurrent()
        assertEquals(OrdersUiState.Loading, viewModel.uiState.value)

        session.expire()
        advanceUntilIdle()

        // Pesanan akun yang sesinya sudah berakhir tidak pernah tampil.
        assertEquals(OrdersUiState.SignedOut(sessionExpired = true), viewModel.uiState.value)
    }

    // --- muat awal ---

    @Test
    fun `muat awal menampilkan memuat lalu halaman pertama terbaru lebih dulu`() = runTest(mainRule.dispatcher) {
        servePages()

        val viewModel = viewModel()
        assertEquals(OrdersUiState.Loading, viewModel.uiState.value)
        advanceUntilIdle()

        assertEquals(summaries(1..20), viewModel.shown().items)
        assertTrue(viewModel.shown().canLoadMore)
        assertFalse(viewModel.shown().refreshing)
        assertNull(viewModel.shown().staleReason)
        // Hanya halaman pertama: riwayat tidak dimuat seluruhnya di muka.
        assertEquals(listOf(1), repository.listCalls)
    }

    @Test
    fun `akun tanpa pesanan menampilkan keadaan kosong`() = runTest(mainRule.dispatcher) {
        val viewModel = viewModel()
        advanceUntilIdle()

        assertEquals(OrdersUiState.Empty, viewModel.uiState.value)
    }

    @Test
    fun `status yang belum dikenal tetap tampil di daftar`() = runTest(mainRule.dispatcher) {
        repository.listHandler = { ApiResult.Success(page(listOf(summary(1, status = OrderStatus.Unknown, method = null)))) }

        val viewModel = viewModel()
        advanceUntilIdle()

        assertEquals(OrderStatus.Unknown, viewModel.shown().items.single().status)
    }

    @Test
    fun `halaman pertama gagal menampilkan galat dan coba lagi memuat daftar`() = runTest(mainRule.dispatcher) {
        repository.listHandler = { ApiResult.Failure(ApiError.Network) }
        val viewModel = viewModel()
        advanceUntilIdle()
        assertEquals(OrdersUiState.Error(ApiError.Network), viewModel.uiState.value)

        servePages(lastPage = 1)
        viewModel.refresh()
        assertEquals(OrdersUiState.Error(ApiError.Network, retrying = true), viewModel.uiState.value)
        // Ketukan ganda pada "Coba lagi" tidak mengirim dua kali.
        viewModel.refresh()
        advanceUntilIdle()

        assertEquals(20, viewModel.shown().items.size)
        assertEquals(listOf(1, 1), repository.listCalls)
    }

    @Test
    fun `instalasi terkunci tampil sebagai toko tidak tersedia`() = runTest(mainRule.dispatcher) {
        repository.listHandler = { ApiResult.Failure(ApiError.Http(423, ApiErrorCodes.STORE_LOCKED)) }

        val viewModel = viewModel()
        advanceUntilIdle()

        assertTrue((viewModel.uiState.value as OrdersUiState.Error).error.isStoreUnavailable)
    }

    @Test
    fun `401 pada halaman pertama mengosongkan layar dan meminta masuk lagi`() = runTest(mainRule.dispatcher) {
        repository.listHandler = { ApiResult.Failure(unauthenticated) }

        val viewModel = viewModel()
        advanceUntilIdle()

        // Tidak ditampilkan sebagai galat: alur sesi berakhir milik :app yang membuka layar masuk.
        assertEquals(OrdersUiState.SignedOut(sessionExpired = true), viewModel.uiState.value)
    }

    @Test
    fun `masuk lagi setelah 401 memuat ulang daftar walau akunnya sama`() = runTest(mainRule.dispatcher) {
        repository.listHandler = { ApiResult.Failure(unauthenticated) }
        val viewModel = viewModel()
        advanceUntilIdle()

        // Lapisan sesi menghapus token, lalu pelanggan masuk lagi dengan akun yang sama.
        session.expire()
        advanceUntilIdle()
        servePages(lastPage = 1)
        session.set(SessionState.SignedIn(TestData.account(), expiresAt = null))
        advanceUntilIdle()

        assertEquals(20, viewModel.shown().items.size)
    }

    // --- halaman berikutnya ---

    @Test
    fun `memuat halaman berikutnya menambah daftar sampai halaman terakhir`() = runTest(mainRule.dispatcher) {
        servePages(lastPage = 3)
        val viewModel = viewModel()
        advanceUntilIdle()

        viewModel.loadMore()
        assertTrue(viewModel.shown().loadingMore)
        // Permintaan kedua selama halaman sedang dimuat diabaikan.
        viewModel.loadMore()
        advanceUntilIdle()
        assertEquals(summaries(1..40), viewModel.shown().items)
        assertTrue(viewModel.shown().canLoadMore)
        assertFalse(viewModel.shown().loadingMore)

        viewModel.loadMore()
        advanceUntilIdle()
        assertEquals(summaries(1..60), viewModel.shown().items)
        assertFalse("Halaman terakhir sudah dimuat", viewModel.shown().canLoadMore)

        viewModel.loadMore()
        advanceUntilIdle()
        assertEquals(listOf(1, 2, 3), repository.listCalls)
    }

    @Test
    fun `pesanan baru yang menggeser halaman tidak membuat baris ganda`() = runTest(mainRule.dispatcher) {
        repository.listHandler = { page ->
            // Halaman 2 mengulang pesanan terakhir halaman 1 (ada pesanan baru di server).
            val items = if (page == 1) summaries(1..20) else summaries(20..39)
            ApiResult.Success(page(items, page, lastPage = 2))
        }
        val viewModel = viewModel()
        advanceUntilIdle()

        viewModel.loadMore()
        advanceUntilIdle()

        assertEquals((1..39).map(::number), viewModel.numbers())
    }

    @Test
    fun `halaman kosong menghentikan pemuatan apa pun kata meta`() = runTest(mainRule.dispatcher) {
        repository.listHandler = { page ->
            ApiResult.Success(page(if (page == 1) summaries(1..20) else emptyList(), page, lastPage = 5))
        }
        val viewModel = viewModel()
        advanceUntilIdle()

        viewModel.loadMore()
        advanceUntilIdle()

        assertFalse(viewModel.shown().canLoadMore)
        assertEquals(20, viewModel.shown().items.size)
    }

    @Test
    fun `halaman berikutnya gagal mempertahankan daftar dan hanya diulang atas permintaan`() = runTest(mainRule.dispatcher) {
        servePages()
        val viewModel = viewModel()
        advanceUntilIdle()

        repository.listHandler = { ApiResult.Failure(serverError) }
        viewModel.loadMore()
        advanceUntilIdle()
        assertEquals(serverError, viewModel.shown().loadMoreError)
        assertEquals(20, viewModel.shown().items.size)
        assertFalse(viewModel.shown().loadingMore)

        // Guliran yang memicu loadMore() lagi tidak mengulang sendiri.
        viewModel.loadMore()
        advanceUntilIdle()
        assertEquals(listOf(1, 2), repository.listCalls)

        servePages()
        viewModel.retryLoadMore()
        advanceUntilIdle()
        assertEquals(summaries(1..40), viewModel.shown().items)
        assertNull(viewModel.shown().loadMoreError)
        assertEquals(listOf(1, 2, 2), repository.listCalls)
    }

    @Test
    fun `401 pada halaman berikutnya mengosongkan layar dan meminta masuk lagi`() = runTest(mainRule.dispatcher) {
        servePages()
        val viewModel = viewModel()
        advanceUntilIdle()

        repository.listHandler = { ApiResult.Failure(unauthenticated) }
        viewModel.loadMore()
        advanceUntilIdle()

        assertEquals(OrdersUiState.SignedOut(sessionExpired = true), viewModel.uiState.value)
    }

    // --- penyegaran ---

    @Test
    fun `muat ulang mengganti daftar dengan halaman pertama terbaru dan mengulang paging dari awal`() =
        runTest(mainRule.dispatcher) {
            servePages()
            val viewModel = viewModel()
            advanceUntilIdle()
            viewModel.loadMore()
            advanceUntilIdle()
            assertEquals(40, viewModel.shown().items.size)

            repository.listHandler = { page ->
                val items = if (page == 1) listOf(summary(100)) + summaries(1..19) else summaries(20..39)
                ApiResult.Success(page(items, page, lastPage = 2))
            }
            viewModel.refresh()
            assertTrue(viewModel.shown().refreshing)
            // Ketukan ganda pada muat ulang tidak mengirim dua kali.
            viewModel.refresh()
            advanceUntilIdle()

            assertEquals(listOf(number(100)) + (1..19).map(::number), viewModel.numbers())
            assertFalse(viewModel.shown().refreshing)
            assertTrue(viewModel.shown().canLoadMore)
            assertEquals(listOf(1, 2, 1), repository.listCalls)

            viewModel.loadMore()
            advanceUntilIdle()
            assertEquals(listOf(1, 2, 1, 2), repository.listCalls)
            assertEquals(40, viewModel.shown().items.size)
        }

    @Test
    fun `muat ulang gagal mempertahankan daftar dan menandainya mungkin belum terbaru`() = runTest(mainRule.dispatcher) {
        servePages()
        val viewModel = viewModel()
        advanceUntilIdle()

        repository.listHandler = { ApiResult.Failure(ApiError.Network) }
        viewModel.refresh()
        advanceUntilIdle()

        assertEquals(summaries(1..20), viewModel.shown().items)
        assertEquals(ApiError.Network, viewModel.shown().staleReason)
        assertFalse(viewModel.shown().refreshing)

        servePages()
        viewModel.refresh()
        advanceUntilIdle()
        assertNull(viewModel.shown().staleReason)
    }

    @Test
    fun `muat ulang dari keadaan kosong menampilkan pesanan yang baru dibuat`() = runTest(mainRule.dispatcher) {
        val viewModel = viewModel()
        advanceUntilIdle()
        assertEquals(OrdersUiState.Empty, viewModel.uiState.value)

        repository.listHandler = { ApiResult.Success(page(listOf(summary(1)))) }
        viewModel.refresh()
        advanceUntilIdle()

        assertEquals(listOf(number(1)), viewModel.numbers())
    }

    @Test
    fun `401 saat muat ulang mengosongkan daftar yang sedang tampil`() = runTest(mainRule.dispatcher) {
        servePages()
        val viewModel = viewModel()
        advanceUntilIdle()

        repository.listHandler = { ApiResult.Failure(unauthenticated) }
        viewModel.refresh()
        advanceUntilIdle()

        assertEquals(OrdersUiState.SignedOut(sessionExpired = true), viewModel.uiState.value)
    }

    @Test
    fun `muat ulang membatalkan halaman berikutnya yang sedang dimuat`() = runTest(mainRule.dispatcher) {
        servePages()
        val viewModel = viewModel()
        advanceUntilIdle()

        repository.listHandler = { page ->
            if (page == 2) delay(5_000)
            ApiResult.Success(page(summaries((page - 1) * 20 + 1..page * 20), page, lastPage = 3))
        }
        viewModel.loadMore()
        runCurrent()
        viewModel.refresh()
        advanceUntilIdle()

        // Jawaban halaman 2 yang terlambat tidak ditempelkan ke daftar yang baru disegarkan.
        assertEquals(summaries(1..20), viewModel.shown().items)
        assertFalse(viewModel.shown().loadingMore)
    }

    // --- kembali ke layar ---

    @Test
    fun `layar tampil lagi memperbarui pesanan terbaru tanpa membuang halaman yang sudah dimuat`() =
        runTest(mainRule.dispatcher) {
            servePages()
            val viewModel = viewModel()
            // Komposisi pertama: pemuatan awal masih berjalan, tidak ada permintaan kedua.
            viewModel.onScreenShown()
            advanceUntilIdle()
            assertEquals(listOf(1), repository.listCalls)
            viewModel.loadMore()
            advanceUntilIdle()

            // Toko mengubah status pesanan 3, dan ada satu pesanan baru (mis. dari checkout).
            repository.listHandler = { page ->
                val first = listOf(summary(100)) + summaries(1..19).map {
                    if (it.orderNumber == number(3)) it.copy(status = OrderStatus.Shipped) else it
                }
                ApiResult.Success(page(if (page == 1) first else summaries(20..39), page, lastPage = 3))
            }
            viewModel.onScreenShown()
            advanceUntilIdle()

            assertEquals(listOf(number(100)) + (1..40).map(::number), viewModel.numbers())
            assertEquals(OrderStatus.Shipped, viewModel.shown().items.first { it.orderNumber == number(3) }.status)
            assertTrue(viewModel.shown().canLoadMore)
            // Paging berlanjut dari halaman yang belum dimuat, bukan dari awal.
            servePages()
            viewModel.loadMore()
            advanceUntilIdle()
            assertEquals(listOf(1, 2, 1, 3), repository.listCalls)
        }

    @Test
    fun `layar tampil lagi dengan satu halaman cukup mengganti daftar`() = runTest(mainRule.dispatcher) {
        repository.listHandler = { ApiResult.Success(page(listOf(summary(1)))) }
        val viewModel = viewModel()
        advanceUntilIdle()

        repository.listHandler = { ApiResult.Success(page(listOf(summary(2), summary(1, status = OrderStatus.Confirmed)))) }
        viewModel.onScreenShown()
        advanceUntilIdle()

        assertEquals(listOf(number(2), number(1)), viewModel.numbers())
        assertEquals(OrderStatus.Confirmed, viewModel.shown().items[1].status)
    }

    @Test
    fun `layar tampil lagi setelah 401 memuat ulang bila sesi sudah ada`() = runTest(mainRule.dispatcher) {
        repository.listHandler = { ApiResult.Failure(unauthenticated) }
        val viewModel = viewModel()
        advanceUntilIdle()
        assertEquals(OrdersUiState.SignedOut(sessionExpired = true), viewModel.uiState.value)

        servePages(lastPage = 1)
        viewModel.onScreenShown()
        advanceUntilIdle()

        assertEquals(20, viewModel.shown().items.size)
    }

    @Test
    fun `layar tampil saat belum masuk tidak memanggil server`() = runTest(mainRule.dispatcher) {
        session.set(SessionState.SignedOut())
        val viewModel = viewModel()
        advanceUntilIdle()

        viewModel.onScreenShown()
        viewModel.refresh()
        viewModel.loadMore()
        advanceUntilIdle()

        assertTrue(repository.listCalls.isEmpty())
        assertEquals(OrdersUiState.SignedOut(sessionExpired = false), viewModel.uiState.value)
    }
}
