package com.boothpos.customer.core.data.domain.cart

import com.boothpos.customer.core.data.domain.model.CartItem
import com.boothpos.customer.core.data.domain.model.CartLine
import com.boothpos.customer.core.data.domain.model.CartLineRefresh
import kotlinx.coroutines.flow.Flow

sealed interface CartAddResult {
    /** [quantity] adalah jumlah baris itu SETELAH penambahan. */
    data class Added(val quantity: Int) : CartAddResult

    /** Jumlah dibatasi ke [maxQuantity] (batas per baris dari server). */
    data class QuantityCapped(val quantity: Int, val maxQuantity: Int) : CartAddResult

    /** Keranjang sudah berisi jumlah baris maksimum per pesanan. */
    data class CartFull(val maxLines: Int) : CartAddResult

    /** Varian tidak dapat dibeli (batas jumlah 0). */
    data object Unavailable : CartAddResult
}

/**
 * Keranjang lokal perangkat. Disimpan di Room sehingga bertahan melewati matinya proses dan tetap
 * berguna saat offline. Isinya hanya niat beli: harga, stok, dan total resmi selalu dari server.
 */
interface CartRepository {
    val lines: Flow<List<CartLine>>

    /** Jumlah barang (total kuantitas) untuk lencana navigasi. */
    val itemCount: Flow<Int>

    suspend fun add(item: CartItem, quantity: Int = 1): CartAddResult

    /**
     * Mengubah jumlah satu baris; nilai dibatasi ke `1..maxQuantity`, dan `<= 0` menghapus baris.
     * Mengembalikan jumlah yang diterapkan, atau null bila barisnya tidak ada / dihapus.
     */
    suspend fun setQuantity(variantId: Long, quantity: Int): Int?

    suspend fun remove(variantId: Long)

    suspend fun clear()

    /** Menyegarkan cuplikan harga/batas dari jawaban server. Jumlah pilihan pelanggan tidak diubah. */
    suspend fun refresh(updates: List<CartLineRefresh>)
}
