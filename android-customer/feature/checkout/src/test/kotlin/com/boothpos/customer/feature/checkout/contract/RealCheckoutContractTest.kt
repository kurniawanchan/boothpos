package com.boothpos.customer.feature.checkout.contract

import com.boothpos.customer.core.data.domain.model.FulfilmentMethod
import com.boothpos.customer.core.data.testing.RealPayloads
import com.boothpos.customer.core.network.HttpClientFactory
import com.boothpos.customer.core.network.NetworkConfig
import com.boothpos.customer.core.network.NetworkJson
import com.boothpos.customer.core.network.RetrofitFactory
import com.boothpos.customer.core.network.UnauthorizedEvents
import com.boothpos.customer.core.network.dto.DataEnvelope
import com.boothpos.customer.core.network.dto.ProblemDto
import com.boothpos.customer.core.network.error.ApiError
import com.boothpos.customer.core.network.error.ApiErrorCodes
import com.boothpos.customer.core.network.error.ProblemJsonParser
import com.boothpos.customer.core.network.error.SafeApiCall
import com.boothpos.customer.core.network.error.isStoreUnavailable
import com.boothpos.customer.feature.checkout.data.CartIssueDto
import com.boothpos.customer.feature.checkout.data.CheckoutApi
import com.boothpos.customer.feature.checkout.data.CheckoutRepositoryImpl
import com.boothpos.customer.feature.checkout.data.QuoteDto
import com.boothpos.customer.feature.checkout.domain.CartIssue
import com.boothpos.customer.feature.checkout.domain.CartIssueReason
import com.boothpos.customer.feature.checkout.domain.CheckoutItem
import com.boothpos.customer.feature.checkout.domain.CheckoutRequest
import com.boothpos.customer.feature.checkout.domain.PlaceOrderOutcome
import com.boothpos.customer.feature.checkout.domain.PlacedOrder
import com.boothpos.customer.feature.checkout.domain.Quote
import com.boothpos.customer.feature.checkout.domain.QuoteLine
import com.boothpos.customer.feature.checkout.domain.QuoteOutcome
import java.math.BigDecimal
import kotlinx.coroutines.test.runTest
import kotlinx.serialization.builtins.ListSerializer
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.jsonObject
import mockwebserver3.MockWebServer
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test

/**
 * Kontrak §8 (`POST /customer/orders/quote`, `POST /customer/orders`) dan §3 terhadap jawaban
 * NYATA backend, lewat Retrofit + converter + `Json` milik :core:network dan
 * `CheckoutRepositoryImpl`. Berkas ada di `src/test/resources/real` (lihat README di sana). Telepon
 * dan e-mail sudah disamarkan.
 *
 * Dua bentuk tidak pernah terekam dan diturunkan dari kode PHP (`derived/`, lihat README di sana),
 * bukan dikarang: 409 `price_changed` (`CustomerCheckoutService::place` melempar
 * `errors.quote` berisi quote yang sama bentuknya dengan jawaban quote) dan 503
 * `customer_commerce_disabled` (`EnsureCustomerCommerceEnabled`).
 */
class RealCheckoutContractTest {

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
    private lateinit var repository: CheckoutRepositoryImpl

    // Isi keranjang + formulir yang menghasilkan PERSIS permintaan rekaman #36 (kurir, 2 unit varian 1).
    private val courier = CheckoutRequest(
        method = FulfilmentMethod.Courier,
        addressId = 1,
        contactName = "Pelanggan Bruno A",
        contactPhone = "0800****0011",
        items = listOf(CheckoutItem(variantId = 1, quantity = 2)),
        notes = "Mohon dibungkus rapi.",
    )

    private val pickupOfUnavailable = CheckoutRequest(
        method = FulfilmentMethod.Pickup,
        addressId = null,
        contactName = "Pelanggan Bruno A",
        contactPhone = "0800****0011",
        items = listOf(CheckoutItem(variantId = 2, quantity = 1)),
        notes = null,
    )

    private val total = BigDecimal("150000.00")

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

    private fun problem(status: Int, code: String, detail: String, requestId: String, fieldErrors: Map<String, List<String>> = emptyMap()) =
        ApiError.Http(status = status, code = code, detail = detail, fieldErrors = fieldErrors, requestId = requestId)

    // --- 200 quote ---

    @Test
    fun `200 quote kurir nyata - baris, uang BigDecimal persis, ongkir tertunda`() = runTest {
        val recorded = RealPayloads.recorded("quote_courier_200")
        assertEquals(200, recorded.status)
        server.enqueueRecorded(recorded)

        val outcome = repository.quote(courier.copy(notes = null))

        val quote = (outcome as QuoteOutcome.Success).quote
        assertEquals(
            Quote(
                method = FulfilmentMethod.Courier,
                lines = listOf(
                    QuoteLine(
                        variantId = 1,
                        productSlug = "etalase-bruno-1794456911541",
                        name = "Etalase Bruno 1794456911541 — Ukuran S",
                        imageUrl = null,
                        unitPrice = BigDecimal("75000.00"),
                        quantity = 2,
                        lineTotal = total,
                        maxQuantity = 10,
                    ),
                ),
                subtotal = total,
                shippingCost = BigDecimal("0.00"),
                shippingCostPending = true,
                total = total,
            ),
            quote,
        )

        val request = server.takeRequest()
        assertEquals("POST", request.method)
        assertEquals("/api/v1/customer/orders/quote", request.url.encodedPath)
        assertEquals("Bearer $FAKE_TOKEN", request.headers["Authorization"])
        assertNull("Quote tidak memakai kunci idempotensi", request.headers["Idempotency-Key"])
        // Tidak ada angka uang di permintaan quote.
        assertEquals(
            setOf("fulfilment_method", "address_id", "contact", "items"),
            json.parseToJsonElement(request.body!!.utf8()).jsonObject.keys,
        )
    }

