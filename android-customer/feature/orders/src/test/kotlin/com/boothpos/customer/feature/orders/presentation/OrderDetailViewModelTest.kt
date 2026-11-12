package com.boothpos.customer.feature.orders.presentation

import androidx.lifecycle.SavedStateHandle
import app.cash.turbine.test
import com.boothpos.customer.core.data.domain.model.FulfilmentMethod
import com.boothpos.customer.core.data.domain.session.SessionState
import com.boothpos.customer.core.data.testing.FakeSessionRepository
import com.boothpos.customer.core.data.testing.MainDispatcherRule
import com.boothpos.customer.core.data.testing.TestData
import com.boothpos.customer.core.network.error.ApiError
import com.boothpos.customer.core.network.error.ApiErrorCodes
import com.boothpos.customer.core.network.error.ApiResult
import com.boothpos.customer.feature.orders.domain.OrderEventType
import com.boothpos.customer.feature.orders.domain.OrderStatus
import com.boothpos.customer.feature.orders.presentation.OrdersTestData.ORDER_NUMBER
import com.boothpos.customer.feature.orders.presentation.OrdersTestData.SAFE_TRACKING_URL
import com.boothpos.customer.feature.orders.presentation.OrdersTestData.detail
import com.boothpos.customer.feature.orders.presentation.OrdersTestData.event
import com.boothpos.customer.feature.orders.presentation.OrdersTestData.shipment
import com.boothpos.customer.feature.orders.presentation.OrdersTestData.shippedDetail
import com.boothpos.customer.feature.orders.presentation.OrdersTestData.tracking
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.delay
import kotlinx.coroutines.test.advanceUntilIdle
import kotlinx.coroutines.test.runCurrent
import kotlinx.coroutines.test.runTest
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertNull
import org.junit.Assert.assertThrows
import org.junit.Assert.assertTrue
import org.junit.Rule
import org.junit.Test

@OptIn(ExperimentalCoroutinesApi::class)
class OrderDetailViewModelTest {

    @get:Rule
    val mainRule = MainDispatcherRule()

    private val repository = FakeOrdersRepository()
    private val session = FakeSessionRepository(SessionState.SignedIn(TestData.account(), expiresAt = null))

    private fun viewModel(orderNumber: String = ORDER_NUMBER) = OrderDetailViewModel(
        SavedStateHandle(mapOf(OrderDetailRoute.ORDER_NUMBER_ARG to orderNumber)),
        repository,
        session,
    )

    private fun OrderDetailViewModel.shown() = uiState.value as OrderDetailUiState.Content

    private val unauthenticated = ApiError.Http(401, ApiErrorCodes.UNAUTHENTICATED)
    private val notFound = ApiError.Http(404, ApiErrorCodes.NOT_FOUND, detail = "Pesanan tidak ditemukan.")
    private val serverError = ApiError.Http(500, ApiErrorCodes.SERVER_ERROR)

    // --- muat ---

    @Test
    fun `memuat rincian dan pelacakan pesanan dari nomor rute`() = runTest(mainRule.dispatcher) {
        val order = shippedDetail()
        repository.serve(order)

        val viewModel = viewModel()
        assertEquals(OrderDetailUiState.Loading, viewModel.uiState.value)
        advanceUntilIdle()

        assertEquals(order, viewModel.shown().order)
        assertTrue("updated_by == store", viewModel.shown().updatedByStore)
        assertFalse(viewModel.shown().refreshing)
        assertNull(viewModel.shown().refreshError)
        assertFalse(viewModel.shown().confirmingHandoff)
        assertEquals(listOf(ORDER_NUMBER), repository.detailCalls)
        assertEquals(listOf(ORDER_NUMBER), repository.trackingCalls)
    }

    @Test
    fun `rute tanpa nomor pesanan ditolak saat ViewModel dibuat`() {
        assertThrows(IllegalStateException::class.java) {
            OrderDetailViewModel(SavedStateHandle(), repository, session)
        }
    }

