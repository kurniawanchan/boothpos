package com.boothpos.customer.feature.orders.contract

import com.boothpos.customer.core.data.domain.model.FulfilmentMethod
import com.boothpos.customer.core.data.testing.RealPayloads
import com.boothpos.customer.core.network.HttpClientFactory
import com.boothpos.customer.core.network.NetworkConfig
import com.boothpos.customer.core.network.NetworkJson
import com.boothpos.customer.core.network.RetrofitFactory
import com.boothpos.customer.core.network.UnauthorizedEvents
import com.boothpos.customer.core.network.dto.DataEnvelope
import com.boothpos.customer.core.network.dto.PagedEnvelope
import com.boothpos.customer.core.network.dto.ProblemDto
import com.boothpos.customer.core.network.error.ApiError
import com.boothpos.customer.core.network.error.ApiErrorCodes
import com.boothpos.customer.core.network.error.ApiResult
import com.boothpos.customer.core.network.error.ProblemJsonParser
import com.boothpos.customer.core.network.error.SafeApiCall
import com.boothpos.customer.core.network.error.errorOrNull
import com.boothpos.customer.core.network.error.isUnauthenticated
import com.boothpos.customer.feature.orders.data.OrderDetailDto
import com.boothpos.customer.feature.orders.data.OrderSummaryDto
import com.boothpos.customer.feature.orders.data.OrderTrackingDto
import com.boothpos.customer.feature.orders.data.OrdersApi
import com.boothpos.customer.feature.orders.data.OrdersRepositoryImpl
import com.boothpos.customer.feature.orders.domain.OrderEventType
import com.boothpos.customer.feature.orders.domain.OrderStatus
import java.math.BigDecimal
import java.time.Instant
import kotlinx.coroutines.test.runTest
import kotlinx.serialization.json.Json
import mockwebserver3.MockWebServer
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test

/**
 * Kontrak §8 (daftar, detail, pelacakan pesanan pelanggan) dan §3 terhadap jawaban NYATA backend,
 * lewat Retrofit + converter + `Json` milik :core:network dan `OrdersRepositoryImpl`. Berkas ada di
 * `src/test/resources/real` (lihat README di sana). Telepon dan e-mail sudah disamarkan.
 *
 * Yang TIDAK terekam untuk endpoint pelanggan: pesanan berstatus confirmed/packing/ready_for_pickup/
 * delivered/picked_up, serta detail (bukan pelacakan) yang berisi `shipment`. Kosakata status dari
 * rekaman sisi staf diperiksa terpisah (lihat tes kosakata), tanpa mengaku sebagai jawaban pelanggan.
 */
class RealOrdersContractTest {

    private val server = MockWebServer()
    private val json = NetworkJson.create()

    // Diagnostik, bukan jalur aplikasi: `Json` aplikasi yang sama, hanya `ignoreUnknownKeys` dimatikan,
    // supaya field rekaman yang namanya tidak cocok dengan DTO (lalu diam-diam jatuh ke nilai bawaan)
    // ketahuan. Dipakai hanya oleh tes "tidak ada field rekaman yang diabaikan".
    private val strict = Json(from = json) { ignoreUnknownKeys = false }

    private inline fun <reified T> strictDecode(name: String, derived: Boolean = false): T {
        val body = (if (derived) RealPayloads.derived(name) else RealPayloads.recorded(name)).body
        return strict.decodeFromString(body)
    }
    private lateinit var repository: OrdersRepositoryImpl

    @Before
    fun setUp() {
        server.start()
        val config = NetworkConfig(server.url("/api/v1/").toString())
        val client = HttpClientFactory.create(config, { FAKE_TOKEN }, UnauthorizedEvents())
        val api = RetrofitFactory.create(config, json, client).create(OrdersApi::class.java)
        repository = OrdersRepositoryImpl(api, SafeApiCall(ProblemJsonParser(json)))
    }

    @After
    fun tearDown() = server.close()

    private fun <T> ApiResult<T>.value(): T = (this as ApiResult.Success).value

    private fun notFound(requestId: String) = ApiError.Http(
        status = 404,
        code = ApiErrorCodes.NOT_FOUND,
        detail = "Data yang Anda cari tidak ditemukan.",
        requestId = requestId,
    )

    // --- 200 daftar ---

