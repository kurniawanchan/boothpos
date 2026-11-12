package com.boothpos.customer.feature.auth.data

import app.cash.turbine.test
import com.boothpos.customer.core.data.domain.model.Account
import com.boothpos.customer.core.data.domain.session.SessionRepository
import com.boothpos.customer.core.data.domain.session.SessionState
import com.boothpos.customer.core.data.session.TokenRevoker
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
import com.boothpos.customer.core.network.error.isStoreUnavailable
import com.boothpos.customer.feature.auth.domain.RegisterInput
import java.io.IOException
import java.time.Instant
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.test.runTest
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import mockwebserver3.MockResponse
import mockwebserver3.MockWebServer
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Assert.fail
import org.junit.Before
import org.junit.Test

/** Kontrak §5: register (201) dan login (200), termasuk setiap jawaban galatnya (§3). */
class AuthRepositoryImplTest {

    /** Merekam apa yang diserahkan ke lapisan sesi (token tidak pernah kembali ke pemanggil). */
    private class RecordingSessionRepository : SessionRepository {
        override val state = MutableStateFlow<SessionState>(SessionState.SignedOut())
        var token: String? = null
        var expiresAt: Instant? = null
        var starts = 0

        /** Meniru penyimpanan token yang gagal (disk penuh, Keystore rusak). */
        var failure: Throwable? = null

        override suspend fun startSession(token: String, expiresAt: Instant?, account: Account) {
            failure?.let { throw it }
            starts++
            this.token = token
            this.expiresAt = expiresAt
            state.value = SessionState.SignedIn(account, expiresAt)
        }

        override suspend fun updateAccount(account: Account) = Unit

        override suspend fun signOut() = Unit
    }

    /** Mencatat token yang dicabut di server; [failure] meniru pencabutan yang gagal. */
    private class RecordingTokenRevoker : TokenRevoker {
        val revoked = mutableListOf<String>()
        var failure: Throwable? = null

        override suspend fun revoke(token: String) {
            revoked += token
            failure?.let { throw it }
        }
    }

    private val server = MockWebServer()
    private val json = NetworkJson.create()
    private val session = RecordingSessionRepository()
    private val revoker = RecordingTokenRevoker()
    private val unauthorized = UnauthorizedEvents()
    private lateinit var repository: AuthRepositoryImpl

    // Data contoh yang jelas-jelas palsu.
    private val authJson = """{"token":"$NEW_TOKEN","token_type":"Bearer","expires_at":"2026-11-25T10:00:00+07:00",
        "account":{"id":12,"name":"Pelanggan Uji","email":"pelanggan.uji@example.test","phone":null,
        "email_verified":false,"created_at":"2026-11-11T10:00:00+07:00"}}"""

    private val input = RegisterInput(
        name = "  Pelanggan Uji ",
        email = " Pelanggan.Uji@example.test ",
        password = FAKE_PASSWORD,
        phone = "   ",
    )

    @Before
    fun setUp() {
        server.start()
        val config = NetworkConfig(server.url("/api/v1/").toString())
        // Token lama masih tersimpan: endpoint publik tetap tidak boleh membawanya.
        val client = HttpClientFactory.create(config, { OLD_TOKEN }, unauthorized)
        val api = RetrofitFactory.create(config, json, client).create(AuthApi::class.java)
        repository = AuthRepositoryImpl(
            api,
            SafeApiCall(ProblemJsonParser(json)),
            session,
            revoker,
            // Unconfined: pencabutan "di latar belakang" berjalan segera, jadi tes dapat memeriksanya.
            CoroutineScope(Dispatchers.Unconfined),
        )
    }

    @After
    fun tearDown() = server.close()

    private fun problem(status: Int, code: String, extra: String = "") =
        MockResponse.Builder()
            .code(status)
            .addHeader("Content-Type", "application/problem+json")
            .body("""{"type":"https://boothpos.local/problems/$code","title":"T","status":$status,"detail":"Pesan server.","code":"$code","request_id":"req-uji-0001"$extra}""")

    @Test
    fun `register mengirim body kontrak tanpa token dan memulai sesi`() = runTest {
        server.enqueue(MockResponse.Builder().code(201).body(authJson).build())

        val account = (repository.register(input) as ApiResult.Success).value

        assertEquals(12L, account.id)
        assertEquals("Pelanggan Uji", account.name)
        assertEquals("pelanggan.uji@example.test", account.email)
        assertNull(account.phone)
        assertFalse(account.emailVerified)
        assertEquals(Instant.parse("2026-11-11T03:00:00Z"), account.createdAt)
        assertEquals(1, session.starts)
        assertEquals(NEW_TOKEN, session.token)
        assertEquals(Instant.parse("2026-11-25T03:00:00Z"), session.expiresAt)

        val request = server.takeRequest()
        assertEquals("POST", request.method)
        assertEquals("/api/v1/customer/auth/register", request.url.encodedPath)
        assertNull("Endpoint publik tidak membawa token", request.headers["Authorization"])
        assertEquals("application/json", request.headers["Accept"])
        assertTrue(request.headers["Content-Type"]!!.startsWith("application/json"))
        val body = json.parseToJsonElement(request.body!!.utf8()).jsonObject
        // Telepon kosong tidak dikirim; nama dan e-mail dipangkas; kata sandi apa adanya.
        assertEquals(setOf("name", "email", "password"), body.keys)
        assertEquals("Pelanggan Uji", body.getValue("name").jsonPrimitive.content)
        assertEquals("Pelanggan.Uji@example.test", body.getValue("email").jsonPrimitive.content)
        assertEquals(FAKE_PASSWORD, body.getValue("password").jsonPrimitive.content)
    }

