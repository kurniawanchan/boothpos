package com.boothpos.customer.feature.cart.presentation

import app.cash.turbine.test
import com.boothpos.customer.core.data.domain.model.CartLine
import com.boothpos.customer.core.data.testing.FakeCartRepository
import com.boothpos.customer.core.data.testing.MainDispatcherRule
import com.boothpos.customer.feature.cart.domain.CartLineIssue
import java.math.BigDecimal
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.test.runCurrent
import kotlinx.coroutines.test.runTest
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Rule
import org.junit.Test

@OptIn(ExperimentalCoroutinesApi::class)
class CartViewModelTest {

    @get:Rule
    val mainRule = MainDispatcherRule()

    private fun line(
        variantId: Long,
        price: String,
        quantity: Int,
        maxQuantity: Int = 5,
        name: String = "Produk Uji $variantId",
    ) = CartLine(
        variantId = variantId,
        productSlug = "produk-uji-$variantId",
        productName = name,
        variantName = "Varian $variantId",
        imageUrl = null,
        unitPrice = BigDecimal(price),
        quantity = quantity,
        maxQuantity = maxQuantity,
    )

    private val keychain = line(31, "15000.00", quantity = 2, maxQuantity = 3, name = "Gantungan Kunci Uji")
    private val sticker = line(32, "12500.50", quantity = 1, name = "Stiker Uji")

    private fun CartViewModel.content() = uiState.value as CartUiState.Content

    private fun CartViewModel.quantityOf(variantId: Long) =
        content().lines.first { it.line.variantId == variantId }.line.quantity

    @Test
    fun `keranjang kosong tampil sebagai keadaan kosong`() = runTest(mainRule.dispatcher) {
        val viewModel = CartViewModel(FakeCartRepository())
        assertEquals(CartUiState.Loading, viewModel.uiState.value)

        runCurrent()

        assertEquals(CartUiState.Empty, viewModel.uiState.value)
    }

    @Test
    fun `total sementara dihitung dengan BigDecimal dari harga tersimpan`() = runTest(mainRule.dispatcher) {
        val viewModel = CartViewModel(FakeCartRepository(listOf(keychain, sticker)))
        runCurrent()

        val content = viewModel.content()
        // 2 x 15000.00 + 1 x 12500.50: tepat, tanpa galat pembulatan floating point.
        assertEquals(BigDecimal("42500.50"), content.subtotal)
        assertEquals(3, content.itemCount)
        assertEquals(listOf(31L, 32L), content.lines.map { it.line.variantId })
        assertTrue(content.canCheckout)
    }

    @Test
    fun `jumlah naik sampai batas server dan turun berhenti di satu`() = runTest(mainRule.dispatcher) {
        val cart = FakeCartRepository(listOf(keychain))
        val viewModel = CartViewModel(cart)
        runCurrent()
        assertTrue(viewModel.content().lines.single().canIncrease)

        viewModel.increase(31)
        runCurrent()
        assertEquals(3, viewModel.quantityOf(31))
        assertFalse("Di batas max_qty tombol tambah nonaktif", viewModel.content().lines.single().canIncrease)

        viewModel.increase(31)
        runCurrent()
        assertEquals("Tidak melewati max_qty", 3, viewModel.quantityOf(31))

        repeat(5) { viewModel.decrease(31) }
        runCurrent()
        assertEquals("Berhenti di 1; menghapus adalah aksi tersendiri", 1, viewModel.quantityOf(31))
        assertFalse(viewModel.content().lines.single().canDecrease)
        assertEquals(BigDecimal("15000.00"), viewModel.content().subtotal)
    }

    @Test
    fun `ketukan beruntun diterapkan berurutan terhadap jumlah terkini`() = runTest(mainRule.dispatcher) {
        val viewModel = CartViewModel(FakeCartRepository(listOf(sticker)))
        runCurrent()

        // Tiga ketukan sebelum layar sempat menerima pancaran baru.
        viewModel.increase(32)
        viewModel.increase(32)
        viewModel.increase(32)
        runCurrent()

        assertEquals(4, viewModel.quantityOf(32))
    }