    @Test
    fun `200 daftar pesanan nyata - terbaru dulu, uang persis, meta halaman`() = runTest {
        val recorded = RealPayloads.recorded("orders_list_200")
        assertEquals(200, recorded.status)
        server.enqueueRecorded(recorded)

        val paged = repository.orders(page = 1).value()

        assertEquals(
            listOf("CO-MZYWRXNDSY", "CO-YFQB1Q4KCG", "CO-V1NKWSJQCN"),
            paged.items.map { it.orderNumber },
        )
        val pickup = paged.items.first()
        assertEquals(OrderStatus.AwaitingConfirmation, pickup.status)
        assertEquals(FulfilmentMethod.Pickup, pickup.fulfilmentMethod)
        assertEquals(BigDecimal("75000.00"), pickup.total)
        assertEquals(1, pickup.itemCount)
        assertEquals("Etalase Bruno 1794456911541 — Ukuran S", pickup.firstItemName)
        assertEquals(Instant.parse("2026-11-12T04:15:29Z"), pickup.createdAt)
        assertEquals(Instant.parse("2026-11-12T04:15:29Z"), pickup.updatedAt)
        val courier = paged.items.last()
        assertEquals(FulfilmentMethod.Courier, courier.fulfilmentMethod)
        assertEquals(BigDecimal("150000.00"), courier.total)
        assertEquals(Instant.parse("2026-11-12T04:15:26Z"), courier.createdAt)
        // `item_count` = jumlah BARIS (1), bukan jumlah unit (2).
        assertEquals(1, courier.itemCount)

        assertEquals(1, paged.page.currentPage)
        assertEquals(20, paged.page.perPage)
        assertEquals(3, paged.page.total)
        assertEquals(1, paged.page.lastPage)
        assertFalse(paged.page.hasMore)

        val request = server.takeRequest()
        assertEquals("GET", request.method)
        assertEquals("/api/v1/customer/orders", request.url.encodedPath)
        assertEquals("1", request.url.queryParameter("page"))
        assertEquals("20", request.url.queryParameter("per_page"))
        assertEquals("Bearer $FAKE_TOKEN", request.headers["Authorization"])
    }

    // --- 200 detail kurir, menunggu konfirmasi (shipment null) ---

    @Test
    fun `200 detail pesanan kurir nyata - uang, kontak, alamat, barang, shipment null, linimasa`() = runTest {
        val recorded = RealPayloads.recorded("order_detail_courier_awaiting_200")
        assertEquals(200, recorded.status)
        server.enqueueRecorded(recorded)

        val detail = repository.order("CO-V1NKWSJQCN").value()

        val summary = detail.summary
        assertEquals("CO-V1NKWSJQCN", summary.orderNumber)
        assertEquals(OrderStatus.AwaitingConfirmation, summary.status)
        assertEquals(FulfilmentMethod.Courier, summary.fulfilmentMethod)
        assertEquals(BigDecimal("150000.00"), summary.total)
        assertEquals(BigDecimal("150000.00"), detail.subtotal)
        assertEquals(BigDecimal("0.00"), detail.shippingCost)
        // Ongkir belum ditetapkan toko untuk pesanan kurir yang belum dikonfirmasi.
        assertTrue(detail.shippingCostPending)

        assertEquals("Pelanggan Bruno A", detail.contact.name)
        assertEquals("0800****0011", detail.contact.phone)
        assertEquals("b***@example.test", detail.contact.email)
        val address = detail.shippingAddress!!
        assertEquals("Pelanggan Bruno A", address.recipientName)
        assertEquals("Jl. Contoh Uji No. 1", address.addressLine)
        assertEquals("Bandung", address.city)
        assertEquals("Jawa Barat", address.province)
        assertEquals("40111", address.postalCode)
        assertEquals("Pagar hijau", address.notes)

        assertEquals("Mohon dibungkus rapi.", detail.notes)
        assertNull(detail.cancelReason)
        assertNull(detail.instructions)

        val item = detail.items.single()
        assertEquals("Etalase Bruno 1794456911541 — Ukuran S", item.name)
        assertEquals("BRN-BR-DGDRPJ-1", item.sku)
        assertEquals(BigDecimal("75000.00"), item.unitPrice)
        assertEquals(2, item.quantity)
        assertEquals(BigDecimal("150000.00"), item.lineTotal)

        assertNull("Belum dikirim: shipment null", detail.shipment)
        val event = detail.timeline.single()
        assertEquals(OrderStatus.AwaitingConfirmation, event.status)
        assertEquals(OrderEventType.StatusChanged, event.type)
        assertNull(event.note)
        assertEquals(Instant.parse("2026-11-12T04:15:26Z"), event.occurredAt)

        val request = server.takeRequest()
        assertEquals("/api/v1/customer/orders/CO-V1NKWSJQCN", request.url.encodedPath)
        assertEquals("Bearer $FAKE_TOKEN", request.headers["Authorization"])
    }

    // --- 200 detail ambil di toko, dibatalkan ---

