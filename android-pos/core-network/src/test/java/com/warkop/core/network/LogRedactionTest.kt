package com.warkop.core.network

import okhttp3.Interceptor
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.Protocol
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import okhttp3.Response
import okhttp3.ResponseBody.Companion.toResponseBody
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

/** Log hanya boleh memuat metode + path + status; header dan badan (token, PIN) tidak pernah tercetak. */
class LogRedactionTest {
    private fun run(code: Int = 200): List<String> {
        val lines = mutableListOf<String>()
        val interceptor = RedactedLogInterceptor { lines += it }
        val request = Request.Builder().url("http://10.0.2.2:8010/api/v1/auth/pin-sessions")
            .header("Authorization", "Bearer SECRET-TOKEN-123")
            .post("""{"pin":"482916"}""".toRequestBody("application/json".toMediaType())).build()
        val chain = object : Interceptor.Chain {
            override fun request() = request
            override fun proceed(request: Request) = Response.Builder().request(request).protocol(Protocol.HTTP_1_1).code(code).message("x")
                .body("""{"token":"ANOTHER-SECRET"}""".toResponseBody()).build()
            override fun connection() = null
            override fun call() = throw UnsupportedOperationException()
            override fun connectTimeoutMillis() = 0
            override fun withConnectTimeout(timeout: Int, unit: java.util.concurrent.TimeUnit) = this
            override fun readTimeoutMillis() = 0
            override fun withReadTimeout(timeout: Int, unit: java.util.concurrent.TimeUnit) = this
            override fun writeTimeoutMillis() = 0
            override fun withWriteTimeout(timeout: Int, unit: java.util.concurrent.TimeUnit) = this
        }
        interceptor.intercept(chain)
        return lines
    }

    @Test fun `log memuat metode path dan status`() {
        val line = run(401).single()
        assertTrue(line.startsWith("POST /api/v1/auth/pin-sessions → 401"))
    }

    @Test fun `log tidak memuat token pin atau badan`() {
        val joined = run().joinToString("\n")
        assertFalse(joined.contains("SECRET-TOKEN-123"))
        assertFalse(joined.contains("ANOTHER-SECRET"))
        assertFalse(joined.contains("482916"))
        assertFalse(joined.contains("Authorization"))
    }
}
