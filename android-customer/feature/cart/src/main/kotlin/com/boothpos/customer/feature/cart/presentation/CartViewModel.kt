package com.boothpos.customer.feature.cart.presentation

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.boothpos.customer.core.data.domain.cart.CartAddResult
import com.boothpos.customer.core.data.domain.cart.CartRepository
import com.boothpos.customer.core.data.domain.model.CartItem
import com.boothpos.customer.core.data.domain.model.CartLine
import com.boothpos.customer.core.data.domain.model.subtotal
import com.boothpos.customer.feature.cart.domain.CartLineIssue
import com.boothpos.customer.feature.cart.domain.issue
import dagger.hilt.android.lifecycle.HiltViewModel
import java.math.BigDecimal
import javax.inject.Inject
import kotlin.coroutines.cancellation.CancellationException
import kotlinx.coroutines.channels.Channel
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.flow.receiveAsFlow
import kotlinx.coroutines.launch
import kotlinx.coroutines.sync.Mutex
import kotlinx.coroutines.sync.withLock

data class CartLineUi(val line: CartLine, val issue: CartLineIssue?) {
    val canDecrease: Boolean get() = line.quantity > 1

    /** Batas atasnya `max_qty` terakhir dari server. */
    val canIncrease: Boolean get() = issue == null && line.quantity < line.maxQuantity
}

sealed interface CartUiState {
    /** Keranjang tersimpan belum selesai dibaca. */
    data object Loading : CartUiState

    data object Empty : CartUiState

    data class Content(
        val lines: List<CartLineUi>,
        /** Jumlah harga TERSIMPAN di perangkat; total resmi baru ada di ringkasan checkout. */
        val subtotal: BigDecimal,
        val itemCount: Int,
    ) : CartUiState {
        val hasIssues: Boolean get() = lines.any { it.issue != null }

        /** Baris bermasalah pasti ditolak server, jadi diperbaiki dulu di sini. */
        val canCheckout: Boolean get() = !hasIssues
    }
}

sealed interface CartEvent {
    /** [canUndo] false untuk barang yang tidak lagi dijual: mengembalikannya tidak ada gunanya. */
    data class LineRemoved(val productName: String, val canUndo: Boolean) : CartEvent

    data object UndoFailed : CartEvent

    /** Penyimpanan keranjang di perangkat gagal; tidak ada yang berubah. */
    data object UpdateFailed : CartEvent

    data object ProceedToCheckout : CartEvent
}

/**
 * Keranjang lokal: jumlah dalam batas `max_qty`, hapus dengan urungkan, dan total sementara.
 * Harga dan stok di sini cuplikan; server yang memutuskan saat checkout.
 */
@HiltViewModel
class CartViewModel @Inject constructor(private val cart: CartRepository) : ViewModel() {

    private val _uiState = MutableStateFlow<CartUiState>(CartUiState.Loading)
    val uiState: StateFlow<CartUiState> = _uiState.asStateFlow()

    private val _events = Channel<CartEvent>(Channel.BUFFERED)
    val events: Flow<CartEvent> = _events.receiveAsFlow()

    // Ketukan beruntun pada +/- dijalankan berurutan dan selalu membaca jumlah TERKINI dari
    // penyimpanan, bukan dari state layar yang bisa tertinggal satu pancaran.
    private val mutex = Mutex()
    private var lastRemoved: CartLine? = null

    init {
        viewModelScope.launch {
            cart.lines.collect { lines -> _uiState.value = lines.toUiState() }
        }
    }

    fun increase(variantId: Long) = mutate {
        val line = currentLine(variantId) ?: return@mutate null
        if (line.issue() == null && line.quantity < line.maxQuantity) cart.setQuantity(variantId, line.quantity + 1)
        null
    }

    /** Berhenti di 1; menghapus baris adalah aksi tersendiri yang bisa diurungkan. */
    fun decrease(variantId: Long) = mutate {
        val line = currentLine(variantId) ?: return@mutate null
        if (line.quantity > 1) cart.setQuantity(variantId, line.quantity - 1)
        null
    }

    /** Menurunkan jumlah ke batas terakhir dari server untuk baris yang melebihinya. */
    fun adjustToLimit(variantId: Long) = mutate {
        val line = currentLine(variantId) ?: return@mutate null
        if (line.maxQuantity >= 1 && line.quantity > line.maxQuantity) cart.setQuantity(variantId, line.maxQuantity)
        null
    }

    fun remove(variantId: Long) = mutate {
        val line = currentLine(variantId) ?: return@mutate null
        cart.remove(variantId)
        lastRemoved = line
        CartEvent.LineRemoved(line.productName, canUndo = line.maxQuantity >= 1)
    }

    /** Mengembalikan baris yang TERAKHIR dihapus. Baris kembali di urutan paling akhir. */
    fun undoRemove() = mutate {
        val line = lastRemoved ?: return@mutate null
        lastRemoved = null
        val item = CartItem(
            variantId = line.variantId,
            productSlug = line.productSlug,
            productName = line.productName,
            variantName = line.variantName,
            imageUrl = line.imageUrl,
            unitPrice = line.unitPrice,
            maxQuantity = line.maxQuantity,
        )
        when (cart.add(item, line.quantity.coerceAtLeast(1))) {
            is CartAddResult.Added, is CartAddResult.QuantityCapped -> null
            is CartAddResult.CartFull, CartAddResult.Unavailable -> CartEvent.UndoFailed
        }
    }

    fun checkout() {
        val content = _uiState.value as? CartUiState.Content ?: return
        if (content.canCheckout) _events.trySend(CartEvent.ProceedToCheckout)
    }

    private suspend fun currentLine(variantId: Long): CartLine? =
        cart.lines.first().firstOrNull { it.variantId == variantId }

    private fun mutate(block: suspend () -> CartEvent?) {
        viewModelScope.launch {
            val event = try {
                mutex.withLock { block() }
            } catch (e: CancellationException) {
                throw e
            } catch (_: Exception) {
                // Mis. penyimpanan perangkat penuh. Detailnya sengaja tidak dibawa ke layar/log.
                CartEvent.UpdateFailed
            }
            event?.let { _events.send(it) }
        }
    }

    private fun List<CartLine>.toUiState(): CartUiState =
        if (isEmpty()) {
            CartUiState.Empty
        } else {
            CartUiState.Content(
                lines = map { CartLineUi(it, it.issue()) },
                subtotal = subtotal(),
                itemCount = sumOf { it.quantity },
            )
        }
}
