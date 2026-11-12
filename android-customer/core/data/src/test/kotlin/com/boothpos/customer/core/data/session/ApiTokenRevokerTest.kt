package com.boothpos.customer.core.data.session

import com.boothpos.customer.core.data.remote.SessionApi
import com.boothpos.customer.core.network.HttpClientFactory
import com.boothpos.customer.core.network.NetworkConfig
import com.boothpos.customer.core.network.NetworkJson
import com.boothpos.customer.core.network.RetrofitFactory
import com.boothpos.customer.core.network.UnauthorizedEvents
import com.boothpos.customer.core.network.error.ProblemJsonParser
import com.boothpos.customer.core.network.error.SafeApiCall
import kotlinx.coroutines.test.runTest
import mockwebserver3.MockResponse
import mockwebserver3.MockWebServer
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Before
import org.junit.Test

/** `POST /customer/auth/logout` (kontrak §5): token dikirim eksplisit, jawaban 204 tanpa body. */
class ApiTokenRevokerTest {

    private val server = MockWebServer()
    private val json = NetworkJson.create()
    private lateinit var revoker: ApiTokenRevoker

    @Before
    fun setUp() {
        server.start()
        val config = NetworkConfig(server.url("/api/v1/").toString())
        // Token sudah dihapus dari perangkat saat logout dipanggil: penyedia token mengembalikan null.
        val client = HttpClientFactory.create(config, { null }, UnauthorizedEvents())
        val api = RetrofitFactory.create(config, json, client).create(SessionApi::class.java)
        revoker = ApiTokenRevoker({ api }, SafeApiCall(ProblemJsonParser(json)))
    }

    @After
    fun tearDown() = server.close()

    @Test
    fun `logout mengirim token yang dilepas sebagai Bearer dan menerima 204`() = runTest {
        server.enqueue(MockResponse.Builder().code(204).build())

        revoker.revoke(FAKE_TOKEN)

        val request = server.takeRequest()
        assertEquals("POST", request.method)
        assertEquals("/api/v1/customer/auth/logout", request.url.encodedPath)
        assertEquals("Bearer $FAKE_TOKEN", request.headers["Authorization"])
        assertEquals("application/json", request.headers["Accept"])
    }

    @Test
    fun `logout yang ditolak server atau gagal jaringan tidak melempar`() = runTest {
        server.enqueue(MockResponse.Builder().code(401).body("""{"status":401,"code":"unauthenticated"}""").build())
        revoker.revoke(FAKE_TOKEN)

        server.close()
        revoker.revoke(FAKE_TOKEN)

        assertEquals(1, server.requestCount)
    }

    private companion object {
        const val FAKE_TOKEN = "tes-token-palsu-123"
    }
}
