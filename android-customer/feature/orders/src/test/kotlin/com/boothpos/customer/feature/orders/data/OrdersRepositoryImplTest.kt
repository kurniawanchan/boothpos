package com.boothpos.customer.feature.orders.data

import com.boothpos.customer.core.data.domain.model.FulfilmentMethod
import com.boothpos.customer.core.network.HttpClientFactory
import com.boothpos.customer.core.network.NetworkConfig
import com.boothpos.customer.core.network.NetworkJson
import com.boothpos.customer.core.network.RetrofitFactory
import com.boothpos.customer.core.network.UnauthorizedEvents
import com.boothpos.customer.core.network.error.ApiError
import com.boothpos.customer.core.network.error.ApiErrorCodes
import com.boothpos.customer.core.network.error.ApiResult
import com.boothpos.customer.core.network.error.ProblemJsonParser
import com.boothpos.customer.core.network.error.SafeApiCall
import com.boothpos.customer.core.network.error.isStoreUnavailable
import com.boothpos.customer.core.network.error.isUnauthenticated
import com.boothpos.customer.feature.orders.domain.OrderEventType
import com.boothpos.customer.feature.orders.domain.OrderStatus
import java.math.BigDecimal
import java.time.Instant
import kotlinx.coroutines.test.runTest
import mockwebserver3.MockResponse
import mockwebserver3.MockWebServer
import mockwebserver3.SocketEffect
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test

/** Kontrak §8 (`GET /customer/orders`, `…/{nomor}`, `…/{nomor}/tracking`) terhadap MockWebServer. */
class OrdersRepositoryImplTest {

    private val server = MockWebServer()
    private val json = NetworkJson.create()
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

    private fun enqueue(code: Int, body: String) = server.enqueue(MockResponse.Builder().code(code).body(body).build())

    private fun problem(status: Int, code: String) =
        """{"type":"https://boothpos.local/problems/$code","title":"Galat","status":$status,
            "detail":"Pesan aman dari server.","code":"$code","request_id":"req-uji-0001"}"""

    private fun <T> ApiResult<T>.value(): T = (this as ApiResult.Success).value

    private fun <T> ApiResult<T>.error(): ApiError = (this as ApiResult.Failure).error

    // Data contoh yang jelas-jelas palsu.

    private fun summaryJson(
        number: String = "CO-UJI0000001",
        status: String = "awaiting_confirmation",
        method: String = "pickup",
        total: String = "55000.00",
    ) = """"order_number":"$number","status":"$status","fulfilment_method":"$method","total":"$total",
        "item_count":2,"first_item_name":"Gantungan Kunci Uji — Kecil",
        "created_at":"2026-11-12T10:00:00+07:00","updated_at":"2026-11-12T11:30:00+07:00""""

    private val shipmentJson = """{"courier_name":"JNE","service_name":"REG","tracking_number":"UJI0000000001",
        "tracking_url":"https://kurir.example.test/lacak?resi=UJI0000000001",
        "dispatched_at":"2026-11-12T13:00:00+07:00","delivered_at":null,"shipping_cost":"12000.00"}"""

    private val timelineJson = """[
        {"status":"shipped","event_type":"tracking_updated","note":null,"occurred_at":"2026-11-12T14:00:00+07:00"},
        {"status":"shipped","event_type":"status_changed","note":"Paket diserahkan ke kurir.","occurred_at":"2026-11-12T13:00:00+07:00"},
        {"status":"confirmed","event_type":"shipping_cost_set","note":null,"occurred_at":"2026-11-12T11:00:00+07:00"},
        {"status":"awaiting_confirmation","event_type":"status_changed","note":null,"occurred_at":"2026-11-12T10:00:00+07:00"}]"""

