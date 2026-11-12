package com.boothpos.customer.core.data.cart

import android.content.Context
import androidx.room.Room
import androidx.test.core.app.ApplicationProvider
import androidx.test.ext.junit.runners.AndroidJUnit4
import app.cash.turbine.test
import com.boothpos.customer.core.data.domain.cart.CartAddResult
import com.boothpos.customer.core.data.domain.model.CartItem
import com.boothpos.customer.core.data.domain.model.CartLineRefresh
import com.boothpos.customer.core.data.domain.session.SignOutReason
import com.boothpos.customer.core.data.local.AppDatabase
import com.boothpos.customer.core.data.testing.FakeStoreConfigRepository
import com.boothpos.customer.core.data.testing.TestData
import java.math.BigDecimal
import java.time.Clock
import java.time.Instant
import java.time.ZoneOffset
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.test.runTest
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test
import org.junit.runner.RunWith

/** Keranjang di atas Room in-memory (Robolectric): SQL dan transaksinya sungguhan. */
@RunWith(AndroidJUnit4::class)
class CartRepositoryImplTest {

    private lateinit var database: AppDatabase
    private val storeConfig = FakeStoreConfigRepository(TestData.storeConfig(maxLinesPerOrder = 2))
    private val clock = Clock.fixed(Instant.parse("2026-11-11T03:00:00Z"), ZoneOffset.UTC)
    private lateinit var cart: CartRepositoryImpl

    @Before
    fun setUp() {
        database = Room.inMemoryDatabaseBuilder(ApplicationProvider.getApplicationContext<Context>(), AppDatabase::class.java)
            .allowMainThreadQueries()
            .build()
        cart = CartRepositoryImpl(database, storeConfig, clock)
    }

    @After
    fun tearDown() = database.close()

    private fun item(variantId: Long, price: String = "15000.00", max: Int = 5) = CartItem(
        variantId = variantId,
        productSlug = "gantungan-kunci",
        productName = "Gantungan Kunci",
        variantName = "Varian $variantId",
        imageUrl = null,
        unitPrice = BigDecimal(price),
        maxQuantity = max,
    )

    @Test
    fun `menambah varian membuat baris dan aliran lines memancarkannya`() = runTest {
        cart.lines.test {
            assertEquals(emptyList<Any>(), awaitItem())

            assertEquals(CartAddResult.Added(2), cart.add(item(31), quantity = 2))

            val line = awaitItem().single()
            assertEquals(31L, line.variantId)
            assertEquals(2, line.quantity)
            assertEquals(BigDecimal("15000.00"), line.unitPrice)
            assertEquals(BigDecimal("30000.00"), line.lineTotal)
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `menambah varian yang sama menjumlahkan dan dibatasi batas server`() = runTest {
        cart.add(item(31, max = 3), quantity = 2)

        assertEquals(CartAddResult.QuantityCapped(3, 3), cart.add(item(31, max = 3), quantity = 2))
        assertEquals(3, cart.lines.first().single().quantity)
        assertEquals(3, cart.itemCount.first())
    }

    @Test
    fun `varian dengan batas nol tidak dapat ditambahkan`() = runTest {
        assertEquals(CartAddResult.Unavailable, cart.add(item(31, max = 0)))
        assertTrue(cart.lines.first().isEmpty())
    }

    @Test
    fun `jumlah baris dibatasi konfigurasi toko tetapi varian yang sudah ada tetap bisa ditambah`() = runTest {
        cart.add(item(1))
        cart.add(item(2))

        assertEquals(CartAddResult.CartFull(2), cart.add(item(3)))
        assertEquals(CartAddResult.Added(2), cart.add(item(1)))
        assertEquals(2, cart.lines.first().size)
    }

    @Test
    fun `setQuantity membatasi ke rentang dan nol menghapus baris`() = runTest {
        cart.add(item(31, max = 4))

        assertEquals(4, cart.setQuantity(31, 99))
        assertEquals(2, cart.setQuantity(31, 2))
        assertNull(cart.setQuantity(31, 0))
        assertTrue(cart.lines.first().isEmpty())
        assertNull("Baris yang tidak ada", cart.setQuantity(404, 1))
    }

    @Test
    fun `remove dan clear`() = runTest {
        cart.add(item(1))
        cart.add(item(2))

        cart.remove(1)
        assertEquals(listOf(2L), cart.lines.first().map { it.variantId })

        cart.clear()
        assertEquals(0, cart.itemCount.first())
    }

    @Test
    fun `refresh memperbarui cuplikan harga dan batas tanpa mengubah jumlah pilihan`() = runTest {
        cart.add(item(31, price = "15000.00", max = 5), quantity = 4)

        cart.refresh(listOf(CartLineRefresh(31, BigDecimal("17500"), maxQuantity = 1)))

        val line = cart.lines.first().single()
        assertEquals(BigDecimal("17500.00"), line.unitPrice)
        assertEquals(1, line.maxQuantity)
        assertEquals(4, line.quantity)
        assertTrue(line.exceedsAvailable)
    }

    @Test
    fun `isi keranjang hidup di basis data bukan di instance repository`() = runTest {
        cart.add(item(31), quantity = 2)

        // Setara dengan proses yang dibuat ulang: instance baru di atas basis data yang sama.
        val recreated = CartRepositoryImpl(database, storeConfig, clock)

        assertEquals(2, recreated.lines.first().single().quantity)
    }

    @Test
    fun `keluar atas permintaan mengosongkan keranjang tetapi sesi berakhir tidak`() = runTest {
        val cleaner = CartSignOutCleaner(cart)
        cart.add(item(31))

        cleaner.clear(SignOutReason.SessionExpired)
        assertEquals(1, cart.lines.first().size)

        cleaner.clear(SignOutReason.UserRequested)
        assertTrue(cart.lines.first().isEmpty())
    }
}