    @Test
    fun `pelacakan yang sejalan menimpa pengiriman dan linimasa rincian`() = runTest(mainRule.dispatcher) {
        val order = shippedDetail(shipment = shipment(trackingNumber = null))
        val newer = shipment(trackingNumber = "UJI0000000777")
        val newerTimeline = listOf(event(OrderStatus.Shipped, 4, OrderEventType.TrackingUpdated)) + order.timeline
        repository.detailHandler = { ApiResult.Success(order) }
        repository.trackingHandler = { ApiResult.Success(tracking(order, shipment = newer, timeline = newerTimeline)) }

        val viewModel = viewModel()
        advanceUntilIdle()

        assertEquals(newer, viewModel.shown().order.shipment)
        assertEquals(newerTimeline, viewModel.shown().order.timeline)
        // Uang, kontak, dan status tetap dari rincian.
        assertEquals(order.summary, viewModel.shown().order.summary)
        assertEquals(order.contact, viewModel.shown().order.contact)
    }

    @Test
    fun `pelacakan dengan status berbeda tidak dicampur ke rincian`() = runTest(mainRule.dispatcher) {
        val order = detail(status = OrderStatus.Packing, method = FulfilmentMethod.Courier)
        repository.detailHandler = { ApiResult.Success(order) }
        // Toko mengirim pesanan di antara dua permintaan: pelacakan sudah "shipped".
        repository.trackingHandler = {
            ApiResult.Success(tracking(order, status = OrderStatus.Shipped, shipment = shipment(), timeline = OrdersTestData.shippedTimeline))
        }

        val viewModel = viewModel()
        advanceUntilIdle()

        assertEquals(order, viewModel.shown().order)
        assertNull(viewModel.shown().order.shipment)
        assertTrue(viewModel.shown().updatedByStore)
    }

    @Test
    fun `pelacakan gagal tetap menampilkan rincian dengan tanda belum terbaru`() = runTest(mainRule.dispatcher) {
        val order = shippedDetail()
        repository.detailHandler = { ApiResult.Success(order) }
        repository.trackingHandler = { ApiResult.Failure(ApiError.Timeout) }

        val viewModel = viewModel()
        advanceUntilIdle()

        assertEquals(order, viewModel.shown().order)
        assertEquals(ApiError.Timeout, viewModel.shown().refreshError)
        // Tanpa jawaban pelacakan, aplikasi tidak mengaku tahu siapa yang memperbarui.
        assertFalse(viewModel.shown().updatedByStore)
    }

    @Test
    fun `pelacakan dari sumber selain toko tidak diberi tanda diperbarui oleh toko`() = runTest(mainRule.dispatcher) {
        repository.serve(shippedDetail(), updatedByStore = false)

        val viewModel = viewModel()
        advanceUntilIdle()

        assertFalse(viewModel.shown().updatedByStore)
    }

    @Test
    fun `status yang belum dikenal tetap menampilkan pesanan`() = runTest(mainRule.dispatcher) {
        val order = detail(status = OrderStatus.Unknown, method = null, timeline = listOf(event(OrderStatus.Unknown, 0, OrderEventType.Unknown)))
        repository.serve(order)

        val viewModel = viewModel()
        advanceUntilIdle()

        assertEquals(OrderStatus.Unknown, viewModel.shown().order.summary.status)
        assertEquals(OrderEventType.Unknown, viewModel.shown().order.timeline.single().type)
    }

    // --- galat ---

    @Test
    fun `rincian gagal menampilkan galat dan coba lagi memuat pesanan`() = runTest(mainRule.dispatcher) {
        repository.detailHandler = { ApiResult.Failure(ApiError.Network) }
        repository.trackingHandler = { ApiResult.Failure(ApiError.Network) }
        val viewModel = viewModel()
        advanceUntilIdle()
        assertEquals(OrderDetailUiState.Error(ApiError.Network), viewModel.uiState.value)

        repository.serve(detail())
        viewModel.refresh()
        assertEquals(OrderDetailUiState.Error(ApiError.Network, retrying = true), viewModel.uiState.value)
        // Ketukan ganda pada "Coba lagi" tidak mengirim dua kali.
        viewModel.refresh()
        advanceUntilIdle()

        assertEquals(detail(), viewModel.shown().order)
        assertEquals(2, repository.detailCalls.size)
        assertEquals(2, repository.trackingCalls.size)
    }

