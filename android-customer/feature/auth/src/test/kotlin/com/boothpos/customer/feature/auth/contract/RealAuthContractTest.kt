package com.boothpos.customer.feature.auth.contract

import app.cash.turbine.test
import com.boothpos.customer.core.data.domain.model.Account
import com.boothpos.customer.core.data.domain.session.SessionRepository
import com.boothpos.customer.core.data.domain.session.SessionState
import com.boothpos.customer.core.data.testing.RealPayloads
import com.boothpos.customer.core.network.HttpClientFactory
import com.boothpos.customer.core.network.NetworkConfig
import com.boothpos.customer.core.network.NetworkJson
import com.boothpos.customer.core.network.RetrofitFactory
import com.boothpos.customer.core.network.UnauthorizedEvents
import com.boothpos.customer.core.network.dto.ProblemDto
import com.boothpos.customer.core.network.error.ApiError
import com.boothpos.customer.core.network.error.ApiErrorCodes
import com.boothpos.customer.core.network.error.ApiResult
import com.boothpos.customer.core.network.error.ProblemJsonParser
import com.boothpos.customer.core.network.error.SafeApiCall
import com.boothpos.customer.core.network.error.errorOrNull
import com.boothpos.customer.core.network.error.isUnauthenticated
import com.boothpos.customer.feature.auth.data.AuthApi
import com.boothpos.customer.feature.auth.data.AuthRepositoryImpl
import com.boothpos.customer.feature.auth.data.AuthResponseDto
import com.boothpos.customer.feature.auth.domain.RegisterInput
import java.time.Instant
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.test.runTest
import kotlinx.serialization.json.Json
import mockwebserver3.MockWebServer
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test

/**
 * Kontrak §5 (register, login) dan §3 (galat) terhadap jawaban NYATA backend, lewat Retrofit +
 * converter + `Json` milik :core:network dan `AuthRepositoryImpl`. Berkas ada di
 * `src/test/resources/real` (lihat README di sana). E-mail dan telepon sudah disamarkan; token
 * pada rekaman berupa penanda `<redacted>` (bukan token sungguhan).
 */
class RealAuthContractTest {

    private class RecordingSession : SessionRepository {
        override val state = MutableStateFlow<SessionState>(SessionState.SignedOut())
        var token: String? = null
        var expiresAt: Instant? = null
        var starts = 0

        override suspend fun startSession(token: String, expiresAt: Instant?, account: Account) {
            starts++
            this.token = token
            this.expiresAt = expiresAt
            state.value = SessionState.SignedIn(account, expiresAt)
        }

        override suspend fun updateAccount(account: Account) = Unit

