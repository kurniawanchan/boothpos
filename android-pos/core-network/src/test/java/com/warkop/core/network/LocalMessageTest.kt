package com.warkop.core.network

import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Test

class LocalMessageTest {
    @Test
    fun `known shift codes answer in Indonesian`() {
        assertEquals("Shift sudah ditutup.", localMessage("session_closed"))
        assertEquals(true, localMessage("open_tabs")?.startsWith("Shift hanya bisa ditutup"))
    }

    @Test
    fun `an unknown code keeps the server message`() {
        assertNull(localMessage("something_new"))
        assertNull(localMessage(null))
    }
}