    @Test
    fun `hapus memancarkan kejadian dan urungkan mengembalikan baris dengan jumlah yang sama`() =
        runTest(mainRule.dispatcher) {
            val cart = FakeCartRepository(listOf(keychain, sticker))
            val viewModel = CartViewModel(cart)
            runCurrent()

            viewModel.events.test {
                viewModel.remove(31)
                runCurrent()
                assertEquals(CartEvent.LineRemoved("Gantungan Kunci Uji", canUndo = true), awaitItem())
                assertEquals(listOf(32L), viewModel.content().lines.map { it.line.variantId })
                assertEquals(BigDecimal("12500.50"), viewModel.content().subtotal)

                viewModel.undoRemove()
                runCurrent()
                assertEquals(2, viewModel.quantityOf(31))
                assertEquals(BigDecimal("42500.50"), viewModel.content().subtotal)

                // Urungkan hanya berlaku sekali untuk satu penghapusan.
                viewModel.undoRemove()
                runCurrent()
                assertEquals(2, viewModel.quantityOf(31))
                expectNoEvents()
            }
        }

    @Test
    fun `menghapus baris terakhir mengosongkan keranjang dan urungkan mengisinya lagi`() = runTest(mainRule.dispatcher) {
        val viewModel = CartViewModel(FakeCartRepository(listOf(sticker)))
        runCurrent()

        viewModel.remove(32)
        runCurrent()
        assertEquals(CartUiState.Empty, viewModel.uiState.value)

        viewModel.undoRemove()
        runCurrent()
        assertEquals(1, viewModel.quantityOf(32))
    }

    @Test
    fun `barang yang tidak lagi tersedia ditandai dan penghapusannya tidak menawarkan urungkan`() =
        runTest(mainRule.dispatcher) {
            val gone = line(40, "9000.00", quantity = 1, maxQuantity = 0, name = "Pin Uji")
            val viewModel = CartViewModel(FakeCartRepository(listOf(gone, sticker)))
            runCurrent()

            val marked = viewModel.content().lines.first { it.line.variantId == 40L }
            assertEquals(CartLineIssue.Unavailable, marked.issue)
            assertFalse(marked.canIncrease)
            assertFalse(viewModel.content().canCheckout)

            viewModel.events.test {
                viewModel.remove(40)
                runCurrent()
                assertEquals(CartEvent.LineRemoved("Pin Uji", canUndo = false), awaitItem())
            }
            assertTrue(viewModel.content().canCheckout)
        }

    @Test
    fun `jumlah di atas batas server memblokir checkout sampai disesuaikan`() = runTest(mainRule.dispatcher) {
        // Mis. setelah cart_invalid: server menurunkan batas menjadi 1 sementara pelanggan memilih 3.
        val over = line(31, "15000.00", quantity = 3, maxQuantity = 1)
        val viewModel = CartViewModel(FakeCartRepository(listOf(over)))
        runCurrent()

        assertEquals(CartLineIssue.ExceedsLimit(1), viewModel.content().lines.single().issue)
        assertFalse(viewModel.content().canCheckout)

        viewModel.events.test {
            viewModel.checkout()
            runCurrent()
            expectNoEvents()

            viewModel.adjustToLimit(31)
            runCurrent()
            assertEquals(1, viewModel.quantityOf(31))
            assertNull(viewModel.content().lines.single().issue)

            viewModel.checkout()
            assertEquals(CartEvent.ProceedToCheckout, awaitItem())
        }
    }

    @Test
    fun `ViewModel baru di atas keranjang yang sama menampilkan isi dan total yang sama`() = runTest(mainRule.dispatcher) {
        val cart = FakeCartRepository(listOf(keychain))
        val first = CartViewModel(cart)
        runCurrent()
        first.increase(31)
        runCurrent()

        // Layar dibuat ulang (rotasi, proses dipulihkan): state berasal dari penyimpanan, bukan dari ViewModel lama.
        val second = CartViewModel(cart)
        runCurrent()

        assertEquals(3, second.quantityOf(31))
        assertEquals(BigDecimal("45000.00"), second.content().subtotal)
    }
}
