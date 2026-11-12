package com.boothpos.customer.feature.account.contract

import app.cash.turbine.test
import com.boothpos.customer.core.data.domain.session.SessionState
import com.boothpos.customer.core.data.remote.AccountDto
import com.boothpos.customer.core.data.testing.FakeSessionRepository
import com.boothpos.customer.core.data.testing.RealPayloads
import com.boothpos.customer.core.data.testing.TestData
import com.boothpos.customer.core.network.HttpClientFactory
import com.boothpos.customer.core.network.NetworkConfig
import com.boothpos.customer.core.network.NetworkJson
import com.boothpos.customer.core.network.RetrofitFactory
import com.boothpos.customer.core.network.UnauthorizedEvents
import com.boothpos.customer.core.network.dto.DataEnvelope
import com.boothpos.customer.core.network.dto.ProblemDto
import com.boothpos.customer.core.network.error.ApiError
import com.boothpos.customer.core.network.error.ApiErrorCodes
import com.boothpos.customer.core.network.error.ApiResult
import com.boothpos.customer.core.network.error.ProblemJsonParser
import com.boothpos.customer.core.network.error.SafeApiCall
import com.boothpos.customer.core.network.error.errorOrNull
import com.boothpos.customer.core.network.error.isUnauthenticated
import com.boothpos.customer.feature.account.data.ProfileApi
import com.boothpos.customer.feature.account.data.ProfileRepositoryImpl
import java.time.Instant
import kotlinx.coroutines.test.runTest
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import mockwebserver3.MockWebServer
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test

/**
 * Kontrak §5 (`GET`/`PUT /customer/me`) dan §3 terhadap jawaban NYATA backend, lewat Retrofit +
 * converter + `Json` milik :core:network dan `ProfileRepositoryImpl`. Berkas ada di
 * `src/test/resources/real` (lihat README di sana). E-mail dan telepon sudah disamarkan.
 */
class RealProfileContractTest {

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
    private val unauthorized = UnauthorizedEvents()

    // Akun yang tersimpan di sesi sebelum jawaban datang (id sama dengan akun rekaman).
    private val signedIn = TestData.account(id = 1, name = "Nama Lama", phone = null)
    private val session = FakeSessionRepository(SessionState.SignedIn(signedIn, expiresAt = null))
    private lateinit var repository: ProfileRepositoryImpl

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
    fun `200 GET me nyata - akun terbaca penuh dan sesi diperbarui`() = runTest {
        val recorded = RealPayloads.recorded("me_200")
        assertEquals(200, recorded.status)
        server.enqueueRecorded(recorded)

        val account = (repository.refresh() as ApiResult.Success).value

        assertEquals(1L, account.id)
        assertEquals("Pelanggan Bruno A", account.name)
        assertEquals("b***@example.test", account.email)
        assertEquals("0800****0001", account.phone)
        assertFalse(account.emailVerified)
        assertEquals(Instant.parse("2026-11-12T04:15:16Z"), account.createdAt)
        assertEquals(account, sessionAccount())

        val request = server.takeRequest()
        assertEquals("GET", request.method)
        assertEquals("/api/v1/customer/me", request.url.encodedPath)
        assertEquals("Bearer $FAKE_TOKEN", request.headers["Authorization"])
    }

    @Test
    fun `200 PUT me nyata - nama dan telepon baru terbaca, e-mail tidak berubah`() = runTest {
        val recorded = RealPayloads.recorded("me_update_200")
        assertEquals(200, recorded.status)
        server.enqueueRecorded(recorded)

        val account = (repository.update("Pelanggan Bruno A Diperbarui", "0800****0011") as ApiResult.Success).value

        assertEquals("Pelanggan Bruno A Diperbarui", account.name)
        assertEquals("0800****0011", account.phone)
        // Rekaman #21 mengirim `email` baru juga dan server mengabaikannya: e-mail tetap.
        assertEquals("b***@example.test", account.email)
        assertEquals(account, sessionAccount())

        val request = server.takeRequest()
        assertEquals("PUT", request.method)
        assertEquals("/api/v1/customer/me", request.url.encodedPath)
        // Aplikasi hanya mengirim nama dan telepon (e-mail tidak dapat diubah di v1).
        val body = json.parseToJsonElement(request.body!!.utf8()).jsonObject
        assertEquals(setOf("name", "phone"), body.keys)
        assertEquals("Pelanggan Bruno A Diperbarui", body.getValue("name").jsonPrimitive.content)
        assertEquals("0800****0011", body.getValue("phone").jsonPrimitive.content)
    }

    @Test
    fun `401 unauthenticated nyata (tanpa token) - kode dikenali dan token yang ditolak diteruskan`() = runTest {
        val recorded = RealPayloads.recorded("me_unauthenticated_no_token_401")
        assertEquals(401, recorded.status)
        server.enqueueRecorded(recorded)

        unauthorized.rejectedTokens.test {
            val error = repository.refresh().errorOrNull() as ApiError.Http

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
            // Permintaan membawa token, jadi 401 diteruskan ke lapisan sesi (yang menghapus sesi).
            assertEquals(FAKE_TOKEN, awaitItem())
        }
        // Repository sendiri tidak menyentuh sesi pada galat; itu tugas lapisan sesi.
        assertEquals(signedIn, sessionAccount())
    }

    @Test
    fun `401 unauthenticated nyata untuk token setelah logout - bentuknya sama`() = runTest {
        val recorded = RealPayloads.recorded("me_after_logout_401")
        assertEquals(401, recorded.status)
        server.enqueueRecorded(recorded)

        val error = repository.update("Nama Baru", null).errorOrNull() as ApiError.Http

        assertEquals(ApiErrorCodes.UNAUTHENTICATED, error.code)
        assertEquals("f8e6fa4a-323c-4e55-a5fd-1a684a4e5963", error.requestId)
        assertTrue(error.fieldErrors.isEmpty())
    }

    // --- tidak ada field rekaman yang diabaikan diam-diam ---

    @Test
    fun `setiap field rekaman profil dan galatnya dimodelkan DTO aplikasi`() {
        // `email_verified` nyata (false) sama dengan nilai bawaan DTO; mode ketat membuktikan namanya dikenali.
        strictDecode<DataEnvelope<AccountDto>>("me_200")
        strictDecode<DataEnvelope<AccountDto>>("me_update_200")
        strictDecode<ProblemDto>("me_unauthenticated_no_token_401")
        strictDecode<ProblemDto>("me_after_logout_401")
    }

    private companion object {
        const val FAKE_TOKEN = "tes-token-palsu-123"
    }
}
