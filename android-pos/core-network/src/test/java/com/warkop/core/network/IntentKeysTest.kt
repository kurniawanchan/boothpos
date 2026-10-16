package com.warkop.core.network

import org.junit.Assert.assertEquals
import org.junit.Assert.assertNotEquals
import org.junit.Test

class IntentKeysTest {
    @Test fun `niat yang sama selama belum terkonfirmasi memakai kunci yang sama`() {
        var n = 0
        val keys = IntentKeys { "k${++n}" }
        assertEquals(keys.keyFor("pay:12:cash:100000"), keys.keyFor("pay:12:cash:100000"))
    }

    @Test fun `setelah terkonfirmasi niat berikutnya mendapat kunci baru`() {
        var n = 0
        val keys = IntentKeys { "k${++n}" }
        val first = keys.keyFor("send:1")
        keys.settled("send:1")
        assertNotEquals(first, keys.keyFor("send:1"))
    }

    @Test fun `niat berbeda mendapat kunci berbeda`() {
        val keys = IntentKeys()
        assertNotEquals(keys.keyFor("line:add:1:2"), keys.keyFor("line:add:1:3"))
    }
}