    private fun detailJson(
        status: String = "shipped",
        method: String = "courier",
        shipment: String = shipmentJson,
        timeline: String = timelineJson,
        address: String = """{"recipient_name":"Penerima Uji","phone":"0800-0000-0001","address_line":"Jl. Contoh No. 1",
            "city":"Kota Uji","province":"Provinsi Uji","postal_code":null,"notes":"Pagar hijau"}""",
        subtotal: String = "55000.00",
    ) = """{"data":{${summaryJson(status = status, method = method, total = "67000.00")},
        "subtotal":"$subtotal","shipping_cost":"12000.00","shipping_cost_pending":false,
        "contact":{"name":"Pelanggan Uji","phone":"0800-0000-0000","email":"pelanggan.uji@example.test"},
        "shipping_address":$address,"notes":" Tolong dibungkus rapi. ","cancel_reason":null,
        "instructions":"Pesanan dikirim pada hari kerja.",
        "items":[{"name":"Gantungan Kunci Uji — Kecil","sku":"UJI0001","unit_price":"15000.00","qty":2,"line_total":"30000.00"},
                 {"name":"Stiker Uji — A5","sku":"UJI0002","unit_price":"25000.00","qty":1,"line_total":"25000.00"}],
        "shipment":$shipment,"timeline":$timeline,"field_baru_dari_server":{"apa":"saja"}}}"""

    // --- daftar ---

    @Test
    fun `daftar meminta halaman dengan token pelanggan dan memetakan ringkasan serta meta`() = runTest {
        enqueue(
            200,
            """{"data":[{${summaryJson()}},{${summaryJson(number = "CO-UJI0000002", status = "shipped", method = "courier", total = "120500.50")},
                "field_baru":true}],"meta":{"current_page":2,"per_page":20,"total":45,"last_page":3}}""",
        )

        val page = repository.orders(page = 2).value()

        val request = server.takeRequest()
        assertEquals("GET", request.method)
        assertEquals("/api/v1/customer/orders", request.url.encodedPath)
        assertEquals("2", request.url.queryParameter("page"))
        assertEquals("20", request.url.queryParameter("per_page"))
        assertEquals(setOf("page", "per_page"), request.url.queryParameterNames)
        assertEquals("Bearer $FAKE_TOKEN", request.headers["Authorization"])
        assertEquals(2, page.page.currentPage)
        assertEquals(3, page.page.lastPage)
        assertEquals(45, page.page.total)
        assertTrue(page.page.hasMore)

        val first = page.items[0]
        assertEquals("CO-UJI0000001", first.orderNumber)
        assertEquals(OrderStatus.AwaitingConfirmation, first.status)
        assertEquals(FulfilmentMethod.Pickup, first.fulfilmentMethod)
        assertEquals(BigDecimal("55000.00"), first.total)
        assertEquals(2, first.itemCount)
        assertEquals("Gantungan Kunci Uji — Kecil", first.firstItemName)
        assertEquals(Instant.parse("2026-11-12T03:00:00Z"), first.createdAt)
        assertEquals(Instant.parse("2026-11-12T04:30:00Z"), first.updatedAt)

        val second = page.items[1]
        assertEquals(OrderStatus.Shipped, second.status)
        assertEquals(FulfilmentMethod.Courier, second.fulfilmentMethod)
        assertEquals(BigDecimal("120500.50"), second.total)
    }

    @Test
    fun `kedelapan status kontrak dikenali dan nilai baru dari server menjadi tidak dikenal`() = runTest {
        val statuses = listOf(
            "awaiting_confirmation", "confirmed", "packing", "ready_for_pickup",
            "shipped", "delivered", "picked_up", "cancelled", "refunded",
        )
        val rows = statuses.mapIndexed { index, status -> "{${summaryJson(number = "CO-UJI000000$index", status = status)}}" }
        enqueue(200, """{"data":[${rows.joinToString(",")}],"meta":{"current_page":1,"per_page":20,"total":9,"last_page":1}}""")

        val items = repository.orders(page = 1).value().items

        assertEquals(
            listOf(
                OrderStatus.AwaitingConfirmation, OrderStatus.Confirmed, OrderStatus.Packing, OrderStatus.ReadyForPickup,
                OrderStatus.Shipped, OrderStatus.Delivered, OrderStatus.PickedUp, OrderStatus.Cancelled,
                // Status yang belum dikenal versi aplikasi ini: netral, bukan gagal urai.
                OrderStatus.Unknown,
            ),
            items.map { it.status },
        )
    }

