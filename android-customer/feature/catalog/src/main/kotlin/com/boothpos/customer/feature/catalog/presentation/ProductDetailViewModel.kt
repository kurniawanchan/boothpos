package com.boothpos.customer.feature.catalog.presentation

import androidx.lifecycle.SavedStateHandle
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.boothpos.customer.core.data.domain.cart.CartAddResult
import com.boothpos.customer.core.data.domain.cart.CartRepository
import com.boothpos.customer.core.data.domain.model.CartItem
import com.boothpos.customer.core.network.error.ApiError
import com.boothpos.customer.core.network.error.ApiErrorCodes
import com.boothpos.customer.core.network.error.ApiResult
import com.boothpos.customer.core.network.error.isOffline
import com.boothpos.customer.core.network.error.isStoreUnavailable
import com.boothpos.customer.feature.catalog.domain.CatalogRepository
import com.boothpos.customer.feature.catalog.domain.ProductDetail
import com.boothpos.customer.feature.catalog.domain.ProductVariant
import dagger.hilt.android.lifecycle.HiltViewModel
import javax.inject.Inject
import kotlin.coroutines.cancellation.CancellationException
import kotlinx.coroutines.channels.Channel
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.receiveAsFlow
import kotlinx.coroutines.launch

sealed interface ProductDetailUiState {
    data object Loading : ProductDetailUiState

    data class Error(val error: ApiError, val retrying: Boolean = false) : ProductDetailUiState

    /** Server menjawab 404 `not_found`: produk tidak lagi dijual. */
    data object NotFound : ProductDetailUiState

    data class Content(
        val product: ProductDetail,
        val selectedVariantId: Long?,
        val quantity: Int,
        /** Penambahan ke keranjang sedang berjalan: tombol dinonaktifkan supaya tidak tertambah dua kali. */
        val adding: Boolean = false,
        /** Data dari cache karena perangkat offline; harga/stok dipastikan lagi saat checkout. */
        val offline: Boolean = false,
        val refreshing: Boolean = false,
    ) : ProductDetailUiState {
        val selectedVariant: ProductVariant?
            get() = product.variants.firstOrNull { it.id == selectedVariantId }

        /** Batas jumlah varian terpilih dari server; 0 bila tidak ada varian yang dapat dibeli. */
        val maxQuantity: Int
            get() = selectedVariant?.takeIf { it.isPurchasable }?.maxQty ?: 0

        val canDecrease: Boolean get() = quantity > 1
        val canIncrease: Boolean get() = quantity < maxQuantity
        val canAddToCart: Boolean get() = maxQuantity >= 1 && !adding
    }
}

sealed interface ProductDetailEvent {
    /** [quantity] barang baru saja ditambahkan. */
    data class AddedToCart(val quantity: Int) : ProductDetailEvent

    /** Jumlah baris di keranjang dibatasi ke [maxQuantity] (batas per baris dari server). */
    data class QuantityCapped(val maxQuantity: Int) : ProductDetailEvent

    data class CartFull(val maxLines: Int) : ProductDetailEvent

    data object VariantUnavailable : ProductDetailEvent

    /** Penyimpanan keranjang di perangkat gagal; tidak ada yang ditambahkan. */
    data object AddFailed : ProductDetailEvent
}

/**
 * Detail produk: pilih varian, jumlah dalam batas `max_qty` server, lalu tambah ke keranjang
 * bersama. Harga, stok, dan batas hanya ditampilkan; checkout memvalidasinya lagi di server.
 */
