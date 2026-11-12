package com.boothpos.customer.core.network

import kotlinx.coroutines.test.runTest
import mockwebserver3.MockResponse
import mockwebserver3.MockWebServer
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNotEquals
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test

class HttpClientFactoryTest {

    private val server = MockWebServer()

    @Before
    fun setUp() = server.start()

    @After
    fun tearDown() = server.close()

    @Test
    fun `klien tidak pernah mengulang otomatis dan memakai batas waktu kontrak`() {
        val client = HttpClientFactory.create(
            NetworkConfig("https://boothpos.invalid/api/v1/"),
            { null },
            UnauthorizedEvents(),
            emptyList(),
        )

        assertFalse("Checkout tidak boleh diulang otomatis oleh OkHttp", client.retryOnConnectionFailure)
        assertEquals(10_000, client.connectTimeoutMillis)
        assertEquals(30_000, client.readTimeoutMillis)
        assertEquals(30_000, client.writeTimeoutMillis)
        assertEquals("Batas total satu panggilan, bukan hanya jeda antarbyte", 60_000, client.callTimeoutMillis)
        assertFalse(client.followSslRedirects)
    }

    @Test
    fun `setiap permintaan membawa header baku dan request id unik`() = runTest {
        val api = testApi(server.url("/api/v1/").toString())
        repeat(2) { server.enqueue(MockResponse.Builder().body("""{"data":{"name":"x"}}""").build()) }

        api.me()
        api.me()

        val first = server.takeRequest()
        val second = server.takeRequest()
        assertEquals("application/json", first.headers["Accept"])
        assertEquals("id", first.headers["Accept-Language"])
        val requestId = first.headers["X-Request-Id"]!!
        assertTrue(Regex("^[A-Za-z0-9._-]{8,64}$").matches(requestId))
        assertNotEquals(requestId, second.headers["X-Request-Id"])
    }

    @Test(expected = IllegalArgumentException::class)
    fun `base url tanpa garis miring akhir ditolak`() {
        NetworkConfig("https://boothpos.invalid/api/v1")
    }
}
