package com.boothpos.customer.feature.catalog.presentation

import androidx.lifecycle.SavedStateHandle
import app.cash.turbine.test
import com.boothpos.customer.core.data.domain.cart.CartAddResult
import com.boothpos.customer.core.data.domain.cart.CartRepository
import com.boothpos.customer.core.data.domain.model.CartItem
import com.boothpos.customer.core.data.domain.model.CartLine
import com.boothpos.customer.core.data.testing.FakeCartRepository
import com.boothpos.customer.core.data.testing.MainDispatcherRule
import com.boothpos.customer.core.network.error.ApiError
import com.boothpos.customer.core.network.error.ApiErrorCodes
import com.boothpos.customer.core.network.error.ApiResult
import com.boothpos.customer.core.network.error.isStoreUnavailable
import com.boothpos.customer.feature.catalog.presentation.CatalogTestData.detail
import com.boothpos.customer.feature.catalog.presentation.CatalogTestData.variant
import java.math.BigDecimal
import kotlinx.coroutines.CompletableDeferred
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.test.advanceUntilIdle
import kotlinx.coroutines.test.runCurrent
import kotlinx.coroutines.test.runTest
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Rule
import org.junit.Test

@OptIn(ExperimentalCoroutinesApi::class)
class ProductDetailViewModelTest {

    @get:Rule
    val mainRule = MainDispatcherRule()

    private val repository = FakeCatalogRepository()
    private val cart = FakeCartRepository()

    private fun handle() = SavedStateHandle(mapOf(ProductDetailRoute.SLUG_ARG to SLUG))

    private fun viewModel(
        handle: SavedStateHandle = handle(),
        cart: CartRepository = this.cart,
    ) = ProductDetailViewModel(handle, repository, cart)

    private fun ProductDetailViewModel.content() = uiState.value as ProductDetailUiState.Content

    private fun serveDetail() {
        repository.detailHandler = { ApiResult.Success(detail()) }
    }

    /** Keranjang yang penambahannya ditahan, untuk memeriksa ketukan ganda. */
    private class GatedCart(private val delegate: FakeCartRepository) : CartRepository by delegate {
        val gate = CompletableDeferred<Unit>()
        var addCalls = 0

        override suspend fun add(item: CartItem, quantity: Int): CartAddResult {
            addCalls++
            gate.await()
            return delegate.add(item, quantity)
        }
    }

    // --- memuat ---

    @Test
    fun `memuat detail berdasarkan slug dan memilih varian pertama yang dapat dibeli`() = runTest(mainRule.dispatcher) {
        repository.detailHandler = {
            ApiResult.Success(
                detail(
                    variants = listOf(
                        variant(30, "Habis", inStock = false, maxQty = 0),
                        variant(31, "Kecil", "15000.00", maxQty = 3),
                        variant(32, "Besar", "25000.00", maxQty = 5),
                    ),
                ),
            )
        }

        val viewModel = viewModel()
        assertEquals(ProductDetailUiState.Loading, viewModel.uiState.value)
        advanceUntilIdle()

        assertEquals(listOf(SLUG), repository.detailCalls)
        val content = viewModel.content()
        assertEquals("Gantungan Kunci Uji", content.product.summary.name)
        assertEquals(31L, content.selectedVariantId)
        assertEquals(1, content.quantity)
        assertEquals(3, content.maxQuantity)
        assertTrue(content.canAddToCart)
        assertFalse(content.offline)
    }

    @Test
    fun `404 not_found menjadi keadaan produk tidak lagi tersedia`() = runTest(mainRule.dispatcher) {
        repository.detailHandler = { ApiResult.Failure(ApiError.Http(404, ApiErrorCodes.NOT_FOUND)) }
        // Walau ada cache, jawaban tegas server yang berlaku.
        repository.cachedDetail = detail()

        val viewModel = viewModel()
        advanceUntilIdle()

        assertEquals(ProductDetailUiState.NotFound, viewModel.uiState.value)
        viewModel.retry()
        advanceUntilIdle()
        assertEquals(1, repository.detailCalls.size)
    }