    @Test
    fun `register mengirim telepon bila diisi`() = runTest {
        server.enqueue(MockResponse.Builder().code(201).body(authJson).build())

        repository.register(RegisterInput("Pelanggan Uji", "pelanggan.uji@example.test", FAKE_PASSWORD, " 0800-0000-0000 "))

        val body = json.parseToJsonElement(server.takeRequest().body!!.utf8()).jsonObject
        assertEquals("0800-0000-0000", body.getValue("phone").jsonPrimitive.content)
    }

    @Test
    fun `login mengirim e-mail dan kata sandi tanpa token lalu memulai sesi`() = runTest {
        server.enqueue(MockResponse.Builder().code(200).body(authJson).build())

        val result = repository.signIn(" pelanggan.uji@example.test ", FAKE_PASSWORD)

        assertEquals(12L, (result as ApiResult.Success).value.id)
        assertEquals(NEW_TOKEN, session.token)
        val request = server.takeRequest()
        assertEquals("POST", request.method)
        assertEquals("/api/v1/customer/auth/login", request.url.encodedPath)
        assertNull(request.headers["Authorization"])
        val body = json.parseToJsonElement(request.body!!.utf8()).jsonObject
        assertEquals(setOf("email", "password"), body.keys)
        assertEquals("pelanggan.uji@example.test", body.getValue("email").jsonPrimitive.content)
        assertEquals(FAKE_PASSWORD, body.getValue("password").jsonPrimitive.content)
    }

    @Test
    fun `401 invalid_credentials tidak memulai sesi dan tidak dianggap sesi berakhir`() = runTest {
        server.enqueue(problem(401, "invalid_credentials").build())

        unauthorized.rejectedTokens.test {
            val error = repository.signIn("pelanggan.uji@example.test", FAKE_PASSWORD).errorOrNull() as ApiError.Http

            assertEquals(401, error.status)
            assertEquals(ApiErrorCodes.INVALID_CREDENTIALS, error.code)
            assertEquals("Pesan server.", error.detail)
            assertEquals("req-uji-0001", error.requestId)
            // Login tidak membawa token, jadi 401-nya tidak pernah diteruskan sebagai "token ditolak"
            // (kalau diteruskan, sesi yang masih sah di perangkat akan ikut terhapus).
            expectNoEvents()
        }
        assertEquals(0, session.starts)
    }

    @Test
    fun `422 registration_failed generik tanpa galat kolom`() = runTest {
        server.enqueue(problem(422, "registration_failed").build())

        val error = repository.register(input).errorOrNull() as ApiError.Http

        assertEquals(ApiErrorCodes.REGISTRATION_FAILED, error.code)
        assertTrue(error.fieldErrors.isEmpty())
        assertEquals(0, session.starts)
    }

    @Test
    fun `422 validation_failed membawa galat per kolom`() = runTest {
        server.enqueue(
            problem(
                422,
                "validation_failed",
                ""","errors":{"email":["Format e-mail tidak valid."],"password":["Kata sandi minimal 8 karakter.","Lainnya."]}""",
            ).build(),
        )

        val error = repository.register(input).errorOrNull() as ApiError.Http

        assertEquals(ApiErrorCodes.VALIDATION_FAILED, error.code)
        assertEquals(listOf("Format e-mail tidak valid."), error.fieldErrors["email"])
        assertEquals("Kata sandi minimal 8 karakter.", error.fieldErrors.getValue("password").first())
    }

    @Test
    fun `429 too_many_requests membawa Retry-After`() = runTest {
        server.enqueue(problem(429, "too_many_requests").addHeader("Retry-After", "42").build())

        val error = repository.signIn("pelanggan.uji@example.test", FAKE_PASSWORD).errorOrNull() as ApiError.Http

        assertEquals(ApiErrorCodes.TOO_MANY_REQUESTS, error.code)
        assertEquals(42L, error.retryAfterSeconds)
    }

