package com.warkop.core.network

import kotlinx.coroutines.runBlocking
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNotSame
import org.junit.Test

/** Blok apiCall tidak boleh berjalan di thread pemanggil (main thread UI): lihat ANR "Order counter baru", 2026-10-16. */
class ApiCallDispatchTest {
    @Test fun `blok apiCall berjalan di thread lain dan hasilnya tetap diteruskan`() = runBlocking {
        val caller = Thread.currentThread()
        var ran: Thread? = null
        val value = apiCall { ran = Thread.currentThread(); 7 }
        assertEquals(7, value)
        assertNotSame(caller, ran)
    }
}
