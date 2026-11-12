package com.boothpos.customer.feature.account.data

import app.cash.turbine.test
import com.boothpos.customer.core.data.domain.session.SessionState
import com.boothpos.customer.core.data.testing.FakeSessionRepository
import com.boothpos.customer.core.data.testing.TestData
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
import com.boothpos.customer.core.network.error.errorOrNull
import com.boothpos.customer.core.network.error.isUnauthenticated
import java.time.Instant
import kotlinx.coroutines.test.runTest
import kotlinx.serialization.json.JsonNull
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

/** Kontrak §5: `GET /customer/me` dan `PUT /customer/me`, termasuk jawaban galatnya (§3). */
class ProfileRepositoryImplTest {

    private val server = MockWebServer()
    private val json = NetworkJson.create()
    private val unauthorized = UnauthorizedEvents()
    private val signedIn = TestData.account(id = 12, name = "Nama Lama", phone = "0800-0000-0000")
    private val session = FakeSessionRepository(SessionState.SignedIn(signedIn, expiresAt = null))
    private lateinit var repository: ProfileRepositoryImpl

    // Data contoh yang jelas-jelas palsu.
    private fun accountJson(id: Long = 12, name: String = "Pelanggan Uji", phone: String? = null) =
        """{"data":{"id":$id,"name":"$name","email":"pelanggan.uji@example.test",
            "phone":${phone?.let { "\"$it\"" } ?: "null"},"email_verified":true,
            "created_at":"2026-11-11T10:00:00+07:00"}}"""

    @Before
    fun setUp() {
        server.start()
        val config = NetworkConfig(server.url("/api/v1/").toString())
        val client = HttpClientFactory.create(config, { FAKE_TOKEN }, unauthorized)
        val api = RetrofitFactory.create(config, json, client).create(ProfileApi::class.java)
        repository = ProfileRepositoryImpl(api, SafeApiCall(ProblemJsonParser(json)), session)
    }

    @After
    fun tearDown() = server.close()

    private fun sessionAccount() = (session.state.value as SessionState.SignedIn).account

    @Test
    fun `GET me membawa token memetakan akun dan memperbarui sesi`() = runTest {
        server.enqueue(MockResponse.Builder().body(accountJson()).build())

        val account = (repository.refresh() as ApiResult.Success).value

        assertEquals(12L, account.id)
        assertEquals("Pelanggan Uji", account.name)
        assertEquals("pelanggan.uji@example.test", account.email)
        assertNull(account.phone)
        assertTrue(account.emailVerified)
        assertEquals(Instant.parse("2026-11-11T03:00:00Z"), account.createdAt)
        assertEquals(account, sessionAccount())

        val request = server.takeRequest()
        assertEquals("GET", request.method)
        assertEquals("/api/v1/customer/me", request.url.encodedPath)
        assertEquals("Bearer $FAKE_TOKEN", request.headers["Authorization"])
        assertEquals("application/json", request.headers["Accept"])
    }

    @Test
    fun `PUT me mengirim nama dan telepon yang dipangkas lalu memperbarui sesi`() = runTest {
        server.enqueue(MockResponse.Builder().body(accountJson(name = "Nama Baru", phone = "0811-0000-0000")).build())

        val account = (repository.update("  Nama Baru ", " 0811-0000-0000 ") as ApiResult.Success).value

        assertEquals("Nama Baru", account.name)
        assertEquals("0811-0000-0000", account.phone)
        assertEquals(account, sessionAccount())
        val request = server.takeRequest()
        assertEquals("PUT", request.method)
        assertEquals("/api/v1/customer/me", request.url.encodedPath)
        assertEquals("Bearer $FAKE_TOKEN", request.headers["Authorization"])
        val body = json.parseToJsonElement(request.body!!.utf8()).jsonObject
        // E-mail tidak dapat diubah di v1, jadi tidak pernah dikirim.
        assertEquals(setOf("name", "phone"), body.keys)
        assertEquals("Nama Baru", body.getValue("name").jsonPrimitive.content)
        assertEquals("0811-0000-0000", body.getValue("phone").jsonPrimitive.content)
    }

    @Test
    fun `telepon yang dikosongkan dikirim sebagai null eksplisit`() = runTest {
        server.enqueue(MockResponse.Builder().body(accountJson()).build())
        server.enqueue(MockResponse.Builder().body(accountJson()).build())

        repository.update("Pelanggan Uji", null)
        repository.update("Pelanggan Uji", "   ")

        repeat(2) {
            val body = json.parseToJsonElement(server.takeRequest().body!!.utf8()).jsonObject
            // Tanpa kunci ini pelanggan tidak dapat menghapus nomor yang pernah diisi.
            assertEquals(JsonNull, body["phone"])
        }
        assertNull(sessionAccount().phone)
    }

    @Test
    fun `401 diteruskan ke lapisan sesi sebagai token yang ditolak dan sesi tidak diubah di sini`() = runTest {
        server.enqueue(
            MockResponse.Builder().code(401)
                .body("""{"status":401,"code":"unauthenticated","detail":"Sesi berakhir."}""").build(),
        )

        unauthorized.rejectedTokens.test {
            val error = repository.refresh().errorOrNull()!!

            assertTrue(error.isUnauthenticated)
            assertEquals(FAKE_TOKEN, awaitItem())
        }
        assertEquals(signedIn, sessionAccount())
    }

    @Test
    fun `422 validation_failed membawa galat per kolom dan tidak mengubah sesi`() = runTest {
        server.enqueue(
            MockResponse.Builder().code(422).body(
                """{"status":422,"code":"validation_failed","detail":"Data tidak valid.",
                    "errors":{"name":["Nama wajib diisi."],"phone":["Nomor telepon maksimal 30 karakter."]}}""",
            ).build(),
        )

        val error = repository.update("", "0".repeat(31)).errorOrNull() as ApiError.Http

        assertEquals(ApiErrorCodes.VALIDATION_FAILED, error.code)
        assertEquals(listOf("Nama wajib diisi."), error.fieldErrors["name"])
        assertEquals(listOf("Nomor telepon maksimal 30 karakter."), error.fieldErrors["phone"])
        assertEquals(signedIn, sessionAccount())
    }

    @Test
    fun `429 dan galat jaringan terpetakan`() = runTest {
        server.enqueue(
            MockResponse.Builder().code(429).addHeader("Retry-After", "30")
                .body("""{"status":429,"code":"too_many_requests"}""").build(),
        )
        val limited = repository.refresh().errorOrNull() as ApiError.Http
        assertEquals(ApiErrorCodes.TOO_MANY_REQUESTS, limited.code)
        assertEquals(30L, limited.retryAfterSeconds)

        server.close()
        assertEquals(ApiError.Network, repository.refresh().errorOrNull())
    }

    @Test
    fun `jawaban untuk akun lain atau setelah keluar tidak menimpa sesi`() = runTest {
        server.enqueue(MockResponse.Builder().body(accountJson(id = 99, name = "Akun Lain")).build())
        repository.refresh()
        assertEquals(signedIn, sessionAccount())

        server.enqueue(MockResponse.Builder().body(accountJson()).build())
        session.signOut()
        val result = repository.refresh()

        assertTrue(result is ApiResult.Success)
        assertTrue(session.state.value is SessionState.SignedOut)
    }

    @Test
    fun `DTO permintaan tidak membocorkan data pribadi lewat toString`() {
        val text = UpdateProfileRequestDto("Pelanggan Uji", JsonNull).toString()

        assertFalse(text.contains("Pelanggan Uji"))
    }

    private companion object {
        const val FAKE_TOKEN = "tes-token-palsu-123"
    }
}