    // --- 409 cart_invalid pada quote ---

    @Test
    fun `409 cart_invalid nyata pada quote - varian stok nol tidak tersedia`() = runTest {
        val recorded = RealPayloads.recorded("quote_zero_stock_409_cart_invalid")
        assertEquals(409, recorded.status)
        server.enqueueRecorded(recorded)

        val outcome = repository.quote(pickupOfUnavailable)

        assertEquals(
            QuoteOutcome.CartInvalid(
                listOf(CartIssue(variantId = 2, reason = CartIssueReason.Unavailable, maxQuantity = 0)),
            ),
            outcome,
        )
    }

    // --- 201 / 200 buat pesanan ---

    @Test
    fun `201 buat pesanan kurir nyata - nomor pesanan terbaca dan permintaan sama dengan rekaman`() = runTest {
        val recorded = RealPayloads.recorded("order_create_courier_201")
        assertEquals(201, recorded.status)
        server.enqueueRecorded(recorded)

        val outcome = repository.placeOrder(courier, total, KEY)

        assertEquals(PlaceOrderOutcome.Placed(PlacedOrder("CO-V1NKWSJQCN")), outcome)
        val request = server.takeRequest()
        assertEquals("POST", request.method)
        assertEquals("/api/v1/customer/orders", request.url.encodedPath)
        assertEquals(KEY, request.headers["Idempotency-Key"])
        assertEquals("Bearer $FAKE_TOKEN", request.headers["Authorization"])
        // Kunci dan nilai yang dikirim aplikasi = permintaan yang benar-benar diterima backend (#36),
        // termasuk `expected_total` sebagai string desimal.
        assertEquals(
            json.parseToJsonElement(RealPayloads.recordedRequest("order_create_courier_201_request")),
            json.parseToJsonElement(request.body!!.utf8()),
        )
    }

    @Test
    fun `200 putar ulang kunci yang sama nyata - pesanan yang sama, bukan pesanan kedua`() = runTest {
        val recorded = RealPayloads.recorded("order_create_replay_200")
        assertEquals(200, recorded.status)
        server.enqueueRecorded(recorded)

        val outcome = repository.placeOrder(courier, total, KEY)

        assertEquals(PlaceOrderOutcome.Placed(PlacedOrder("CO-V1NKWSJQCN")), outcome)
    }

    @Test
    fun `201 buat pesanan ambil di toko nyata - shipping_address null tidak mengganggu`() = runTest {
        val recorded = RealPayloads.recorded("order_create_pickup_201")
        assertEquals(201, recorded.status)
        server.enqueueRecorded(recorded)

        val outcome = repository.placeOrder(
            pickupOfUnavailable.copy(items = listOf(CheckoutItem(1, 1)), notes = "Diambil saat acara hari pertama."),
            BigDecimal("75000.00"),
            "08b21841-1d8b-4625-8d7f-522d309a4c9a",
        )

        assertEquals(PlaceOrderOutcome.Placed(PlacedOrder("CO-YFQB1Q4KCG")), outcome)
        // Ambil di toko: `address_id` tidak dikirim.
        val body = json.parseToJsonElement(server.takeRequest().body!!.utf8()).jsonObject
        assertEquals(setOf("fulfilment_method", "contact", "items", "notes", "expected_total"), body.keys)
    }

    // --- galat 409 / 422 nyata ---

    @Test
    fun `409 idempotency_key_reused nyata - hasil khusus untuk kunci yang dipakai isi lain`() = runTest {
        val recorded = RealPayloads.recorded("order_create_key_reused_409_idempotency_key_reused")
        assertEquals(409, recorded.status)
        server.enqueueRecorded(recorded)

        val outcome = repository.placeOrder(courier.copy(notes = "Isi berbeda dengan kunci yang sama."), total, KEY)

        assertEquals(PlaceOrderOutcome.IdempotencyKeyReused, outcome)
    }

    @Test
    fun `422 idempotency_key_required nyata - galat biasa dengan kode yang dikenal`() = runTest {
        val recorded = RealPayloads.recorded("order_create_no_key_422_idempotency_key_required")
        assertEquals(422, recorded.status)
        server.enqueueRecorded(recorded)

        val outcome = repository.placeOrder(courier, total, KEY)

        assertEquals(
            PlaceOrderOutcome.Failure(
                problem(
                    status = 422,
                    code = ApiErrorCodes.IDEMPOTENCY_KEY_REQUIRED,
                    detail = "Permintaan pesanan harus menyertakan kunci permintaan yang valid.",
                    requestId = "1e55821d-c327-4279-8c2e-dfa4abf39339",
                ),
            ),
            outcome,
        )
    }