    @Test
    fun `toko tidak tersedia tampil sebagai galat toko dan dapat dicoba lagi tanpa permintaan ganda`() =
        runTest(mainRule.dispatcher) {
            val closed = ApiError.Http(503, ApiErrorCodes.CUSTOMER_COMMERCE_DISABLED)
            repository.detailHandler = { ApiResult.Failure(closed) }
            repository.cachedDetail = detail()
            val viewModel = viewModel()
            advanceUntilIdle()

            val error = viewModel.uiState.value as ProductDetailUiState.Error
            assertTrue(error.error.isStoreUnavailable)

            serveDetail()
            viewModel.retry()
            viewModel.retry()
            assertEquals(ProductDetailUiState.Error(closed, retrying = true), viewModel.uiState.value)
            advanceUntilIdle()

            assertEquals(31L, viewModel.content().selectedVariantId)
            assertEquals(2, repository.detailCalls.size)
        }

    @Test
    fun `instalasi terkunci 423 juga tampil sebagai toko tidak tersedia`() = runTest(mainRule.dispatcher) {
        repository.detailHandler = { ApiResult.Failure(ApiError.Http(423, ApiErrorCodes.STORE_LOCKED)) }

        val viewModel = viewModel()
        advanceUntilIdle()

        assertTrue((viewModel.uiState.value as ProductDetailUiState.Error).error.isStoreUnavailable)
    }

    @Test
    fun `offline memakai detail dari cache lalu coba lagi memperbaruinya tanpa mengubah pilihan`() =
        runTest(mainRule.dispatcher) {
            repository.detailHandler = { ApiResult.Failure(ApiError.Network) }
            repository.cachedDetail = detail()
            val viewModel = viewModel()
            advanceUntilIdle()

            assertTrue(viewModel.content().offline)
            assertTrue("Keranjang tetap berguna saat offline", viewModel.content().canAddToCart)

            viewModel.onVariantSelected(32)
            viewModel.increaseQuantity()

            // Masih offline: data cache tetap tampil.
            viewModel.retry()
            assertTrue(viewModel.content().refreshing)
            advanceUntilIdle()
            assertTrue(viewModel.content().offline)
            assertFalse(viewModel.content().refreshing)

            serveDetail()
            viewModel.retry()
            advanceUntilIdle()

            val content = viewModel.content()
            assertFalse(content.offline)
            assertFalse(content.refreshing)
            assertEquals(32L, content.selectedVariantId)
            assertEquals(2, content.quantity)
        }

    @Test
    fun `offline tanpa cache dan galat server tampil sebagai galat dengan coba lagi`() = runTest(mainRule.dispatcher) {
        repository.detailHandler = { ApiResult.Failure(ApiError.Network) }
        val viewModel = viewModel()
        advanceUntilIdle()
        assertEquals(ProductDetailUiState.Error(ApiError.Network), viewModel.uiState.value)

        // Galat server tidak jatuh ke cache.
        val serverError = ApiError.Http(500, ApiErrorCodes.SERVER_ERROR)
        repository.detailHandler = { ApiResult.Failure(serverError) }
        repository.cachedDetail = detail()
        viewModel.retry()
        advanceUntilIdle()

        assertEquals(ProductDetailUiState.Error(serverError), viewModel.uiState.value)
    }

    // --- varian dan jumlah ---