    @Test
    fun `200 detail pesanan ambil di toko yang dibatalkan nyata - alamat null, alasan batal, linimasa terbaru dulu`() = runTest {
        val recorded = RealPayloads.recorded("order_detail_pickup_cancelled_200")
        assertEquals(200, recorded.status)
        server.enqueueRecorded(recorded)

        val detail = repository.order("CO-MZYWRXNDSY").value()

        assertEquals(OrderStatus.Cancelled, detail.summary.status)
        assertEquals(FulfilmentMethod.Pickup, detail.summary.fulfilmentMethod)
        assertEquals(BigDecimal("75000.00"), detail.summary.total)
        assertEquals(BigDecimal("0.00"), detail.shippingCost)
        assertFalse("Ambil di toko tidak pernah menunggu ongkir", detail.shippingCostPending)
        assertNull("Ambil di toko: tanpa alamat kirim", detail.shippingAddress)
        assertNull(detail.shipment)
        assertEquals("Stok dialokasikan untuk acara lain.", detail.cancelReason)
        assertEquals("Pesanan uji untuk dibatalkan toko.", detail.notes)
        assertEquals("Pelanggan Bruno A Diperbarui", detail.contact.name)

        // Linimasa terbaru dulu, persis urutan server: dibatalkan, lalu dibuat.
        assertEquals(
            listOf(OrderStatus.Cancelled, OrderStatus.AwaitingConfirmation),
            detail.timeline.map { it.status },
        )
        assertEquals("Stok dialokasikan untuk acara lain.", detail.timeline.first().note)
        assertEquals(Instant.parse("2026-11-12T04:15:40Z"), detail.timeline.first().occurredAt)
        assertEquals(Instant.parse("2026-11-12T04:15:40Z"), detail.summary.updatedAt)
    }

    // --- 200 pelacakan sebelum dikirim ---

    @Test
    fun `200 pelacakan sebelum dikirim nyata - shipment null dan diperbarui oleh toko`() = runTest {
        val recorded = RealPayloads.recorded("tracking_courier_awaiting_200")
        assertEquals(200, recorded.status)
        server.enqueueRecorded(recorded)

        val tracking = repository.tracking("CO-V1NKWSJQCN").value()

        assertEquals("CO-V1NKWSJQCN", tracking.orderNumber)
        assertEquals(OrderStatus.AwaitingConfirmation, tracking.status)
        assertEquals(FulfilmentMethod.Courier, tracking.fulfilmentMethod)
        assertTrue(tracking.updatedByStore)
        assertNull(tracking.shipment)
        assertEquals(1, tracking.timeline.size)
        assertEquals("/api/v1/customer/orders/CO-V1NKWSJQCN/tracking", server.takeRequest().url.encodedPath)
    }

    // --- 200 pelacakan setelah dikirim (shipment terisi, linimasa enam peristiwa) ---

    @Test
    fun `200 pelacakan setelah dikirim nyata - shipment, tautan null, dan urutan linimasa server dipertahankan`() = runTest {
        val recorded = RealPayloads.recorded("tracking_courier_shipped_200")
        assertEquals(200, recorded.status)
        server.enqueueRecorded(recorded)

        val tracking = repository.tracking("CO-V1NKWSJQCN").value()

        assertEquals(OrderStatus.Shipped, tracking.status)
        assertTrue(tracking.updatedByStore)
        val shipment = tracking.shipment!!
        assertEquals("JNE", shipment.courierName)
        assertEquals("YES", shipment.serviceName)
        assertEquals("BRUNO-1794456935022-R", shipment.trackingNumber)
        // `tracking_url` null di rekaman: tidak ada tombol "Lacak paket".
        assertNull(shipment.trackingUrl)
        assertNull(shipment.trackingLink)
        assertEquals(Instant.parse("2026-11-12T04:15:35Z"), shipment.dispatchedAt)
        assertNull(shipment.deliveredAt)
        assertEquals(BigDecimal("15000.00"), shipment.shippingCost)

        // Urutan persis server (terbaru dulu), termasuk peristiwa pada detik yang sama.
        assertEquals(
            listOf(
                OrderStatus.Shipped to OrderEventType.TrackingUpdated,
                OrderStatus.Shipped to OrderEventType.StatusChanged,
                OrderStatus.Packing to OrderEventType.StatusChanged,
                OrderStatus.Confirmed to OrderEventType.StatusChanged,
                OrderStatus.Confirmed to OrderEventType.ShippingCostSet,
                OrderStatus.AwaitingConfirmation to OrderEventType.StatusChanged,
            ),
            tracking.timeline.map { it.status to it.type },
        )
        assertEquals(
            listOf(
                null,
                "Paket sudah diserahkan ke kurir.",
                "Pesanan sedang dikemas.",
                "Ongkir sudah ditetapkan toko.",
                null,
                null,
            ),
            tracking.timeline.map { it.note },
        )
        val times = tracking.timeline.map { it.occurredAt }
        assertTrue(times.all { it != null })
        assertEquals(times.sortedByDescending { it }, times)
        assertEquals(Instant.parse("2026-11-12T04:15:26Z"), times.last())
    }