    @Test
    fun `ringkasan dengan field kosong atau metode baru tetap terurai`() = runTest {
        enqueue(
            200,
            """{"data":[{"order_number":"CO-UJI0000009","status":null,"fulfilment_method":"drone","total":"0.00",
                "item_count":-3,"first_item_name":"  ","created_at":"kemarin","updated_at":null}],
                "meta":{"current_page":1,"per_page":20,"total":1,"last_page":1}}""",
        )

        val order = repository.orders(page = 1).value().items.single()

        assertEquals(OrderStatus.Unknown, order.status)
        assertNull(order.fulfilmentMethod)
        assertEquals(0, order.itemCount)
        assertNull(order.firstItemName)
        assertNull("Tanggal yang tidak terbaca hanya menghilangkan tanggal", order.createdAt)
        assertNull(order.updatedAt)
    }

    @Test
    fun `daftar kosong terurai sebagai halaman tanpa isi`() = runTest {
        enqueue(200, """{"data":[],"meta":{"current_page":1,"per_page":20,"total":0,"last_page":1}}""")

        val page = repository.orders(page = 1).value()

        assertTrue(page.items.isEmpty())
        assertFalse(page.page.hasMore)
    }

    @Test
    fun `uang yang bukan desimal polos menggagalkan panggilan tanpa angka karangan`() = runTest {
        enqueue(
            200,
            """{"data":[{${summaryJson(total = "Rp55.000")}}],"meta":{"current_page":1,"per_page":20,"total":1,"last_page":1}}""",
        )

        assertEquals(ApiError.Unknown, repository.orders(page = 1).error())
    }

    // --- detail ---

    @Test
    fun `detail memetakan uang kontak alamat barang pengiriman dan linimasa`() = runTest {
        enqueue(200, detailJson())

        val order = repository.order("CO-UJI0000001").value()

        val request = server.takeRequest()
        assertEquals("GET", request.method)
        assertEquals("/api/v1/customer/orders/CO-UJI0000001", request.url.encodedPath)
        assertEquals("Bearer $FAKE_TOKEN", request.headers["Authorization"])

        assertEquals("CO-UJI0000001", order.summary.orderNumber)
        assertEquals(OrderStatus.Shipped, order.summary.status)
        assertEquals(FulfilmentMethod.Courier, order.summary.fulfilmentMethod)
        assertEquals(BigDecimal("67000.00"), order.summary.total)
        assertEquals(BigDecimal("55000.00"), order.subtotal)
        assertEquals(BigDecimal("12000.00"), order.shippingCost)
        assertFalse(order.shippingCostPending)
        assertEquals("Pelanggan Uji", order.contact.name)
        assertEquals("0800-0000-0000", order.contact.phone)
        assertEquals("pelanggan.uji@example.test", order.contact.email)
        val address = order.shippingAddress!!
        assertEquals("Penerima Uji", address.recipientName)
        assertEquals("Jl. Contoh No. 1", address.addressLine)
        assertEquals("Kota Uji", address.city)
        assertEquals("Provinsi Uji", address.province)
        assertNull(address.postalCode)
        assertEquals("Pagar hijau", address.notes)
        assertEquals("Tolong dibungkus rapi.", order.notes)
        assertNull(order.cancelReason)
        assertEquals("Pesanan dikirim pada hari kerja.", order.instructions)

        assertEquals(2, order.items.size)
        val item = order.items.first()
        assertEquals("Gantungan Kunci Uji — Kecil", item.name)
        assertEquals("UJI0001", item.sku)
        assertEquals(BigDecimal("15000.00"), item.unitPrice)
        assertEquals(2, item.quantity)
        assertEquals(BigDecimal("30000.00"), item.lineTotal)

        val shipment = order.shipment!!
        assertEquals("JNE", shipment.courierName)
        assertEquals("REG", shipment.serviceName)
        assertEquals("UJI0000000001", shipment.trackingNumber)
        assertEquals("https://kurir.example.test/lacak?resi=UJI0000000001", shipment.trackingUrl)
        assertEquals("https://kurir.example.test/lacak?resi=UJI0000000001", shipment.trackingLink)
        assertEquals(Instant.parse("2026-11-12T06:00:00Z"), shipment.dispatchedAt)
        assertNull(shipment.deliveredAt)
        assertEquals(BigDecimal("12000.00"), shipment.shippingCost)

        // Terbaru lebih dulu, dengan jenis peristiwa dan catatan apa adanya.
        assertEquals(
            listOf(OrderEventType.TrackingUpdated, OrderEventType.StatusChanged, OrderEventType.ShippingCostSet, OrderEventType.StatusChanged),
            order.timeline.map { it.type },
        )
        assertEquals(
            listOf(OrderStatus.Shipped, OrderStatus.Shipped, OrderStatus.Confirmed, OrderStatus.AwaitingConfirmation),
            order.timeline.map { it.status },
        )
        assertEquals("Paket diserahkan ke kurir.", order.timeline[1].note)
        assertNull(order.timeline[0].note)
        assertEquals(Instant.parse("2026-11-12T07:00:00Z"), order.timeline.first().occurredAt)
    }

