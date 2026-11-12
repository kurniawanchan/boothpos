package com.boothpos.customer.core.data.domain.model

data class PageInfo(val currentPage: Int, val perPage: Int, val total: Int, val lastPage: Int) {
    val hasMore: Boolean get() = currentPage < lastPage
}

/** Satu halaman hasil dari daftar berhalaman (produk, pesanan). */
data class Paged<T>(val items: List<T>, val page: PageInfo)