    @Test
    fun `422 validation_failed nyata - alamat milik akun lain dipetakan ke kolom address_id`() = runTest {
        val recorded = RealPayloads.recorded("order_create_foreign_address_422_validation_failed")
        assertEquals(422, recorded.status)
        server.enqueueRecorded(recorded)

        val outcome = repository.placeOrder(courier.copy(addressId = 2), BigDecimal("75000.00"), KEY)

        val failure = outcome as PlaceOrderOutcome.Failure
        assertEquals(
            problem(
                status = 422,
                code = ApiErrorCodes.VALIDATION_FAILED,
                detail = "Data yang dikirim tidak valid. Periksa kembali isian Anda.",
                requestId = "a44ef343-1186-4320-9df3-2c1a4b55c397",
                fieldErrors = mapOf(
                    "address_id" to listOf("Alamat pengiriman tidak valid. Pilih salah satu alamat tersimpan Anda."),
                ),
            ),
            (failure.error as ApiError.Http).copy(errors = null),
        )
    }

    // --- diturunkan dari PHP (bukan rekaman) ---

    @Test
    fun `409 price_changed (diturunkan dari PHP) - quote baru dari errors quote terbaca`() = runTest {
        val derived = RealPayloads.derived("price_changed_409")
        assertEquals(409, derived.status)
        server.enqueueRecorded(derived)

        val outcome = repository.placeOrder(courier, total, KEY)

        val quote = (outcome as PlaceOrderOutcome.PriceChanged).quote!!
        assertEquals(FulfilmentMethod.Courier, quote.method)
        assertEquals(BigDecimal("160000.00"), quote.total)
        assertEquals(BigDecimal("160000.00"), quote.subtotal)
        assertTrue(quote.shippingCostPending)
        val line = quote.lines.single()
        assertEquals(BigDecimal("80000.00"), line.unitPrice)
        assertEquals(BigDecimal("160000.00"), line.lineTotal)
        assertEquals(2, line.quantity)
        assertEquals(10, line.maxQuantity)
    }

    @Test
    fun `503 customer_commerce_disabled (diturunkan dari PHP) - penolakan pasti, toko tidak tersedia`() = runTest {
        val derived = RealPayloads.derived("customer_commerce_disabled_503")
        assertEquals(503, derived.status)
        server.enqueueRecorded(derived)
        server.enqueueRecorded(derived)

        val quoteFailure = (repository.quote(courier) as QuoteOutcome.Failure).error as ApiError.Http
        val placeFailure = (repository.placeOrder(courier, total, KEY) as PlaceOrderOutcome.Failure).error as ApiError.Http

        listOf(quoteFailure, placeFailure).forEach { error ->
            assertEquals(503, error.status)
            assertEquals(ApiErrorCodes.CUSTOMER_COMMERCE_DISABLED, error.code)
            assertEquals(3600L, error.retryAfterSeconds)
            assertTrue(error.isStoreUnavailable)
        }
    }

    // --- tidak ada field rekaman yang diabaikan diam-diam ---

    @Test
    fun `setiap field rekaman quote dan galat checkout dimodelkan DTO aplikasi`() {
        // `shipping_cost` ("0.00") dan `fulfilment_method` nyata sama dengan bawaan/cadangan aplikasi;
        // mode ketat membuktikan namanya dikenali. Jawaban buat-pesanan sengaja TIDAK diuji ketat:
        // aplikasi hanya memodelkan `order_number` (rincian pesanan milik fitur pesanan).
        strictDecode<DataEnvelope<QuoteDto>>("quote_courier_200")
        listOf(
            "quote_zero_stock_409_cart_invalid",
            "order_create_key_reused_409_idempotency_key_reused",
            "order_create_no_key_422_idempotency_key_required",
            "order_create_foreign_address_422_validation_failed",
        ).forEach { strictDecode<ProblemDto>(it) }
        strictDecode<ProblemDto>("price_changed_409", derived = true)
        strictDecode<ProblemDto>("customer_commerce_disabled_503", derived = true)

        // errors.items (cart_invalid) dan errors.quote (price_changed) juga tanpa field yang terbuang.
        val items = strictDecode<ProblemDto>("quote_zero_stock_409_cart_invalid").errors!!.jsonObject.getValue("items")
        strict.decodeFromJsonElement(ListSerializer(CartIssueDto.serializer()), items)
        val quote = strictDecode<ProblemDto>("price_changed_409", derived = true).errors!!.jsonObject.getValue("quote")
        strict.decodeFromJsonElement(QuoteDto.serializer(), quote)
    }

    private companion object {
        const val FAKE_TOKEN = "tes-token-palsu-123"
        const val KEY = "ef8aaacb-686e-47d1-94ad-056c98255414"
    }
}