    @Test
    fun `detail pesanan ambil di toko tanpa pengiriman dan tanpa alamat`() = runTest {
        enqueue(
            200,
            detailJson(
                status = "awaiting_confirmation",
                method = "pickup",
                shipment = "null",
                address = "null",
                timeline = """[{"status":"awaiting_confirmation","event_type":"status_changed","note":null,
                    "occurred_at":"2026-11-12T10:00:00+07:00"}]""",
            ),
        )

        val order = repository.order("CO-UJI0000001").value()

        assertNull(order.shipment)
        assertNull(order.shippingAddress)
        assertEquals(FulfilmentMethod.Pickup, order.summary.fulfilmentMethod)
        assertEquals(1, order.timeline.size)
    }

    @Test
    fun `pengiriman dengan field pelacakan null tidak menawarkan nomor resi maupun tautan`() = runTest {
        enqueue(
            200,
            detailJson(
                shipment = """{"courier_name":"JNE","service_name":null,"tracking_number":null,"tracking_url":null,
                    "dispatched_at":"2026-11-12T13:00:00+07:00","delivered_at":null,"shipping_cost":"12000.00"}""",
            ),
        )

        val shipment = repository.order("CO-UJI0000001").value().shipment!!

        assertEquals("JNE", shipment.courierName)
        assertNull(shipment.serviceName)
        assertNull(shipment.trackingNumber)
        assertNull(shipment.trackingUrl)
        assertNull(shipment.trackingLink)
        assertNotNull(shipment.dispatchedAt)
        assertNull(shipment.deliveredAt)
    }

    @Test
    fun `tautan pelacakan yang bukan https tetap terurai tetapi tidak pernah ditawarkan`() = runTest {
        listOf("http://kurir.example.test/lacak?resi=1", "javascript:alert(1)", "bukan tautan").forEach { url ->
            enqueue(
                200,
                detailJson(
                    shipment = """{"courier_name":"JNE","service_name":"REG","tracking_number":"UJI0000000001",
                        "tracking_url":"$url","dispatched_at":"2026-11-12T13:00:00+07:00","delivered_at":null,
                        "shipping_cost":"12000.00"}""",
                ),
            )

            val shipment = repository.order("CO-UJI0000001").value().shipment!!

            assertEquals("UJI0000000001", shipment.trackingNumber)
            assertNull("tidak boleh ditawarkan: $url", shipment.trackingLink)
        }
    }

