package com.boothpos.customer.feature.checkout.data

import com.boothpos.customer.core.data.domain.model.FulfilmentMethod
import com.boothpos.customer.core.network.HttpClientFactory
import com.boothpos.customer.core.network.NetworkConfig
import com.boothpos.customer.core.network.NetworkJson
import com.boothpos.customer.core.network.RetrofitFactory
import com.boothpos.customer.core.network.UnauthorizedEvents
import com.boothpos.customer.core.network.error.ApiError
import com.boothpos.customer.core.network.error.ApiErrorCodes
import com.boothpos.customer.core.network.error.ProblemJsonParser
import com.boothpos.customer.core.network.error.SafeApiCall
import com.boothpos.customer.core.network.error.isStoreUnavailable
import com.boothpos.customer.core.network.error.isUnauthenticated
import com.boothpos.customer.feature.checkout.domain.CartIssue
import com.boothpos.customer.feature.checkout.domain.CartIssueReason
import com.boothpos.customer.feature.checkout.domain.CheckoutItem
import com.boothpos.customer.feature.checkout.domain.CheckoutRequest
import com.boothpos.customer.feature.checkout.domain.PlaceOrderOutcome
import com.boothpos.customer.feature.checkout.domain.QuoteOutcome
import java.math.BigDecimal
import kotlinx.coroutines.test.runTest
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.jsonArray
import kotlinx.serialization.json.jsonObject
import mockwebserver3.MockResponse
import mockwebserver3.MockWebServer
import mockwebserver3.RecordedRequest
import mockwebserver3.SocketEffect
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test

/** Kontrak §8 (`POST /customer/orders/quote`, `POST /customer/orders`) terhadap MockWebServer. */
class CheckoutRepositoryImplTest {

    private val server = MockWebServer()
    private val json = NetworkJson.create()
    private lateinit var repository: CheckoutRepositoryImpl

    // Data contoh yang jelas-jelas palsu.
    private val pickup = CheckoutRequest(
        method = FulfilmentMethod.Pickup,
        addressId = null,
        contactName = " Pelanggan Uji ",
        contactPhone = "0800-0000-0000",
        items = listOf(CheckoutItem(31, 2), CheckoutItem(32, 1)),
        notes = null,
    )
    private val courier = pickup.copy(method = FulfilmentMethod.Courier, addressId = 5, notes = " Tolong dibungkus rapi. ")

    private fun quoteJson(unitPrice: String = "15000.00", total: String = "55000.00", pending: Boolean = false) =
        """{"fulfilment_method":"pickup","lines":[
            {"variant_id":31,"product_slug":"gantungan-kunci-uji","name":"Gantungan Kunci Uji — Kecil","image_url":null,
             "unit_price":"$unitPrice","qty":2,"line_total":"30000.00","max_qty":3,"weight":10},
            {"variant_id":32,"product_slug":"stiker-uji","name":"Stiker Uji — A5",
             "image_url":"https://cdn.example.test/v32.png","unit_price":"25000.00","qty":1,"line_total":"25000.00","max_qty":5}],
           "subtotal":"$total","shipping_cost":"0.00","shipping_cost_pending":$pending,"total":"$total","promo":null}"""

    private val orderJson = """{"data":{"order_number":"CO-UJI0000001","status":"awaiting_confirmation",
        "fulfilment_method":"pickup","total":"55000.00","item_count":3,"items":[],"timeline":[]}}"""

    @Before
    fun setUp() {
        server.start()
        val config = NetworkConfig(server.url("/api/v1/").toString())
        val client = HttpClientFactory.create(config, { FAKE_TOKEN }, UnauthorizedEvents())
        val api = RetrofitFactory.create(config, json, client).create(CheckoutApi::class.java)
        repository = CheckoutRepositoryImpl(api, SafeApiCall(ProblemJsonParser(json)), json)
    }

    @After
    fun tearDown() = server.close()

    private fun enqueue(code: Int, body: String) = server.enqueue(MockResponse.Builder().code(code).body(body).build())

