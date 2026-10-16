package com.warkop.core.domain

import org.junit.Assert.assertEquals
import org.junit.Test

class MoneyTest {
    @Test fun `string desimal server menjadi rupiah`() {
        assertEquals(66_000L, Money.parse("66000.00"))
        assertEquals(0L, Money.parse("0.00"))
        assertEquals(1_250_000L, Money.parse(" 1250000 "))
    }

    @Test fun `pecahan sen dibulatkan ke rupiah terdekat`() {
        assertEquals(10L, Money.parse("9.50"))
        assertEquals(9L, Money.parse("9.49"))
    }

    @Test fun `null kosong dan tak valid menjadi nol bukan crash`() {
        assertEquals(0L, Money.parse(null))
        assertEquals(0L, Money.parse(""))
        assertEquals(0L, Money.parse("abc"))
    }

    @Test fun `format ke server selalu dua desimal`() {
        assertEquals("66000.00", Money.toServer(66_000))
        assertEquals("0.00", Money.toServer(0))
    }
}