    @Test
    fun `404 menampilkan pesanan tidak ditemukan dan tidak dicoba ulang`() = runTest(mainRule.dispatcher) {
        repository.detailHandler = { ApiResult.Failure(notFound) }
        repository.trackingHandler = { ApiResult.Failure(notFound) }

        val viewModel = viewModel("CO-BUKANMILIK")
        advanceUntilIdle()
        assertEquals(OrderDetailUiState.NotFound, viewModel.uiState.value)

        viewModel.refresh()
        advanceUntilIdle()
        assertEquals(OrderDetailUiState.NotFound, viewModel.uiState.value)
        assertEquals(listOf("CO-BUKANMILIK"), repository.detailCalls)
    }

    @Test
    fun `pesanan yang hilang saat disegarkan berganti menjadi tidak ditemukan`() = runTest(mainRule.dispatcher) {
        repository.serve(detail())
        val viewModel = viewModel()
        advanceUntilIdle()

        repository.detailHandler = { ApiResult.Failure(notFound) }
        repository.trackingHandler = { ApiResult.Failure(notFound) }
        viewModel.refresh()
        advanceUntilIdle()

        assertEquals(OrderDetailUiState.NotFound, viewModel.uiState.value)
    }

    @Test
    fun `401 membuang rincian dan meminta masuk lagi tanpa pesan galat`() = runTest(mainRule.dispatcher) {
        repository.detailHandler = { ApiResult.Failure(unauthenticated) }
        repository.trackingHandler = { ApiResult.Failure(unauthenticated) }

        val viewModel = viewModel()
        advanceUntilIdle()

        // Alur sesi berakhir milik :app yang membuka layar masuk.
        assertEquals(OrderDetailUiState.SignedOut(sessionExpired = true), viewModel.uiState.value)
    }

    @Test
    fun `401 hanya pada pelacakan diperlakukan sebagai sesi berakhir`() = runTest(mainRule.dispatcher) {
        repository.detailHandler = { ApiResult.Success(shippedDetail()) }
        repository.trackingHandler = { ApiResult.Failure(unauthenticated) }

        val viewModel = viewModel()
        advanceUntilIdle()

        assertEquals(OrderDetailUiState.SignedOut(sessionExpired = true), viewModel.uiState.value)
    }

    @Test
    fun `401 saat menyegarkan membuang rincian yang sedang tampil lalu masuk lagi memuat ulang`() =
        runTest(mainRule.dispatcher) {
            repository.serve(shippedDetail())
            val viewModel = viewModel()
            advanceUntilIdle()

            repository.detailHandler = { ApiResult.Failure(unauthenticated) }
            viewModel.refresh()
            advanceUntilIdle()
            assertEquals(OrderDetailUiState.SignedOut(sessionExpired = true), viewModel.uiState.value)

            // Lapisan sesi menghapus token, lalu pelanggan masuk lagi dengan akun yang sama.
            session.expire()
            advanceUntilIdle()
            repository.serve(shippedDetail())
            session.set(SessionState.SignedIn(TestData.account(), expiresAt = null))
            advanceUntilIdle()

            assertEquals(shippedDetail(), viewModel.shown().order)
        }

    // --- sesi ---

    @Test
    fun `belum masuk menampilkan ajakan masuk tanpa memanggil server`() = runTest(mainRule.dispatcher) {
        session.set(SessionState.SignedOut())

        val viewModel = viewModel()
        advanceUntilIdle()

        assertEquals(OrderDetailUiState.SignedOut(sessionExpired = false), viewModel.uiState.value)
        assertTrue(repository.detailCalls.isEmpty())
        assertTrue(repository.trackingCalls.isEmpty())
        viewModel.refresh()
        advanceUntilIdle()
        assertTrue(repository.detailCalls.isEmpty())
    }

    @Test
    fun `keluar saat rincian tampil langsung membuang kontak dan alamat dari layar`() = runTest(mainRule.dispatcher) {
        repository.serve(shippedDetail())
        val viewModel = viewModel()
        advanceUntilIdle()

        session.signOut()
        advanceUntilIdle()

        assertEquals(OrderDetailUiState.SignedOut(sessionExpired = false), viewModel.uiState.value)
    }

    @Test
    fun `sesi berakhir saat memuat membuang jawaban yang terlambat`() = runTest(mainRule.dispatcher) {
        repository.detailHandler = {
            delay(1_000)
            ApiResult.Success(shippedDetail())
        }
        repository.trackingHandler = { ApiResult.Success(tracking(shippedDetail())) }
        val viewModel = viewModel()
        runCurrent()

        session.expire()
        advanceUntilIdle()

        assertEquals(OrderDetailUiState.SignedOut(sessionExpired = true), viewModel.uiState.value)
    }

