package com.boothpos.customer.core.network

import kotlinx.coroutines.test.runTest
import mockwebserver3.MockResponse
import mockwebserver3.MockWebServer
import okhttp3.logging.HttpLoggingInterceptor
import org.junit.After
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test

/** Hanya untuk varian debug: build rilis tidak punya interseptor logging sama sekali. */
class DebugLoggingRedactionTest {

    private val server = MockWebServer()
    private val lines = mutableListOf<String>()

    @Before
    fun setUp() = server.start()

    @After
    fun tearDown() = server.close()

    @Test
    fun `log debug tidak memuat token maupun body permintaan dan jawaban`() = runTest {
        val api = testApi(
            server.url("/api/v1/").toString(),
            tokenProvider = { "tes-token-palsu-123" },
            extraInterceptors = buildTypeInterceptors { lines += it },
        )
        server.enqueue(MockResponse.Builder().body("""{"data":{"name":"nama-di-body-jawaban"}}""").build())
        server.enqueue(MockResponse.Builder().body("""{"data":{"name":"x"}}""").build())

        api.me()
        api.login(LoginBody("palsu@example.test", "bukan-sandi-asli"))

        val log = lines.joinToString("\n")
        assertTrue("Baris ringkas permintaan tetap ada", log.contains("/api/v1/customer/me"))
        assertFalse(log.contains("tes-token-palsu-123"))
        assertFalse(log.contains("Bearer"))
        assertFalse(log.contains("bukan-sandi-asli"))
        assertFalse(log.contains("palsu@example.test"))
        assertFalse(log.contains("nama-di-body-jawaban"))
    }

    @Test
    fun `header Authorization tetap teredaksi bila level dinaikkan ke HEADERS`() = runTest {
        val interceptors = buildTypeInterceptors { lines += it }
        (interceptors.single() as HttpLoggingInterceptor).level = HttpLoggingInterceptor.Level.HEADERS
        val api = testApi(
            server.url("/api/v1/").toString(),
            tokenProvider = { "tes-token-palsu-123" },
            extraInterceptors = interceptors,
        )
        server.enqueue(MockResponse.Builder().body("""{"data":{"name":"x"}}""").build())

        api.me()

        val log = lines.joinToString("\n")
        assertTrue(log.contains("Authorization"))
        assertFalse(log.contains("tes-token-palsu-123"))
    }
}
