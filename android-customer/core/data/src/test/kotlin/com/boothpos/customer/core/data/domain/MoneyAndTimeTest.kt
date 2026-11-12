package com.boothpos.customer.core.data.domain

import com.boothpos.customer.core.data.domain.model.CartLine
import com.boothpos.customer.core.data.domain.model.FulfilmentMethod
import com.boothpos.customer.core.data.domain.model.PageInfo
import com.boothpos.customer.core.data.domain.model.subtotal
import com.boothpos.customer.core.data.domain.money.Money
import com.boothpos.customer.core.data.domain.time.IsoTime
import java.math.BigDecimal
import java.time.Instant
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

class MoneyAndTimeTest {

    @Test
    fun `uang diurai dari string desimal menjadi BigDecimal berskala dua`() {
        assertEquals(BigDecimal("15000.00"), Money.parse("15000.00"))
        assertEquals(BigDecimal("15000.00"), Money.parse("15000"))
        assertEquals(BigDecimal("0.10"), Money.parse(" 0.1 "))
        assertEquals(BigDecimal("-2500.00"), Money.parse("-2500.00"))
    }

    @Test
    fun `masukan yang bukan desimal polos ditolak`() {
        listOf(null, "", "abc", "1e5", "15.000,00", "Rp15000", "1,5", "NaN", "12345678901234567890").forEach {
            assertNull("'$it' harus ditolak", Money.parse(it))
        }
        assertEquals(Money.ZERO, Money.parseOrZero("rusak"))
    }

    @Test
    fun `bentuk kawat selalu dua desimal tanpa notasi ilmiah`() {
        assertEquals("150000.00", Money.toWire(BigDecimal("150000")))
        assertEquals("150000.00", Money.toWire(BigDecimal("1.5E+5")))
        assertEquals("0.50", Money.toWire(BigDecimal("0.5")))
    }

    @Test
    fun `subtotal keranjang adalah jumlah harga kali kuantitas`() {
        fun line(price: String, quantity: Int, max: Int = 20) =
            CartLine(1, "slug", "Produk", "Varian", null, BigDecimal(price), quantity, max)

        assertEquals(BigDecimal("65000.00"), listOf(line("15000.00", 3), line("20000.00", 1)).subtotal())
        assertEquals(BigDecimal.ZERO, emptyList<CartLine>().subtotal())
        assertTrue(line("1.00", 5, max = 2).exceedsAvailable)
        assertFalse(line("1.00", 2, max = 2).exceedsAvailable)
    }

    @Test
    fun `cap waktu ISO dengan offset menjadi Instant dan yang rusak menjadi null`() {
        assertEquals(Instant.parse("2026-11-11T03:00:00Z"), IsoTime.parse("2026-11-11T10:00:00+07:00"))
        assertEquals(Instant.parse("2026-11-11T03:00:00Z"), IsoTime.parse("2026-11-11T03:00:00Z"))
        assertNull(IsoTime.parse(null))
        assertNull(IsoTime.parse("kemarin sore"))
    }

    @Test
    fun `metode pemenuhan tak dikenal diabaikan dan halaman tahu masih ada lanjutan`() {
        assertEquals(FulfilmentMethod.Courier, FulfilmentMethod.fromWire("courier"))
        assertNull(FulfilmentMethod.fromWire("drone"))
        assertTrue(PageInfo(currentPage = 1, perPage = 20, total = 41, lastPage = 3).hasMore)
        assertFalse(PageInfo(currentPage = 3, perPage = 20, total = 41, lastPage = 3).hasMore)
    }
}
