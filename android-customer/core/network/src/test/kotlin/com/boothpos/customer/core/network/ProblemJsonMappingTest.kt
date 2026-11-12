package com.boothpos.customer.core.network

import com.boothpos.customer.core.network.error.ApiError
import com.boothpos.customer.core.network.error.ApiErrorCodes
import com.boothpos.customer.core.network.error.ApiResult
import com.boothpos.customer.core.network.error.ProblemJsonParser
import com.boothpos.customer.core.network.error.SafeApiCall
import com.boothpos.customer.core.network.error.errorOrNull
import com.boothpos.customer.core.network.error.isStoreUnavailable
import com.boothpos.customer.core.network.error.isUnauthenticated
import java.util.concurrent.TimeUnit
import kotlinx.coroutines.test.runTest
import kotlinx.serialization.json.jsonArray
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import mockwebserver3.MockResponse
import mockwebserver3.MockWebServer
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test

class ProblemJsonMappingTest {

    private val server = MockWebServer()
    private val safeApiCall = SafeApiCall(ProblemJsonParser(NetworkJson.create()))
    private lateinit var api: TestApi

    @Before
    fun setUp() {
        server.start()
        api = testApi(server.url("/api/v1/").toString())
    }

    @After
    fun tearDown() {
        server.close()
    }

    private fun problem(status: Int, body: String, vararg headers: Pair<String, String>) {
        val builder = MockResponse.Builder()
            .code(status)
            .addHeader("Content-Type", "application/problem+json")
            .body(body)
        headers.forEach { (name, value) -> builder.addHeader(name, value) }
        server.enqueue(builder.build())
    }

    private suspend fun failure(): ApiError = safeApiCall { api.me() }.errorOrNull()!!

    @Test
    fun `sukses membuka amplop data`() = runTest {
        server.enqueue(MockResponse.Builder().body("""{"data":{"name":"Budi","extra":"diabaikan"}}""").build())

        val result = safeApiCall { api.me() }

        assertEquals("Budi", (result as ApiResult.Success).value.data.name)
    }

    @Test
    fun `daftar berhalaman membawa meta`() = runTest {
        server.enqueue(
            MockResponse.Builder().body(
                """{"data":[{"name":"A"}],"meta":{"current_page":2,"per_page":20,"total":41,"last_page":3}}""",
            ).build(),
        )

        val page = (safeApiCall { api.products() } as ApiResult.Success).value

        assertEquals(listOf(NameDto("A")), page.data)
        assertEquals(2, page.meta.currentPage)
        assertEquals(3, page.meta.lastPage)
        assertEquals(41, page.meta.total)
    }

    @Test
    fun `422 validation_failed memetakan galat per field dan request id`() = runTest {
        problem(
            422,
            """{"type":"https://boothpos.local/problems/validation_failed","title":"Validasi gagal","status":422,
               "detail":"Periksa kembali isian Anda.","code":"validation_failed",
               "errors":{"email":["Format email tidak valid."],"password":["Minimal 8 karakter.","Wajib diisi."]},
               "request_id":"req-12345678"}""",
        )

        val error = failure() as ApiError.Http

        assertEquals(422, error.status)
        assertEquals(ApiErrorCodes.VALIDATION_FAILED, error.code)
        assertEquals("Periksa kembali isian Anda.", error.detail)
        assertEquals(listOf("Format email tidak valid."), error.fieldErrors["email"])
        assertEquals(2, error.fieldErrors["password"]?.size)
        assertEquals("req-12345678", error.requestId)
    }