    private fun problem(status: Int, code: String, errors: String? = null): String {
        val errorsPart = if (errors == null) "" else ",\"errors\":$errors"
        return """{"type":"https://boothpos.local/problems/$code","title":"Galat","status":$status,
            "detail":"Pesan aman dari server.","code":"$code"$errorsPart,"request_id":"req-uji-0001"}"""
    }

    private fun RecordedRequest.jsonBody(): JsonObject = json.parseToJsonElement(body!!.utf8()).jsonObject

    // --- quote ---

    @Test
    fun `quote mengirim hanya varian dan jumlah lalu memetakan angka server`() = runTest {
        enqueue(200, """{"data":${quoteJson()}}""")

        val outcome = repository.quote(pickup)

        val quote = (outcome as QuoteOutcome.Success).quote
        assertEquals(FulfilmentMethod.Pickup, quote.method)
        assertEquals(BigDecimal("55000.00"), quote.subtotal)
        assertEquals(BigDecimal("0.00"), quote.shippingCost)
        assertFalse(quote.shippingCostPending)
        assertEquals(BigDecimal("55000.00"), quote.total)
        val line = quote.lines.first()
        assertEquals(31L, line.variantId)
        assertEquals("gantungan-kunci-uji", line.productSlug)
        assertEquals(BigDecimal("15000.00"), line.unitPrice)
        assertEquals(2, line.quantity)
        assertEquals(BigDecimal("30000.00"), line.lineTotal)
        assertEquals(3, line.maxQuantity)
        assertNull(line.imageUrl)

        val request = server.takeRequest()
        assertEquals("POST", request.method)
        assertEquals("/api/v1/customer/orders/quote", request.url.encodedPath)
        assertEquals("Bearer $FAKE_TOKEN", request.headers["Authorization"])
        assertNull("Quote tidak membawa kunci idempotensi", request.headers["Idempotency-Key"])
        val body = request.jsonBody()
        // Ambil di toko tanpa catatan: tidak ada address_id, notes, expected_total, atau angka uang apa pun.
        assertEquals(setOf("fulfilment_method", "contact", "items"), body.keys)
        assertEquals("\"pickup\"", body["fulfilment_method"].toString())
        assertEquals(setOf("name", "phone"), body.getValue("contact").jsonObject.keys)
        assertEquals("\"Pelanggan Uji\"", body.getValue("contact").jsonObject["name"].toString())
        val items = body.getValue("items").jsonArray.map { it.jsonObject }
        assertEquals(listOf(setOf("variant_id", "qty"), setOf("variant_id", "qty")), items.map { it.keys })
        assertEquals(listOf("31", "32"), items.map { it["variant_id"].toString() })
        assertEquals(listOf("2", "1"), items.map { it["qty"].toString() })
    }

    @Test
    fun `quote kurir membawa alamat dan catatan serta ongkos kirim yang menunggu konfirmasi`() = runTest {
        enqueue(200, """{"data":${quoteJson(pending = true)}}""")

        val quote = (repository.quote(courier) as QuoteOutcome.Success).quote

        assertTrue(quote.shippingCostPending)
        val body = server.takeRequest().jsonBody()
        assertEquals(setOf("fulfilment_method", "address_id", "contact", "items", "notes"), body.keys)
        assertEquals("5", body["address_id"].toString())
        assertEquals("\"Tolong dibungkus rapi.\"", body["notes"].toString())
    }

    @Test
    fun `cart_invalid pada quote memetakan baris bermasalah dari errors items`() = runTest {
        enqueue(
            409,
            problem(
                409,
                "cart_invalid",
                """{"items":[{"variant_id":31,"reason":"insufficient_stock","max_qty":1},
                    {"variant_id":32,"reason":"unavailable","max_qty":0},
                    {"variant_id":33,"reason":"qty_limit","max_qty":20},
                    {"variant_id":34,"reason":"alasan_baru","max_qty":2}]}""",
            ),
        )

        val outcome = repository.quote(pickup)

        assertEquals(
            QuoteOutcome.CartInvalid(
                listOf(
                    CartIssue(31, CartIssueReason.InsufficientStock, 1),
                    CartIssue(32, CartIssueReason.Unavailable, 0),
                    CartIssue(33, CartIssueReason.QuantityLimit, 20),
                    // Alasan yang belum dikenal aplikasi lama tetap ditampilkan menurut batasnya.
                    CartIssue(34, CartIssueReason.InsufficientStock, 2),
                ),
            ),
            outcome,
        )
    }