    // --- kosakata status dari rekaman sisi staf ---

    @Test
    fun `kosakata status dan jenis peristiwa yang dipancarkan server nyata semuanya dikenal aplikasi`() {
        // Diambil dari `status` dan `event_type` pada rekaman #52-#68 (jawaban STAF, satu siklus penuh
        // pesanan kurir dan ambil di toko): hanya string-nya, bukan bentuk jawaban pelanggan.
        val observedStatuses = listOf(
            "awaiting_confirmation", "confirmed", "packing", "shipped", "delivered",
            "ready_for_pickup", "picked_up", "cancelled",
        )
        val observedEvents = listOf("status_changed", "shipping_cost_set", "tracking_updated")

        assertTrue(observedStatuses.none { OrderStatus.fromWire(it) == OrderStatus.Unknown })
        assertEquals(observedStatuses.size, observedStatuses.map { OrderStatus.fromWire(it) }.toSet().size)
        assertTrue(observedEvents.none { OrderEventType.fromWire(it) == OrderEventType.Unknown })
        assertEquals(observedEvents.size, observedEvents.map { OrderEventType.fromWire(it) }.toSet().size)
    }

    // --- 404 ---

    @Test
    fun `404 detail pesanan milik akun lain nyata - not_found yang sama dengan nomor tak ada`() = runTest {
        val other = RealPayloads.recorded("order_detail_other_account_404")
        val unknown = RealPayloads.recorded("order_unknown_number_404")
        assertEquals(404, other.status)
        assertEquals(404, unknown.status)
        server.enqueueRecorded(other)
        server.enqueueRecorded(unknown)

        val foreign = repository.order("CO-V1NKWSJQCN").errorOrNull() as ApiError.Http
        val missing = repository.order("CO-0000000000").errorOrNull() as ApiError.Http

        assertEquals(notFound("6f9ba384-15c2-4b6a-ba0b-ddb1cf43ce39"), foreign)
        assertEquals(notFound("4088b679-34e0-4646-9d39-60b72808e324"), missing)
        // Anti-enumerasi: pesanan akun lain tak terbedakan dari nomor yang tidak ada (selain request_id).
        assertEquals(foreign.copy(requestId = null), missing.copy(requestId = null))
    }

    @Test
    fun `404 pelacakan pesanan milik akun lain nyata - not_found`() = runTest {
        val recorded = RealPayloads.recorded("tracking_other_account_404")
        assertEquals(404, recorded.status)
        server.enqueueRecorded(recorded)

        val error = repository.tracking("CO-V1NKWSJQCN").errorOrNull()

        assertEquals(notFound("2fb4e19f-e821-4a39-8d8a-e28bdea08637"), error)
    }

    // --- 401 ---

    @Test
    fun `401 unauthenticated nyata pada daftar pesanan - kode dikenali sebagai sesi tidak valid`() = runTest {
        val recorded = RealPayloads.recorded("unauthenticated_401")
        assertEquals(401, recorded.status)
        server.enqueueRecorded(recorded)

        val error = repository.orders(page = 1).errorOrNull() as ApiError.Http

        assertEquals(
            ApiError.Http(
                status = 401,
                code = ApiErrorCodes.UNAUTHENTICATED,
                detail = "Sesi Anda tidak valid atau sudah berakhir. Silakan masuk kembali.",
                requestId = "e7710768-c063-46a1-8fd2-69ac154ba792",
            ),
            error,
        )
        assertTrue(error.isUnauthenticated)
        assertNotNull(error.detail)
    }

    // --- tidak ada field rekaman yang diabaikan diam-diam ---

    @Test
    fun `setiap field rekaman pesanan dan galatnya dimodelkan DTO aplikasi`() {
        strictDecode<PagedEnvelope<OrderSummaryDto>>("orders_list_200")
        strictDecode<DataEnvelope<OrderDetailDto>>("order_detail_courier_awaiting_200")
        strictDecode<DataEnvelope<OrderDetailDto>>("order_detail_pickup_cancelled_200")
        strictDecode<DataEnvelope<OrderTrackingDto>>("tracking_courier_awaiting_200")
        strictDecode<DataEnvelope<OrderTrackingDto>>("tracking_courier_shipped_200")
        listOf("order_detail_other_account_404", "tracking_other_account_404", "order_unknown_number_404", "unauthenticated_401")
            .forEach { strictDecode<ProblemDto>(it) }
    }

    private companion object {
        const val FAKE_TOKEN = "tes-token-palsu-123"
    }
}