    @Test
    fun `kunci terjemahan mentah dari server dibuang dan kolomnya tetap tercatat`() = runTest {
        problem(
            422,
            """{"status":422,"code":"validation_failed","detail":"Periksa kembali isian Anda.",
               "errors":{"email":["validation.email"],
                         "password":["validation.min.string","Minimal 8 karakter."],
                         "address_id":["  validation.exists"],
                         "notes":["Catatan terlalu panjang."],
                         "phone":["Nomor ini tidak valid. validation.phone"]}}""",
        )

        val error = failure() as ApiError.Http

        assertEquals("Kolom tetap tercatat sebagai ditolak, tanpa pesan", emptyList<String>(), error.fieldErrors["email"])
        assertEquals(listOf("Minimal 8 karakter."), error.fieldErrors["password"])
        assertEquals("Spasi di depan tidak menyembunyikan kunci mentah", emptyList<String>(), error.fieldErrors["address_id"])
        assertEquals(listOf("Catatan terlalu panjang."), error.fieldErrors["notes"])
        assertEquals(
            "Hanya yang DIAWALI validation. dibuang; kalimat biasa tidak disentuh",
            listOf("Nomor ini tidak valid. validation.phone"),
            error.fieldErrors["phone"],
        )
    }

    @Test
    fun `413 payload_too_large dan 413 tanpa problem json dipetakan menurut status`() = runTest {
        problem(413, """{"status":413,"code":"payload_too_large","detail":"Isi permintaan terlalu besar."}""")
        server.enqueue(
            MockResponse.Builder().code(413).addHeader("Content-Type", "text/html")
                .body("<html><body>413 Request Entity Too Large</body></html>").build(),
        )

        val withProblem = failure() as ApiError.Http
        val bare = failure() as ApiError.Http

        assertEquals(413, withProblem.status)
        assertEquals("payload_too_large", withProblem.code)
        assertEquals("Isi permintaan terlalu besar.", withProblem.detail)
        assertEquals(413, bare.status)
        assertEquals("Tanpa problem+json: kode umum dari status, tanpa membocorkan body", ApiErrorCodes.HTTP_ERROR, bare.code)
        assertNull(bare.detail)
        assertFalse(withProblem.isStoreUnavailable || bare.isStoreUnavailable)
        assertFalse(withProblem.isUnauthenticated || bare.isUnauthenticated)
    }

    @Test
    fun `423 installation_locked problem json tetap toko tidak tersedia dan tanpa teks server`() = runTest {
        problem(
            423,
            """{"type":"https://boothpos.local/problems/installation_locked","title":"Terkunci","status":423,
               "detail":"License not activated for this installation.","code":"installation_locked","request_id":"req-uji-0423"}""",
        )

        val error = failure() as ApiError.Http

        assertTrue(error.isStoreUnavailable)
        assertEquals("installation_locked", error.code)
        assertNull("Pesan untuk staf/instalasi tidak boleh sampai ke pelanggan", error.detail)
        assertEquals("req-uji-0423", error.requestId)
    }

    @Test
    fun `409 cart_invalid menyimpan errors mentah dan tidak menganggapnya galat field`() = runTest {
        problem(
            409,
            """{"status":409,"code":"cart_invalid","detail":"Sebagian barang tidak tersedia.",
               "errors":{"items":[{"variant_id":31,"reason":"insufficient_stock","max_qty":1}]}}""",
        )

        val error = failure() as ApiError.Http

        assertEquals(ApiErrorCodes.CART_INVALID, error.code)
        assertTrue(error.fieldErrors.isEmpty())
        val item = error.errors!!["items"]!!.jsonArray.first().jsonObject
        assertEquals("insufficient_stock", item["reason"]!!.jsonPrimitive.content)
        assertEquals(1, item["max_qty"]!!.jsonPrimitive.content.toInt())
    }

    @Test
    fun `503 customer_commerce_disabled berarti toko tidak tersedia dan membawa Retry-After`() = runTest {
        problem(503, """{"status":503,"code":"customer_commerce_disabled","detail":"Toko sedang tutup."}""", "Retry-After" to "3600")

        val error = failure()

        assertTrue(error.isStoreUnavailable)
        assertEquals(3600L, (error as ApiError.Http).retryAfterSeconds)
    }