    @Test
    fun `perubahan profil akun yang sama tidak memuat ulang pesanan`() = runTest(mainRule.dispatcher) {
        repository.serve(detail())
        val viewModel = viewModel()
        advanceUntilIdle()

        session.updateAccount(TestData.account(name = "Nama Baru Uji"))
        advanceUntilIdle()

        assertEquals(1, repository.detailCalls.size)
        assertEquals(detail(), viewModel.shown().order)
    }

    // --- penyegaran pelacakan ---

    @Test
    fun `perbarui status memuat ulang rincian dan pelacakan lalu menampilkan nomor resi baru`() =
        runTest(mainRule.dispatcher) {
            val packing = detail(status = OrderStatus.Packing, method = FulfilmentMethod.Courier)
            repository.serve(packing)
            val viewModel = viewModel()
            advanceUntilIdle()
            assertNull(viewModel.shown().order.shipment)

            val shipped = shippedDetail()
            repository.serve(shipped)
            viewModel.refresh()
            assertTrue(viewModel.shown().refreshing)
            // Rincian lama tetap tampil selama penyegaran, dan ketukan ganda tidak mengirim dua kali.
            assertEquals(packing, viewModel.shown().order)
            viewModel.refresh()
            advanceUntilIdle()

            assertFalse(viewModel.shown().refreshing)
            assertEquals(OrderStatus.Shipped, viewModel.shown().order.summary.status)
            assertEquals(OrdersTestData.TRACKING_NUMBER, viewModel.shown().order.shipment?.trackingNumber)
            assertEquals(shipped.timeline, viewModel.shown().order.timeline)
            assertEquals(2, repository.detailCalls.size)
            assertEquals(2, repository.trackingCalls.size)
        }

    @Test
    fun `penyegaran gagal mempertahankan rincian dan menandainya mungkin belum terbaru`() = runTest(mainRule.dispatcher) {
        val order = shippedDetail()
        repository.serve(order)
        val viewModel = viewModel()
        advanceUntilIdle()

        repository.detailHandler = { ApiResult.Failure(ApiError.Network) }
        repository.trackingHandler = { ApiResult.Failure(ApiError.Network) }
        viewModel.refresh()
        advanceUntilIdle()

        assertEquals(order, viewModel.shown().order)
        assertEquals(ApiError.Network, viewModel.shown().refreshError)
        assertFalse(viewModel.shown().refreshing)
        assertTrue("Tanda toko dari muatan sebelumnya dipertahankan", viewModel.shown().updatedByStore)

        repository.serve(order)
        viewModel.refresh()
        advanceUntilIdle()
        assertNull(viewModel.shown().refreshError)
    }

    @Test
    fun `galat server saat menyegarkan tidak mengganti rincian dengan layar galat`() = runTest(mainRule.dispatcher) {
        repository.serve(detail())
        val viewModel = viewModel()
        advanceUntilIdle()

        repository.detailHandler = { ApiResult.Failure(serverError) }
        viewModel.refresh()
        advanceUntilIdle()

        assertEquals(serverError, viewModel.shown().refreshError)
        assertEquals(detail(), viewModel.shown().order)
    }

    // --- Lacak paket ---

    @Test
    fun `tautan https yang aman menawarkan Lacak paket dan dibuka setelah pemberitahuan disetujui`() =
        runTest(mainRule.dispatcher) {
            repository.serve(shippedDetail(shipment(trackingUrl = SAFE_TRACKING_URL)))
            val viewModel = viewModel()
            advanceUntilIdle()
            assertEquals(SAFE_TRACKING_URL, viewModel.shown().trackingLink)

            viewModel.events.test {
                viewModel.onTrackParcelClick()
                assertTrue(viewModel.shown().confirmingHandoff)
                // Belum ada yang dibuka sebelum pelanggan menyetujui pemberitahuan.
                expectNoEvents()

                viewModel.confirmHandoff()
                assertFalse(viewModel.shown().confirmingHandoff)
                assertEquals(OrderDetailEvent.OpenExternalUrl(SAFE_TRACKING_URL), awaitItem())

                // Persetujuan kedua tanpa pemberitahuan baru tidak membuka apa pun.
                viewModel.confirmHandoff()
                expectNoEvents()
            }
        }

