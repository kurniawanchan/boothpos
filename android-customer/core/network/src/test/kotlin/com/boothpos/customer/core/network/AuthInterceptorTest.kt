package com.boothpos.customer.core.network

import app.cash.turbine.test
import com.boothpos.customer.core.network.error.ProblemJsonParser
import com.boothpos.customer.core.network.error.SafeApiCall
import kotlinx.coroutines.test.runTest
import mockwebserver3.MockResponse
import mockwebserver3.MockWebServer
import okhttp3.Request
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Before
import org.junit.Test

class AuthInterceptorTest {

    private val server = MockWebServer()
    private val otherHost = MockWebServer()
    private val events = UnauthorizedEvents()
    private val safeApiCall = SafeApiCall(ProblemJsonParser(NetworkJson.create()))
    private var token: String? = FAKE_TOKEN
    private lateinit var api: TestApi

    @Before
    fun setUp() {
        server.start()
        otherHost.start()
        api = testApi(server.url("/api/v1/").toString(), tokenProvider = { token }, events = events)
    }

    @After
    fun tearDown() {
        server.close()
        otherHost.close()
    }

    private fun ok() = MockResponse.Builder().body("""{"data":{"name":"x"}}""").build()

    @Test
    fun `panggilan customer membawa Bearer`() = runTest {
        server.enqueue(ok())

        api.me()

        assertEquals("Bearer $FAKE_TOKEN", server.takeRequest().headers["Authorization"])
    }

    @Test
    fun `panggilan storefront publik tidak pernah membawa token`() = runTest {
        server.enqueue(MockResponse.Builder().body("""{"data":[],"meta":{"current_page":1,"per_page":20,"total":0,"last_page":1}}""").build())

        api.products()

        assertNull(server.takeRequest().headers["Authorization"])
    }

    @Test
    fun `login tidak membawa token walau sesi masih ada`() = runTest {
        server.enqueue(ok())

        api.login(LoginBody("palsu@example.test", "bukan-sandi-asli"))

        assertNull(server.takeRequest().headers["Authorization"])
    }

    @Test
    fun `host lain tidak menerima token walau path-nya mirip`() = runTest {
        otherHost.enqueue(ok())

        api.absolute(otherHost.url("/api/v1/customer/me").toString())

        assertNull(otherHost.takeRequest().headers["Authorization"])
    }

    @Test
    fun `tanpa sesi tidak ada header dan 401 tidak dianggap sesi berakhir`() = runTest {
        token = null
        server.enqueue(MockResponse.Builder().code(401).body("""{"code":"unauthenticated"}""").build())

        events.rejectedTokens.test {
            safeApiCall { api.me() }
            assertNull(server.takeRequest().headers["Authorization"])
            expectNoEvents()
        }
    }

    @Test
    fun `401 pada permintaan bertoken memberi tahu lapisan sesi`() = runTest {
        server.enqueue(MockResponse.Builder().code(401).body("""{"code":"unauthenticated"}""").build())

        events.rejectedTokens.test {
            safeApiCall { api.me() }
            assertEquals(FAKE_TOKEN, awaitItem())
        }
    }

    @Test
    fun `401 dari login tidak memberi tahu lapisan sesi`() = runTest {
        server.enqueue(MockResponse.Builder().code(401).body("""{"code":"invalid_credentials"}""").build())

        events.rejectedTokens.test {
            safeApiCall { api.login(LoginBody("palsu@example.test", "bukan-sandi-asli")) }
            expectNoEvents()
        }
    }

    @Test
    fun `header Authorization dari pemanggil tidak ditimpa`() {
        server.enqueue(ok())
        val config = NetworkConfig(server.url("/api/v1/").toString())
        val client = HttpClientFactory.create(config, { token }, events, emptyList())

        client.newCall(
            Request.Builder().url(server.url("/api/v1/customer/auth/logout"))
                .header("Authorization", "Bearer token-lama-palsu").build(),
        ).execute().close()

        assertEquals("Bearer token-lama-palsu", server.takeRequest().headers["Authorization"])
    }

    private companion object {
        const val FAKE_TOKEN = "tes-token-palsu-123"
    }
}