    @Test
    fun `linimasa yang tiba tidak berurutan ditampilkan terbaru lebih dulu`() = runTest {
        enqueue(
            200,
            detailJson(
                timeline = """[
                    {"status":"awaiting_confirmation","event_type":"status_changed","note":null,"occurred_at":"2026-11-12T10:00:00+07:00"},
                    {"status":"packing","event_type":"status_changed","note":null,"occurred_at":"2026-11-12T12:00:00+07:00"},
                    {"status":"confirmed","event_type":"status_changed","note":null,"occurred_at":"2026-11-12T11:00:00+07:00"}]""",
            ),
        )

        val timeline = repository.order("CO-UJI0000001").value().timeline

        assertEquals(listOf(OrderStatus.Packing, OrderStatus.Confirmed, OrderStatus.AwaitingConfirmation), timeline.map { it.status })
    }

    @Test
    fun `peristiwa dengan jenis atau status baru dan waktu tak terbaca tetap terurai dalam urutan server`() = runTest {
        enqueue(
            200,
            detailJson(
                timeline = """[
                    {"status":"returned","event_type":"refund_issued","note":"  ","occurred_at":"2026-11-12T12:00:00+07:00"},
                    {"status":"shipped","event_type":"status_changed","note":null,"occurred_at":"entah kapan"},
                    {"status":"confirmed"}]""",
            ),
        )

        val timeline = repository.order("CO-UJI0000001").value().timeline

        assertEquals(listOf(OrderStatus.Unknown, OrderStatus.Shipped, OrderStatus.Confirmed), timeline.map { it.status })
        assertEquals(listOf(OrderEventType.Unknown, OrderEventType.StatusChanged, OrderEventType.Unknown), timeline.map { it.type })
        assertNull(timeline[0].note)
        assertNull(timeline[1].occurredAt)
        assertNull(timeline[2].occurredAt)
    }

    @Test
    fun `detail yang dibatalkan membawa alasan dan ongkos kirim yang belum ditetapkan`() = runTest {
        enqueue(
            200,
            """{"data":{${summaryJson(status = "cancelled", method = "courier")},
                "subtotal":"55000.00","shipping_cost":"0.00","shipping_cost_pending":true,
                "contact":{"name":"Pelanggan Uji","phone":"0800-0000-0000","email":"pelanggan.uji@example.test"},
                "shipping_address":null,"notes":null,"cancel_reason":"Stok habis.","instructions":null,
                "items":[],"shipment":null,"timeline":[]}}""",
        )

        val order = repository.order("CO-UJI0000001").value()

        assertEquals(OrderStatus.Cancelled, order.summary.status)
        assertEquals("Stok habis.", order.cancelReason)
        assertTrue(order.shippingCostPending)
        assertEquals(BigDecimal("0.00"), order.shippingCost)
        assertNull(order.instructions)
        assertNull(order.notes)
        assertTrue(order.items.isEmpty())
        assertTrue(order.timeline.isEmpty())
    }

    @Test
    fun `model pesanan tidak membocorkan kontak dan alamat lewat toString`() = runTest {
        enqueue(200, detailJson())

        val printed = repository.order("CO-UJI0000001").value().toString()

        // "Tolong dibungkus rapi." = catatan bebas yang diketik pelanggan, bisa berisi apa saja.
        listOf(
            "Pelanggan Uji", "0800-0000-0000", "pelanggan.uji@example.test", "Penerima Uji", "Jl. Contoh No. 1",
            "Pagar hijau", "Tolong dibungkus rapi.",
        ).forEach { secret -> assertFalse("bocor: $secret", printed.contains(secret)) }
    }

    // --- pelacakan ---

    @Test
    fun `pelacakan membawa tanda diperbarui oleh toko pengiriman dan linimasa`() = runTest {
        enqueue(
            200,
            """{"data":{"order_number":"CO-UJI0000001","status":"shipped","fulfilment_method":"courier",
                "updated_by":"store","shipment":$shipmentJson,"timeline":$timelineJson}}""",
        )

        val tracking = repository.tracking("CO-UJI0000001").value()

        val request = server.takeRequest()
        assertEquals("GET", request.method)
        assertEquals("/api/v1/customer/orders/CO-UJI0000001/tracking", request.url.encodedPath)
        assertEquals("Bearer $FAKE_TOKEN", request.headers["Authorization"])
        assertEquals("CO-UJI0000001", tracking.orderNumber)
        assertEquals(OrderStatus.Shipped, tracking.status)
        assertEquals(FulfilmentMethod.Courier, tracking.fulfilmentMethod)
        assertTrue(tracking.updatedByStore)
        assertEquals("UJI0000000001", tracking.shipment?.trackingNumber)
        assertEquals(4, tracking.timeline.size)
        assertEquals(OrderEventType.TrackingUpdated, tracking.timeline.first().type)
    }