    @Test
    fun `423 gerbang lisensi bukan problem json tetapi tetap toko tidak tersedia tanpa pesan staf`() = runTest {
        server.enqueue(
            MockResponse.Builder().code(423)
                .addHeader("Content-Type", "application/json")
                .body("""{"message":"Aplikasi belum diaktivasi.","activated":false}""")
                .build(),
        )

        val error = failure() as ApiError.Http

        assertTrue(error.isStoreUnavailable)
        assertEquals(ApiErrorCodes.STORE_LOCKED, error.code)
        assertNull("Pesan untuk staf tidak boleh sampai ke pelanggan", error.detail)
    }

    @Test
    fun `401 unauthenticated dikenali`() = runTest {
        problem(401, """{"status":401,"code":"unauthenticated","detail":"Silakan masuk lagi."}""")

        assertTrue(failure().isUnauthenticated)
    }

    @Test
    fun `401 invalid_credentials bukan sesi berakhir`() = runTest {
        problem(401, """{"status":401,"code":"invalid_credentials","detail":"Email atau kata sandi salah."}""")

        val error = safeApiCall { api.login(LoginBody("palsu@example.test", "bukan-sandi-asli")) }.errorOrNull()!!

        assertFalse(error.isUnauthenticated)
        assertEquals(ApiErrorCodes.INVALID_CREDENTIALS, (error as ApiError.Http).code)
    }

    @Test
    fun `429 membawa Retry-After`() = runTest {
        problem(429, """{"status":429,"code":"too_many_requests","detail":"Terlalu banyak percobaan."}""", "Retry-After" to "42")

        val error = failure() as ApiError.Http

        assertEquals(ApiErrorCodes.TOO_MANY_REQUESTS, error.code)
        assertEquals(42L, error.retryAfterSeconds)
    }

    @Test
    fun `500 dengan body HTML menjadi server_error tanpa membocorkan body`() = runTest {
        server.enqueue(
            MockResponse.Builder().code(500)
                .addHeader("Content-Type", "text/html")
                .addHeader("X-Request-Id", "hdr-abcdefgh")
                .body("<html><body>SQLSTATE[42S22]: Column not found</body></html>")
                .build(),
        )

        val error = failure() as ApiError.Http

        assertEquals(ApiErrorCodes.SERVER_ERROR, error.code)
        assertNull(error.detail)
        assertEquals("hdr-abcdefgh", error.requestId)
    }

    @Test
    fun `body sukses yang tidak sesuai bentuk menjadi Unknown`() = runTest {
        server.enqueue(MockResponse.Builder().body("""{"data":"bukan objek"}""").build())

        assertEquals(ApiError.Unknown, failure())
    }

    @Test
    fun `batas waktu baca menjadi Timeout`() = runTest {
        val slowApi = testApi(server.url("/api/v1/").toString()) { readTimeout(200, TimeUnit.MILLISECONDS) }
        server.enqueue(MockResponse.Builder().headersDelay(2, TimeUnit.SECONDS).body("{}").build())

        assertEquals(ApiError.Timeout, safeApiCall { slowApi.me() }.errorOrNull())
    }

    @Test
    fun `server tidak terjangkau menjadi Network`() = runTest {
        val url = server.url("/api/v1/").toString()
        server.close()

        assertEquals(ApiError.Network, safeApiCall { testApi(url).me() }.errorOrNull())
    }

    @Test
    fun `204 tanpa body sukses lewat unit dan galatnya tetap terpetakan`() = runTest {
        server.enqueue(MockResponse.Builder().code(204).build())
        problem(404, """{"status":404,"code":"not_found","detail":"Data tidak ditemukan."}""")

        assertEquals(ApiResult.Success(Unit), safeApiCall.unit { api.deleteAddress(5) })
        val error = safeApiCall.unit { api.deleteAddress(6) }.errorOrNull() as ApiError.Http
        assertEquals(ApiErrorCodes.NOT_FOUND, error.code)
        assertEquals("/api/v1/customer/addresses/5", server.takeRequest().url.encodedPath)
    }
}
