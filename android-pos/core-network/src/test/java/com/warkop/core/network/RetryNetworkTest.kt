package com.warkop.core.network

import com.warkop.core.domain.ApiError
import kotlinx.coroutines.runBlocking
import org.junit.Assert.assertEquals
import org.junit.Assert.assertThrows
import org.junit.Test

class RetryNetworkTest {
    @Test fun `gangguan jaringan dicoba ulang sampai berhasil`() = runBlocking {
        var calls = 0
        val result = retryNetwork(delayMs = 0) {
            calls++
            if (calls < 3) throw ApiError.Network() else "ok"
        }
        assertEquals("ok", result)
        assertEquals(3, calls)
    }

    @Test fun `menyerah setelah batas percobaan`() = runBlocking {
        var calls = 0
        assertThrows(ApiError.Network::class.java) {
            runBlocking { retryNetwork(attempts = 2, delayMs = 0) { calls++; throw ApiError.Network() } }
        }
        assertEquals(3, calls)
    }

    @Test fun `galat server tidak pernah diulang`() = runBlocking {
        var calls = 0
        assertThrows(ApiError.Conflict::class.java) {
            runBlocking { retryNetwork(delayMs = 0) { calls++; throw ApiError.Conflict("pesan", "shift_required") } }
        }
        assertEquals(1, calls)
    }
}