    @Test
    fun `memilih varian mengganti pilihan dan menyesuaikan jumlah ke batas varian baru`() =
        runTest(mainRule.dispatcher) {
            repository.detailHandler = {
                ApiResult.Success(
                    detail(
                        variants = listOf(
                            variant(31, "Kecil", "15000.00", maxQty = 3),
                            variant(32, "Besar", "25000.00", maxQty = 5),
                            variant(33, "Habis", inStock = false, maxQty = 0),
                            variant(34, "Tanpa Batas Beli", inStock = true, maxQty = 0),
                        ),
                    ),
                )
            }
            val viewModel = viewModel()
            advanceUntilIdle()

            viewModel.onVariantSelected(32)
            repeat(4) { viewModel.increaseQuantity() }
            assertEquals(32L, viewModel.content().selectedVariantId)
            assertEquals(BigDecimal("25000.00"), viewModel.content().selectedVariant?.price)
            assertEquals(5, viewModel.content().quantity)

            // Batas varian Kecil adalah 3: jumlah 5 diturunkan, tidak dibiarkan melebihi batas.
            viewModel.onVariantSelected(31)
            assertEquals(31L, viewModel.content().selectedVariantId)
            assertEquals(3, viewModel.content().quantity)

            // Varian yang tidak dapat dibeli (stok habis / batas 0) dan id asing diabaikan.
            viewModel.onVariantSelected(33)
            viewModel.onVariantSelected(34)
            viewModel.onVariantSelected(999)
            assertEquals(31L, viewModel.content().selectedVariantId)
        }

    @Test
    fun `jumlah tidak dapat turun di bawah satu atau naik melewati max_qty`() = runTest(mainRule.dispatcher) {
        serveDetail()
        val viewModel = viewModel()
        advanceUntilIdle()

        assertFalse(viewModel.content().canDecrease)
        viewModel.decreaseQuantity()
        assertEquals(1, viewModel.content().quantity)

        repeat(10) { viewModel.increaseQuantity() }
        assertEquals("max_qty varian Kecil", 3, viewModel.content().quantity)
        assertFalse(viewModel.content().canIncrease)
        assertTrue(viewModel.content().canDecrease)

        viewModel.decreaseQuantity()
        assertEquals(2, viewModel.content().quantity)
        assertTrue(viewModel.content().canIncrease)
    }

    @Test
    fun `produk tanpa varian yang dapat dibeli tidak punya pilihan dan tidak dapat ditambahkan`() =
        runTest(mainRule.dispatcher) {
            repository.detailHandler = {
                ApiResult.Success(detail(variants = listOf(variant(31, "Habis", inStock = false, maxQty = 0))))
            }
            val viewModel = viewModel()
            advanceUntilIdle()

            val content = viewModel.content()
            assertNull(content.selectedVariantId)
            assertEquals(0, content.maxQuantity)
            assertFalse(content.canAddToCart)

            viewModel.increaseQuantity()
            viewModel.addToCart()
            advanceUntilIdle()

            assertEquals(1, viewModel.content().quantity)
            assertTrue(cart.current.isEmpty())
        }

    @Test
    fun `pilihan varian dan jumlah dipulihkan dari saved state`() = runTest(mainRule.dispatcher) {
        serveDetail()
        val handle = handle()
        viewModel(handle).apply {
            advanceUntilIdle()
            onVariantSelected(32)
            increaseQuantity()
        }

        val restored = viewModel(handle)
        advanceUntilIdle()

        assertEquals(32L, restored.content().selectedVariantId)
        assertEquals(2, restored.content().quantity)
    }

    @Test
    fun `pilihan tersimpan yang tidak lagi sah diganti varian pertama dan jumlah dibatasi`() =
        runTest(mainRule.dispatcher) {
            serveDetail()
            val handle = handle()
            viewModel(handle).apply {
                advanceUntilIdle()
                onVariantSelected(32)
                repeat(4) { increaseQuantity() }
            }

            // Sejak itu varian Besar habis; pilihan lama (Besar x 5) tidak boleh bertahan.
            repository.detailHandler = {
                ApiResult.Success(
                    detail(variants = listOf(variant(31, "Kecil", maxQty = 3), variant(32, "Besar", inStock = false, maxQty = 0))),
                )
            }
            val restored = viewModel(handle)
            advanceUntilIdle()

            assertEquals(31L, restored.content().selectedVariantId)
            assertEquals(3, restored.content().quantity)
        }

