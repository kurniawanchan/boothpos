package com.boothpos.customer.feature.cart.domain

import com.boothpos.customer.core.data.domain.model.CartLine

/**
 * Masalah satu baris keranjang menurut cuplikan TERAKHIR dari server (batas jumlah). Bukan
 * keputusan stok: server memeriksa ulang semuanya saat checkout; ini hanya membuat pelanggan
 * memperbaiki keranjang lebih awal daripada ditolak di langkah terakhir.
 */
sealed interface CartLineIssue {
    /** Server menyatakan varian ini tidak lagi dapat dibeli (batas 0). Hanya bisa dihapus. */
    data object Unavailable : CartLineIssue

    /** Jumlah pilihan melebihi batas terakhir dari server (stok berkurang atau batas per baris). */
    data class ExceedsLimit(val maxQuantity: Int) : CartLineIssue
}

fun CartLine.issue(): CartLineIssue? = when {
    maxQuantity < 1 -> CartLineIssue.Unavailable
    quantity > maxQuantity -> CartLineIssue.ExceedsLimit(maxQuantity)
    else -> null
}
