package com.boothpos.customer.feature.cart.data

import android.content.Context
import androidx.room.Room
import androidx.test.core.app.ApplicationProvider
import androidx.test.ext.junit.runners.AndroidJUnit4
import com.boothpos.customer.core.data.cart.CartRepositoryImpl
import com.boothpos.customer.core.data.domain.model.CartItem
import com.boothpos.customer.core.data.domain.model.subtotal
import com.boothpos.customer.core.data.local.AppDatabase
import com.boothpos.customer.core.data.testing.FakeStoreConfigRepository
import com.boothpos.customer.core.data.testing.TestData
import java.math.BigDecimal
import java.time.Clock
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.test.runTest
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Test
import org.junit.runner.RunWith

/**
 * Keranjang harus bertahan melewati matinya proses. Di sini basis data BERKAS sungguhan ditutup
 * lalu dibuka lagi dengan instance baru, seperti yang terjadi saat aplikasi dimulai ulang.
 */
@RunWith(AndroidJUnit4::class)
class CartPersistenceTest {

    private val context = ApplicationProvider.getApplicationContext<Context>()
    private var database: AppDatabase? = null

    private fun openDatabase(): AppDatabase =
        Room.databaseBuilder(context, AppDatabase::class.java, DB_NAME).allowMainThreadQueries().build().also { database = it }

    private fun repository(db: AppDatabase) =
        CartRepositoryImpl(db, FakeStoreConfigRepository(TestData.storeConfig()), Clock.systemUTC())

    @After
    fun tearDown() {
        database?.close()
        context.deleteDatabase(DB_NAME)
    }

    @Test
    fun `isi jumlah dan harga keranjang kembali utuh setelah basis data ditutup dan dibuka lagi`() = runTest {
        val before = openDatabase()
        val cart = repository(before)
        cart.add(
            CartItem(
                variantId = 31,
                productSlug = "gantungan-kunci-uji",
                productName = "Gantungan Kunci Uji",
                variantName = "Kecil",
                imageUrl = null,
                unitPrice = BigDecimal("15000.00"),
                maxQuantity = 3,
            ),
            quantity = 2,
        )
        cart.add(
            CartItem(
                variantId = 32,
                productSlug = "stiker-uji",
                productName = "Stiker Uji",
                variantName = "A5",
                imageUrl = "https://cdn.example.test/v32.png",
                unitPrice = BigDecimal("12500.50"),
                maxQuantity = 5,
            ),
        )
        cart.setQuantity(31, 3)
        before.close()

        val restored = repository(openDatabase()).lines.first()

        assertEquals(listOf(31L, 32L), restored.map { it.variantId })
        assertEquals(listOf(3, 1), restored.map { it.quantity })
        assertEquals("Kecil", restored.first().variantName)
        assertEquals(3, restored.first().maxQuantity)
        assertEquals("https://cdn.example.test/v32.png", restored.last().imageUrl)
        // 3 x 15000.00 + 1 x 12500.50
        assertEquals(BigDecimal("57500.50"), restored.subtotal())
    }

    private companion object {
        const val DB_NAME = "cart-persistence-test.db"
    }
}