    @Test
    fun `harga yang bukan desimal sah menggagalkan quote alih-alih menampilkan angka karangan`() = runTest {
        enqueue(200, """{"data":${quoteJson(unitPrice = "lima belas ribu")}}""")

        assertEquals(QuoteOutcome.Failure(ApiError.Unknown), repository.quote(pickup))
    }

    @Test
    fun `quote meneruskan sesi berakhir dan toko tidak tersedia sebagai galat umum`() = runTest {
        enqueue(401, problem(401, "unauthenticated"))
        enqueue(503, problem(503, "customer_commerce_disabled"))
        server.enqueue(MockResponse.Builder().code(423).body("""{"message":"Terkunci","activated":false}""").build())

        val expired = (repository.quote(pickup) as QuoteOutcome.Failure).error
        val disabled = (repository.quote(pickup) as QuoteOutcome.Failure).error
        val locked = (repository.quote(pickup) as QuoteOutcome.Failure).error

        assertTrue(expired.isUnauthenticated)
        assertTrue(disabled.isStoreUnavailable)
        assertTrue(locked.isStoreUnavailable)
    }

    // --- buat pesanan ---

    @Test
    fun `buat pesanan mengirim Idempotency-Key dan expected_total sebagai satu-satunya angka uang`() = runTest {
        enqueue(201, orderJson)

        val outcome = repository.placeOrder(courier, BigDecimal("55000"), KEY)

        assertEquals("CO-UJI0000001", (outcome as PlaceOrderOutcome.Placed).order.orderNumber)
        val request = server.takeRequest()
        assertEquals("POST", request.method)
        assertEquals("/api/v1/customer/orders", request.url.encodedPath)
        assertEquals(KEY, request.headers["Idempotency-Key"])
        assertEquals("Bearer $FAKE_TOKEN", request.headers["Authorization"])
        val body = request.jsonBody()
        assertEquals(
            setOf("fulfilment_method", "address_id", "contact", "items", "notes", "expected_total"),
            body.keys,
        )
        // Bentuk kawat uang: string desimal berskala 2.
        assertEquals("\"55000.00\"", body["expected_total"].toString())
        assertEquals("\"courier\"", body["fulfilment_method"].toString())
        // Tidak ada harga, subtotal, ongkos kirim, atau total lain di mana pun dalam body.
        val raw = request.body!!.utf8()
        listOf("price", "subtotal", "line_total", "shipping", "\"total\"").forEach { forbidden ->
            assertFalse("Body tidak boleh memuat $forbidden", raw.contains(forbidden))
        }
        assertEquals(1, Regex("total").findAll(raw).count())
    }

    @Test
    fun `jawaban putar ulang 200 untuk kunci yang sama diperlakukan sama dengan 201`() = runTest {
        enqueue(200, orderJson)

        val outcome = repository.placeOrder(pickup, BigDecimal("55000.00"), KEY)

        assertEquals("CO-UJI0000001", (outcome as PlaceOrderOutcome.Placed).order.orderNumber)
        assertEquals(1, server.requestCount)
    }

    @Test
    fun `price_changed membawa quote baru dari errors quote`() = runTest {
        enqueue(409, problem(409, "price_changed", """{"quote":${quoteJson(unitPrice = "17500.00", total = "60000.00")}}"""))
        enqueue(409, problem(409, "price_changed", """{"quote":{"lines":"rusak"}}"""))

        val changed = repository.placeOrder(pickup, BigDecimal("55000.00"), KEY) as PlaceOrderOutcome.PriceChanged
        val unreadable = repository.placeOrder(pickup, BigDecimal("55000.00"), KEY) as PlaceOrderOutcome.PriceChanged

        assertEquals(BigDecimal("60000.00"), changed.quote?.total)
        assertEquals(BigDecimal("17500.00"), changed.quote?.lines?.first()?.unitPrice)
        // Quote yang tak terbaca bukan galat fatal: pemanggil meminta quote baru.
        assertNull(unreadable.quote)
    }