    @Test
    fun `membatalkan pemberitahuan tidak membuka tautan`() = runTest(mainRule.dispatcher) {
        repository.serve(shippedDetail(shipment(trackingUrl = SAFE_TRACKING_URL)))
        val viewModel = viewModel()
        advanceUntilIdle()

        viewModel.events.test {
            viewModel.onTrackParcelClick()
            viewModel.dismissHandoff()
            assertFalse(viewModel.shown().confirmingHandoff)
            viewModel.confirmHandoff()
            expectNoEvents()
        }
    }

    @Test
    fun `tautan http skema lain dan tautan rusak tidak pernah ditawarkan atau dibuka`() = runTest(mainRule.dispatcher) {
        val unsafe = listOf(
            null,
            "",
            "http://kurir.example.test/lacak?resi=UJI0000000001",
            "HTTPS://kurir.example.test/lacak",
            "javascript:alert(1)",
            "intent://kurir.example.test/#Intent;scheme=https;end",
            "file:///data/data/com.boothpos.customer/files/sesi",
            "https://",
            "https:///lacak",
            "https://kurir.example.test/ lacak",
            "https://kurir.example.test@lain.example.test/lacak",
            "kurir.example.test/lacak",
            "bukan tautan",
        )
        unsafe.forEach { url ->
            repository.serve(shippedDetail(shipment(trackingUrl = url)))
            val viewModel = viewModel()
            advanceUntilIdle()

            assertNull("tidak boleh ditawarkan: $url", viewModel.shown().trackingLink)
            viewModel.events.test {
                viewModel.onTrackParcelClick()
                assertFalse("pemberitahuan tidak boleh tampil: $url", viewModel.shown().confirmingHandoff)
                viewModel.confirmHandoff()
                expectNoEvents()
            }
            // Nomor resi tetap tampil walau tautannya tidak dipakai.
            assertNotNull(viewModel.shown().order.shipment?.trackingNumber)
        }
    }

    @Test
    fun `pesanan tanpa pengiriman tidak menawarkan Lacak paket`() = runTest(mainRule.dispatcher) {
        repository.serve(detail())
        val viewModel = viewModel()
        advanceUntilIdle()

        assertNull(viewModel.shown().trackingLink)
        viewModel.onTrackParcelClick()
        assertFalse(viewModel.shown().confirmingHandoff)
    }

    @Test
    fun `tautan yang hilang saat penyegaran menutup pemberitahuan dan tidak dibuka`() = runTest(mainRule.dispatcher) {
        repository.serve(shippedDetail(shipment(trackingUrl = SAFE_TRACKING_URL)))
        val viewModel = viewModel()
        advanceUntilIdle()

        viewModel.events.test {
            repository.serve(shippedDetail(shipment(trackingUrl = null)))
            viewModel.refresh()
            // Pemberitahuan dibuka ketika penyegaran masih berjalan.
            viewModel.onTrackParcelClick()
            assertTrue(viewModel.shown().confirmingHandoff)
            advanceUntilIdle()

            assertFalse(viewModel.shown().confirmingHandoff)
            assertNull(viewModel.shown().trackingLink)
            viewModel.confirmHandoff()
            expectNoEvents()
        }
    }

    @Test
    fun `pemberitahuan yang terbuka bertahan melewati penyegaran bila tautannya masih ada`() = runTest(mainRule.dispatcher) {
        repository.serve(shippedDetail(shipment(trackingUrl = SAFE_TRACKING_URL)))
        val viewModel = viewModel()
        advanceUntilIdle()

        viewModel.refresh()
        viewModel.onTrackParcelClick()
        advanceUntilIdle()

        assertTrue(viewModel.shown().confirmingHandoff)
        assertFalse(viewModel.shown().refreshing)
    }

    // --- data pribadi ---

    @Test
    fun `state layar tidak membocorkan kontak dan alamat lewat toString`() = runTest(mainRule.dispatcher) {
        repository.serve(shippedDetail())
        val viewModel = viewModel()
        advanceUntilIdle()

        val printed = viewModel.uiState.value.toString()

        listOf("Pelanggan Uji", "0800-0000-0000", "pelanggan.uji@example.test", "Penerima Uji", "Jl. Contoh No. 1", "Pagar hijau")
            .forEach { secret -> assertFalse("bocor: $secret", printed.contains(secret)) }
    }
}