    @Test
    fun `pelacakan tanpa pengiriman dan dengan sumber lain tidak mengaku diperbarui oleh toko`() = runTest {
        enqueue(
            200,
            """{"data":{"order_number":"CO-UJI0000001","status":"confirmed","fulfilment_method":"pickup",
                "updated_by":"carrier","shipment":null,"timeline":[]}}""",
        )
        enqueue(
            200,
            """{"data":{"order_number":"CO-UJI0000001","status":"confirmed","fulfilment_method":"pickup",
                "shipment":null,"timeline":[]}}""",
        )

        val fromCarrier = repository.tracking("CO-UJI0000001").value()
        val withoutSource = repository.tracking("CO-UJI0000001").value()

        assertFalse(fromCarrier.updatedByStore)
        assertNull(fromCarrier.shipment)
        assertTrue(fromCarrier.timeline.isEmpty())
        assertFalse(withoutSource.updatedByStore)
    }

    // --- galat ---

    @Test
    fun `pesanan tidak ada atau milik akun lain dijawab not_found`() = runTest {
        enqueue(404, problem(404, ApiErrorCodes.NOT_FOUND))
        enqueue(404, problem(404, ApiErrorCodes.NOT_FOUND))

        val detail = repository.order("CO-BUKANMILIK").error() as ApiError.Http
        val tracking = repository.tracking("CO-BUKANMILIK").error() as ApiError.Http

        assertEquals(404, detail.status)
        assertEquals(ApiErrorCodes.NOT_FOUND, detail.code)
        assertEquals("req-uji-0001", detail.requestId)
        assertEquals(ApiErrorCodes.NOT_FOUND, tracking.code)
    }

    @Test
    fun `token ditolak dijawab unauthenticated untuk daftar detail dan pelacakan`() = runTest {
        repeat(3) { enqueue(401, problem(401, ApiErrorCodes.UNAUTHENTICATED)) }

        assertTrue(repository.orders(page = 1).error().isUnauthenticated)
        assertTrue(repository.order("CO-UJI0000001").error().isUnauthenticated)
        assertTrue(repository.tracking("CO-UJI0000001").error().isUnauthenticated)
    }

    @Test
    fun `instalasi terkunci dan galat server dipetakan tanpa membawa body mentah`() = runTest {
        enqueue(423, """{"message":"Belum diaktivasi","activated":false}""")
        enqueue(500, problem(500, ApiErrorCodes.SERVER_ERROR))

        val locked = repository.orders(page = 1).error()
        val failed = repository.order("CO-UJI0000001").error() as ApiError.Http

        assertTrue(locked.isStoreUnavailable)
        assertEquals(ApiErrorCodes.SERVER_ERROR, failed.code)
    }

    @Test
    fun `koneksi terputus menjadi galat jaringan dan jawaban tak terbaca menjadi galat umum`() = runTest {
        server.enqueue(MockResponse.Builder().onResponseStart(SocketEffect.CloseSocket()).build())
        enqueue(200, """{"data":"bukan objek pesanan"}""")
        enqueue(200, "<html>bukan json</html>")

        assertEquals(ApiError.Network, repository.orders(page = 1).error())
        assertEquals(ApiError.Unknown, repository.order("CO-UJI0000001").error())
        assertEquals(ApiError.Unknown, repository.tracking("CO-UJI0000001").error())
    }

    private companion object {
        const val FAKE_TOKEN = "tes-token-palsu-123"
    }
}