    @Test
    fun `503 fitur dimatikan dan 423 terkunci terbaca sebagai toko tidak tersedia`() = runTest {
        server.enqueue(problem(503, "customer_commerce_disabled").addHeader("Retry-After", "3600").build())
        server.enqueue(MockResponse.Builder().code(423).body("""{"message":"Belum diaktivasi","activated":false}""").build())

        val disabled = repository.register(input).errorOrNull()!!
        val locked = repository.signIn("pelanggan.uji@example.test", FAKE_PASSWORD).errorOrNull()!!

        assertTrue(disabled.isStoreUnavailable)
        assertTrue(locked.isStoreUnavailable)
        assertEquals(0, session.starts)
    }

    @Test
    fun `jawaban sukses tanpa token tidak membuat sesi`() = runTest {
        server.enqueue(MockResponse.Builder().code(200).body(authJson.replace(NEW_TOKEN, "")).build())

        val result = repository.signIn("pelanggan.uji@example.test", FAKE_PASSWORD)

        assertEquals(ApiError.Unknown, result.errorOrNull())
        assertEquals(0, session.starts)
    }

    @Test
    fun `register yang tokennya gagal disimpan menjadi galat tenang dan tokennya dicabut`() = runTest {
        server.enqueue(MockResponse.Builder().code(201).body(authJson).build())
        session.failure = IOException("disk penuh: ${NEW_TOKEN}")

        val result = repository.register(input)

        assertEquals(ApiError.SessionStorage, result.errorOrNull())
        assertEquals("Tidak ada sesi yang terbentuk", 0, session.starts)
        assertTrue(session.state.value is SessionState.SignedOut)
        assertEquals("Token yatim dicabut di server", listOf(NEW_TOKEN), revoker.revoked)
        assertFalse("Token dan isi pengecualian tidak ikut keluar", result.toString().contains(NEW_TOKEN))
    }

    @Test
    fun `login yang tokennya gagal disimpan juga menjadi galat tenang dan tokennya dicabut`() = runTest {
        server.enqueue(MockResponse.Builder().code(200).body(authJson).build())
        session.failure = IllegalStateException("kunci rusak")

        val result = repository.signIn("pelanggan.uji@example.test", FAKE_PASSWORD)

        assertEquals(ApiError.SessionStorage, result.errorOrNull())
        assertEquals(listOf(NEW_TOKEN), revoker.revoked)
    }

    @Test
    fun `pencabutan yang gagal tidak melempar dan tidak mengubah hasilnya`() = runTest {
        server.enqueue(MockResponse.Builder().code(201).body(authJson).build())
        session.failure = IOException("disk penuh")
        revoker.failure = IOException("jaringan putus")

        val result = repository.register(input)

        assertEquals(ApiError.SessionStorage, result.errorOrNull())
        assertEquals(listOf(NEW_TOKEN), revoker.revoked)
    }

    @Test
    fun `pembatalan saat menyimpan sesi diteruskan dan tidak dilaporkan sebagai galat penyimpanan`() = runTest {
        server.enqueue(MockResponse.Builder().code(200).body(authJson).build())
        session.failure = CancellationException("layar ditinggalkan")

        try {
            repository.signIn("pelanggan.uji@example.test", FAKE_PASSWORD)
            fail("CancellationException harus diteruskan")
        } catch (_: CancellationException) {
            // diharapkan
        }
        assertTrue("Tidak ada pencabutan untuk pembatalan", revoker.revoked.isEmpty())
    }

    @Test
    fun `galat dari server tidak pernah memicu pencabutan`() = runTest {
        server.enqueue(problem(401, "invalid_credentials").build())
        server.enqueue(MockResponse.Builder().code(200).body(authJson.replace(NEW_TOKEN, "")).build())

        repository.signIn("pelanggan.uji@example.test", FAKE_PASSWORD)
        repository.signIn("pelanggan.uji@example.test", FAKE_PASSWORD)

        assertTrue(revoker.revoked.isEmpty())
    }

    @Test
    fun `server tidak terjangkau menjadi galat jaringan`() = runTest {
        server.close()

        val result = repository.signIn("pelanggan.uji@example.test", FAKE_PASSWORD)

        assertEquals(ApiError.Network, result.errorOrNull())
        assertEquals(0, session.starts)
    }

    @Test
    fun `DTO berisi rahasia tidak membocorkannya lewat toString`() {
        val request = LoginRequestDto("pelanggan.uji@example.test", FAKE_PASSWORD).toString()
        val register = RegisterRequestDto("Pelanggan Uji", "pelanggan.uji@example.test", FAKE_PASSWORD).toString()
        val response = json.decodeFromString(AuthResponseDto.serializer(), authJson).toString()

        assertFalse(request.contains(FAKE_PASSWORD))
        assertFalse(register.contains(FAKE_PASSWORD))
        assertFalse(response.contains(NEW_TOKEN))
        assertFalse(input.toString().contains(FAKE_PASSWORD))
    }

    private companion object {
        // Nilai uji yang jelas-jelas palsu; bukan kredensial sungguhan.
        const val FAKE_PASSWORD = "sandi-palsu-uji"
        const val NEW_TOKEN = "tes-token-baru-palsu"
        const val OLD_TOKEN = "tes-token-lama-palsu"
    }
}