        override suspend fun signOut() = Unit
    }

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
    private val session = RecordingSession()
    private val unauthorized = UnauthorizedEvents()
    private lateinit var repository: AuthRepositoryImpl

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
            tokenRevoker = { },
            scope = CoroutineScope(Dispatchers.Unconfined),
        )
    }

    @After
    fun tearDown() = server.close()

    private fun assertRecordedAccountOf(account: Account) {
        assertEquals(1L, account.id)
        assertEquals("Pelanggan Bruno A", account.name)
        assertEquals("b***@example.test", account.email)
        assertEquals("0800****0001", account.phone)
        assertFalse(account.emailVerified)
        // `created_at` nyata memakai offset "+00:00".
        assertEquals(Instant.parse("2026-11-12T04:15:16Z"), account.createdAt)
    }

    // --- 201 register ---

    @Test
    fun `201 register nyata - akun terbaca, sesi dimulai, permintaan aplikasi sama dengan rekaman`() = runTest {
        val recorded = RealPayloads.recorded("register_201")
        assertEquals(201, recorded.status)
        server.enqueueRecorded(recorded)

        val account = (
            repository.register(
                RegisterInput(
                    name = "Pelanggan Bruno A",
                    email = "b***@example.test",
                    password = "<redacted>",
                    phone = "0800****0001",
                ),
            ) as ApiResult.Success
            ).value

        assertRecordedAccountOf(account)
        assertEquals(1, session.starts)
        assertEquals("<redacted>", session.token)
        // `expires_at` nyata: dua minggu setelah pembuatan akun.
        assertEquals(Instant.parse("2026-11-26T04:15:16Z"), session.expiresAt)

        val request = server.takeRequest()
        assertEquals("POST", request.method)
        assertEquals("/api/v1/customer/auth/register", request.url.encodedPath)
        assertNull("Endpoint publik tidak membawa token", request.headers["Authorization"])
        assertEquals(
            json.parseToJsonElement(RealPayloads.recordedRequest("register_201_request")),
            json.parseToJsonElement(request.body!!.utf8()),
        )
    }

    // --- 200 login ---

    @Test
    fun `200 login nyata - akun terbaca, sesi dimulai, permintaan aplikasi sama dengan rekaman`() = runTest {
        val recorded = RealPayloads.recorded("login_200")
        assertEquals(200, recorded.status)
        server.enqueueRecorded(recorded)

        val account = (repository.signIn("b***@example.test", "<redacted>") as ApiResult.Success).value

        assertRecordedAccountOf(account)
        assertEquals(1, session.starts)
        assertEquals("<redacted>", session.token)
        assertEquals(Instant.parse("2026-11-26T04:15:17Z"), session.expiresAt)

        val request = server.takeRequest()
        assertEquals("/api/v1/customer/auth/login", request.url.encodedPath)
        assertNull(request.headers["Authorization"])
        assertEquals(
            json.parseToJsonElement(RealPayloads.recordedRequest("login_200_request")),
            json.parseToJsonElement(request.body!!.utf8()),
        )
    }

    // --- 401 invalid_credentials (kata sandi salah dan e-mail tak terdaftar: jawaban identik) ---

    @Test
    fun `401 invalid_credentials nyata - sesi tidak dimulai dan bukan dianggap sesi berakhir`() = runTest {
        val wrongPassword = RealPayloads.recorded("login_wrong_password_401_invalid_credentials")
        val unknownEmail = RealPayloads.recorded("login_unknown_email_401_invalid_credentials")
        assertEquals(401, wrongPassword.status)
        assertEquals(401, unknownEmail.status)
        server.enqueueRecorded(wrongPassword)
        server.enqueueRecorded(unknownEmail)

        unauthorized.rejectedTokens.test {
            val first = repository.signIn("b***@example.test", "<redacted>").errorOrNull() as ApiError.Http
            val second = repository.signIn("tidak-ada@example.test", "<redacted>").errorOrNull() as ApiError.Http

            assertEquals(401, first.status)
            assertEquals(ApiErrorCodes.INVALID_CREDENTIALS, first.code)
            assertEquals("E-mail atau kata sandi salah.", first.detail)
            assertEquals("97d69ac8-f4af-4140-bb82-9d36da686e47", first.requestId)
            assertTrue(first.fieldErrors.isEmpty())
            // Kode ini BUKAN `unauthenticated`: aplikasi tidak menganggapnya sesi berakhir.
            assertFalse(first.isUnauthenticated)
            // Anti-enumerasi: e-mail terdaftar dan tidak terdaftar tak terbedakan (selain request_id).
            assertEquals(first.copy(requestId = null), second.copy(requestId = null))
            // Login tidak membawa token, jadi 401-nya tidak pernah diteruskan sebagai "token ditolak".
            expectNoEvents()
        }
        assertEquals(0, session.starts)
    }

    // --- 422 registration_failed ---

    @Test
    fun `422 registration_failed nyata - pesan netral tanpa galat kolom`() = runTest {
        val recorded = RealPayloads.recorded("register_email_taken_422_registration_failed")
        assertEquals(422, recorded.status)
        server.enqueueRecorded(recorded)

        val error = repository.register(RegisterInput("Pelanggan Bruno A", "b***@example.test", "<redacted>", null))
            .errorOrNull()

        assertEquals(
            ApiError.Http(
                status = 422,
                code = ApiErrorCodes.REGISTRATION_FAILED,
                detail = "Pendaftaran tidak dapat diproses. Periksa data Anda, atau masuk jika sudah punya akun.",
                requestId = "4845a3e3-b81d-4ae0-a723-a8db1e74f23b",
            ),
            error,
        )
        // Tidak ada `errors`: pesan ini tidak boleh menandai kolom e-mail (anti-enumerasi).
        assertTrue((error as ApiError.Http).fieldErrors.isEmpty())
        assertNull(error.errors)
        assertEquals(0, session.starts)
    }

    // --- 429 too_many_requests + Retry-After ---

    @Test
    fun `429 too_many_requests nyata membawa Retry-After 56 detik`() = runTest {
        val recorded = RealPayloads.recorded("login_rate_limited_429_too_many_requests")
        assertEquals(429, recorded.status)
        // Header sebagaimana direkam (huruf kecil di rekaman; OkHttp tidak peka huruf besar-kecil).
        assertEquals("56", recorded.header("Retry-After"))
        server.enqueueRecorded(recorded)

        val error = repository.signIn("b***@example.test", "<redacted>").errorOrNull()

        assertEquals(
            ApiError.Http(
                status = 429,
                code = ApiErrorCodes.TOO_MANY_REQUESTS,
                detail = "Terlalu banyak percobaan. Tunggu sebentar lalu coba lagi.",
                requestId = "0e3570f7-bbf9-427b-98b1-24b3b339c988",
                retryAfterSeconds = 56,
            ),
            error,
        )
        assertEquals(0, session.starts)
    }

    // --- tidak ada field rekaman yang diabaikan diam-diam ---

    @Test
    fun `setiap field rekaman auth dan galatnya dimodelkan DTO aplikasi`() {
        strictDecode<AuthResponseDto>("register_201")
        strictDecode<AuthResponseDto>("login_200")
        strictDecode<ProblemDto>("register_email_taken_422_registration_failed")
        strictDecode<ProblemDto>("login_wrong_password_401_invalid_credentials")
        strictDecode<ProblemDto>("login_unknown_email_401_invalid_credentials")
        strictDecode<ProblemDto>("login_rate_limited_429_too_many_requests")
    }

    private companion object {
        const val OLD_TOKEN = "tes-token-lama-palsu-123"
    }
}