    // --- tambah ke keranjang ---

    @Test
    fun `tambah ke keranjang meneruskan varian terpilih dan jumlahnya ke CartRepository`() =
        runTest(mainRule.dispatcher) {
            serveDetail()
            val viewModel = viewModel()
            advanceUntilIdle()
            viewModel.onVariantSelected(32)
            viewModel.increaseQuantity()

            viewModel.events.test {
                viewModel.addToCart()

                assertEquals(ProductDetailEvent.AddedToCart(quantity = 2), awaitItem())
            }

            assertEquals(
                listOf(
                    CartLine(
                        variantId = 32,
                        productSlug = SLUG,
                        productName = "Gantungan Kunci Uji",
                        variantName = "Besar",
                        imageUrl = null,
                        unitPrice = BigDecimal("25000.00"),
                        quantity = 2,
                        maxQuantity = 5,
                    ),
                ),
                cart.current,
            )
            assertFalse(viewModel.content().adding)
        }

    @Test
    fun `ketukan ganda pada tambah ke keranjang hanya menambah satu kali`() = runTest(mainRule.dispatcher) {
        serveDetail()
        val gated = GatedCart(cart)
        val viewModel = viewModel(cart = gated)
        advanceUntilIdle()

        viewModel.events.test {
            viewModel.addToCart()
            viewModel.addToCart()
            runCurrent()
            assertTrue(viewModel.content().adding)
            assertFalse("Tombol nonaktif selama penambahan berjalan", viewModel.content().canAddToCart)

            gated.gate.complete(Unit)

            assertEquals(ProductDetailEvent.AddedToCart(quantity = 1), awaitItem())
            expectNoEvents()
        }
        assertEquals(1, gated.addCalls)
        assertEquals(1, cart.current.single().quantity)
    }

    @Test
    fun `jumlah yang melampaui batas baris dilaporkan sebagai disesuaikan`() = runTest(mainRule.dispatcher) {
        serveDetail()
        val viewModel = viewModel()
        advanceUntilIdle()
        repeat(2) { viewModel.increaseQuantity() }

        viewModel.events.test {
            viewModel.addToCart()
            assertEquals(ProductDetailEvent.AddedToCart(quantity = 3), awaitItem())

            // Baris itu sudah berisi 3 (batasnya): penambahan berikutnya dibatasi keranjang.
            viewModel.addToCart()
            assertEquals(ProductDetailEvent.QuantityCapped(maxQuantity = 3), awaitItem())
        }
        assertEquals(3, cart.current.single().quantity)
    }

    @Test
    fun `keranjang penuh varian tidak tersedia dan kegagalan simpan menjadi kejadian tersendiri`() =
        runTest(mainRule.dispatcher) {
            serveDetail()
            var outcome: () -> CartAddResult = { CartAddResult.CartFull(maxLines = 50) }
            val scripted = object : CartRepository by cart {
                override suspend fun add(item: CartItem, quantity: Int): CartAddResult = outcome()
            }
            val viewModel = viewModel(cart = scripted)
            advanceUntilIdle()

            viewModel.events.test {
                viewModel.addToCart()
                assertEquals(ProductDetailEvent.CartFull(maxLines = 50), awaitItem())

                outcome = { CartAddResult.Unavailable }
                viewModel.addToCart()
                assertEquals(ProductDetailEvent.VariantUnavailable, awaitItem())

                outcome = { throw IllegalStateException("penyimpanan penuh") }
                viewModel.addToCart()
                assertEquals(ProductDetailEvent.AddFailed, awaitItem())
            }
            // Setelah gagal pun tombol kembali dapat dipakai.
            assertFalse(viewModel.content().adding)
            assertTrue(viewModel.content().canAddToCart)
        }

    private companion object {
        const val SLUG = "produk-uji-1"
    }
}