    @Test
    fun `kode khusus checkout dipetakan ke hasilnya masing-masing`() = runTest {
        enqueue(409, problem(409, "cart_invalid", """{"items":[{"variant_id":31,"reason":"qty_limit","max_qty":20}]}"""))
        enqueue(409, problem(409, "idempotency_key_reused"))
        enqueue(422, problem(422, "idempotency_key_required"))
        enqueue(422, problem(422, "validation_failed", """{"address_id":["Alamat tidak ditemukan."]}"""))
        enqueue(429, problem(429, "too_many_requests"))

        val invalid = repository.placeOrder(pickup, BigDecimal("55000.00"), KEY)
        val reused = repository.placeOrder(pickup, BigDecimal("55000.00"), KEY)
        val required = repository.placeOrder(pickup, BigDecimal("55000.00"), KEY) as PlaceOrderOutcome.Failure
        val validation = repository.placeOrder(pickup, BigDecimal("55000.00"), KEY) as PlaceOrderOutcome.Failure
        val throttled = repository.placeOrder(pickup, BigDecimal("55000.00"), KEY) as PlaceOrderOutcome.Failure

        assertEquals(
            PlaceOrderOutcome.CartInvalid(listOf(CartIssue(31, CartIssueReason.QuantityLimit, 20))),
            invalid,
        )
        assertEquals(PlaceOrderOutcome.IdempotencyKeyReused, reused)
        assertEquals(ApiErrorCodes.IDEMPOTENCY_KEY_REQUIRED, (required.error as ApiError.Http).code)
        assertEquals(
            mapOf("address_id" to listOf("Alamat tidak ditemukan.")),
            (validation.error as ApiError.Http).fieldErrors,
        )
        assertEquals(ApiErrorCodes.TOO_MANY_REQUESTS, (throttled.error as ApiError.Http).code)
    }

    @Test
    fun `koneksi yang putus setelah permintaan terkirim tidak diulang otomatis`() = runTest {
        server.enqueue(MockResponse.Builder().onResponseStart(SocketEffect.CloseSocket()).build())
        enqueue(201, orderJson)

        val outcome = repository.placeOrder(pickup, BigDecimal("55000.00"), KEY)

        // Hasil di server tidak diketahui; keputusan mengirim ulang (dengan kunci yang sama) milik pemanggil.
        val error = (outcome as PlaceOrderOutcome.Failure).error
        assertTrue(error is ApiError.Network || error is ApiError.Timeout)
        assertEquals("Tepat satu permintaan yang sampai ke server", 1, server.requestCount)
        assertEquals(KEY, server.takeRequest().headers["Idempotency-Key"])
    }

    @Test
    fun `jawaban sukses yang tidak terbaca dan galat server dilaporkan tanpa detail internal`() = runTest {
        enqueue(201, """{"data":{"status":"awaiting_confirmation"}}""")
        enqueue(500, problem(500, "server_error"))

        val unreadable = repository.placeOrder(pickup, BigDecimal("55000.00"), KEY)
        val serverError = (repository.placeOrder(pickup, BigDecimal("55000.00"), KEY) as PlaceOrderOutcome.Failure).error

        assertEquals(PlaceOrderOutcome.Failure(ApiError.Unknown), unreadable)
        assertEquals(500, (serverError as ApiError.Http).status)
        assertEquals(ApiErrorCodes.SERVER_ERROR, serverError.code)
    }

    private companion object {
        const val FAKE_TOKEN = "tes-token-palsu-123"
        const val KEY = "kunci-uji-0001-abcdefgh"
    }
}