@HiltViewModel
class ProductDetailViewModel @Inject constructor(
    private val savedStateHandle: SavedStateHandle,
    private val repository: CatalogRepository,
    private val cart: CartRepository,
) : ViewModel() {

    private val slug: String =
        checkNotNull(savedStateHandle.get<String>(ProductDetailRoute.SLUG_ARG)) { "Rute detail produk tanpa slug" }

    // Semua penulisan state terjadi di main thread (viewModelScope), jadi tidak ada penulis serentak.
    private val _uiState = MutableStateFlow<ProductDetailUiState>(ProductDetailUiState.Loading)
    val uiState: StateFlow<ProductDetailUiState> = _uiState.asStateFlow()

    private val _events = Channel<ProductDetailEvent>(Channel.BUFFERED)
    val events: Flow<ProductDetailEvent> = _events.receiveAsFlow()

    init {
        load()
    }

    /** "Coba lagi" pada galat dan pada pita offline. Ketukan ganda tidak mengirim dua permintaan. */
    fun retry() {
        when (val state = _uiState.value) {
            ProductDetailUiState.Loading, ProductDetailUiState.NotFound -> return
            is ProductDetailUiState.Error -> if (state.retrying) return
            is ProductDetailUiState.Content -> if (state.refreshing) return
        }
        load()
    }

    fun onVariantSelected(variantId: Long) {
        val content = _uiState.value as? ProductDetailUiState.Content ?: return
        if (variantId == content.selectedVariantId) return
        val variant = content.product.variants.firstOrNull { it.id == variantId && it.isPurchasable } ?: return
        // Jumlah yang sudah dipilih dipertahankan sejauh batas varian baru mengizinkan.
        select(content, variant.id, content.quantity.coerceIn(1, variant.maxQty))
    }

    fun increaseQuantity() {
        val content = _uiState.value as? ProductDetailUiState.Content ?: return
        if (content.canIncrease) select(content, content.selectedVariantId, content.quantity + 1)
    }

    fun decreaseQuantity() {
        val content = _uiState.value as? ProductDetailUiState.Content ?: return
        if (content.canDecrease) select(content, content.selectedVariantId, content.quantity - 1)
    }

    fun addToCart() {
        val content = _uiState.value as? ProductDetailUiState.Content ?: return
        val variant = content.selectedVariant ?: return
        if (!content.canAddToCart) return
        val summary = content.product.summary
        val quantity = content.quantity
        _uiState.value = content.copy(adding = true)
        viewModelScope.launch {
            val event = try {
                val result = cart.add(
                    item = CartItem(
                        variantId = variant.id,
                        productSlug = summary.slug,
                        productName = summary.name,
                        variantName = variant.name,
                        imageUrl = variant.imageUrl?.takeIf { it.isNotBlank() } ?: summary.imageUrl,
                        unitPrice = variant.price,
                        maxQuantity = variant.maxQty,
                    ),
                    quantity = quantity,
                )
                when (result) {
                    is CartAddResult.Added -> ProductDetailEvent.AddedToCart(quantity)
                    is CartAddResult.QuantityCapped -> ProductDetailEvent.QuantityCapped(result.maxQuantity)
                    is CartAddResult.CartFull -> ProductDetailEvent.CartFull(result.maxLines)
                    CartAddResult.Unavailable -> ProductDetailEvent.VariantUnavailable
                }
            } catch (e: CancellationException) {
                throw e
            } catch (_: Exception) {
                // Mis. penyimpanan perangkat penuh. Detailnya sengaja tidak dibawa ke layar/log.
                ProductDetailEvent.AddFailed
            }
            (_uiState.value as? ProductDetailUiState.Content)?.let { _uiState.value = it.copy(adding = false) }
            _events.send(event)
        }
    }

    private fun load() {
        _uiState.value = when (val state = _uiState.value) {
            is ProductDetailUiState.Content -> state.copy(refreshing = true)
            is ProductDetailUiState.Error -> state.copy(retrying = true)
            else -> ProductDetailUiState.Loading
        }
        viewModelScope.launch {
            val result = repository.product(slug)
            // Pelanggan boleh mengubah pilihan selama penyegaran: pakai state TERKINI, bukan yang lama.
            val shown = _uiState.value as? ProductDetailUiState.Content
            _uiState.value = when (result) {
                is ApiResult.Success -> contentFor(result.value, offline = false, shown = shown)
                is ApiResult.Failure -> failureState(result.error, shown)
            }
        }
    }

    private suspend fun failureState(error: ApiError, shown: ProductDetailUiState.Content?): ProductDetailUiState = when {
        error is ApiError.Http && error.code == ApiErrorCodes.NOT_FOUND -> ProductDetailUiState.NotFound
        // Toko ditutup: produk tidak boleh tetap tampil seolah masih dapat dibeli.
        error.isStoreUnavailable -> ProductDetailUiState.Error(error)
        shown != null -> shown.copy(refreshing = false)
        error.isOffline -> repository.cachedProduct(slug)
            ?.let { cached -> contentFor(cached, offline = true, shown = null) }
            ?: ProductDetailUiState.Error(error)
        else -> ProductDetailUiState.Error(error)
    }

    /**
     * Pilihan (varian + jumlah) dipertahankan bila masih sah menurut data baru; kalau tidak,
     * varian pertama yang dapat dibeli dipilih dan jumlah dibatasi ke `1..max_qty`.
     */
    private fun contentFor(
        product: ProductDetail,
        offline: Boolean,
        shown: ProductDetailUiState.Content?,
    ): ProductDetailUiState.Content {
        val wantedVariantId = if (shown != null) shown.selectedVariantId else savedStateHandle.get<Long>(KEY_VARIANT)
        val wantedQuantity = shown?.quantity ?: savedStateHandle.get<Int>(KEY_QUANTITY) ?: 1
        val variant = product.variants.firstOrNull { it.id == wantedVariantId && it.isPurchasable }
            ?: product.variants.firstOrNull { it.isPurchasable }
        val quantity = wantedQuantity.coerceIn(1, (variant?.maxQty ?: 1).coerceAtLeast(1))
        remember(variant?.id, quantity)
        return ProductDetailUiState.Content(
            product = product,
            selectedVariantId = variant?.id,
            quantity = quantity,
            adding = shown?.adding ?: false,
            offline = offline,
        )
    }

    private fun select(content: ProductDetailUiState.Content, variantId: Long?, quantity: Int) {
        remember(variantId, quantity)
        _uiState.value = content.copy(selectedVariantId = variantId, quantity = quantity)
    }

    /** Pilihan ikut saved state supaya tidak hilang bila proses dimatikan sistem. */
    private fun remember(variantId: Long?, quantity: Int) {
        savedStateHandle[KEY_VARIANT] = variantId
        savedStateHandle[KEY_QUANTITY] = quantity
    }

    private companion object {
        const val KEY_VARIANT = "catalog_selected_variant_id"
        const val KEY_QUANTITY = "catalog_quantity"
    }
}
