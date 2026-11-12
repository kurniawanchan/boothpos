package com.boothpos.customer.feature.catalog.presentation

import androidx.lifecycle.SavedStateHandle
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.boothpos.customer.core.data.domain.model.Paged
import com.boothpos.customer.core.data.domain.util.ConnectivityObserver
import com.boothpos.customer.core.network.error.ApiError
import com.boothpos.customer.core.network.error.ApiResult
import com.boothpos.customer.core.network.error.isOffline
import com.boothpos.customer.core.network.error.isStoreUnavailable
import com.boothpos.customer.feature.catalog.domain.CatalogRepository
import com.boothpos.customer.feature.catalog.domain.Category
import com.boothpos.customer.feature.catalog.domain.ProductQuery
import com.boothpos.customer.feature.catalog.domain.ProductSort
import com.boothpos.customer.feature.catalog.domain.ProductSummary
import dagger.hilt.android.lifecycle.HiltViewModel
import javax.inject.Inject
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.ensureActive
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.distinctUntilChanged
import kotlinx.coroutines.flow.drop
import kotlinx.coroutines.flow.filter
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

data class ShopUiState(
    /** Teks di kolom cari (langsung mengikuti ketikan; permintaannya ditunda sebentar). */
    val search: String = "",
    val categories: List<Category> = emptyList(),
    val selectedCategoryId: Long? = null,
    val sort: ProductSort = ProductSort.Newest,
    val content: ShopContent = ShopContent.Loading,
)

sealed interface ShopContent {
    data object Loading : ShopContent

    /** Halaman pertama gagal dan tidak ada yang dapat ditampilkan. */
    data class Error(val error: ApiError, val retrying: Boolean = false) : ShopContent

    /** [filtered]: kosong karena pencarian/filter, bukan karena toko belum punya produk. */
    data class Empty(val filtered: Boolean) : ShopContent

    data class Products(
        val items: List<ProductSummary>,
        val canLoadMore: Boolean = false,
        val loadingMore: Boolean = false,
        /** Halaman berikutnya gagal dimuat; produk yang sudah ada tetap tampil. */
        val loadMoreError: ApiError? = null,
        val refreshing: Boolean = false,
        /**
         * Bukan null: yang tampil mungkin bukan data terbaru, karena pemuatan terakhir gagal dengan
         * galat ini (offline -> daftar dari cache, atau penyegaran yang gagal).
         */
        val staleReason: ApiError? = null,
    ) : ShopContent
}

/**
 * Daftar produk: cari (ditunda [SEARCH_DEBOUNCE_MS]), filter kategori, urutan, dan halaman demi
 * halaman. Setiap perubahan pencarian/filter/urutan membatalkan permintaan yang sedang berjalan
 * dan mulai lagi dari halaman pertama, sehingga jawaban lama tidak pernah menimpa yang baru.
 */
@HiltViewModel
class ShopViewModel @Inject constructor(
    private val savedStateHandle: SavedStateHandle,
    private val repository: CatalogRepository,
    connectivity: ConnectivityObserver,
) : ViewModel() {

    private val _uiState = MutableStateFlow(
        ShopUiState(
            search = savedStateHandle.get<String>(KEY_SEARCH).orEmpty(),
            selectedCategoryId = savedStateHandle.get<Long>(KEY_CATEGORY),
            sort = ProductSort.fromWire(savedStateHandle.get<String>(KEY_SORT)),
        ),
    )
    val uiState: StateFlow<ShopUiState> = _uiState.asStateFlow()

    /** Kueri yang sedang/terakhir dimuat; berbeda dari teks di kolom cari selama penundaan. */
    private var appliedQuery = currentQuery()
    private var nextPage = FIRST_PAGE
    private var loadJob: Job? = null
    private var searchJob: Job? = null
    private var categoriesJob: Job? = null

    init {
        loadCategories()
        loadFirstPage(keepContent = false)
        viewModelScope.launch {
            // Koneksi kembali saat yang tampil data offline: muat ulang tanpa menunggu pelanggan.
            connectivity.isOnline.distinctUntilChanged().drop(1).filter { online -> online }.collect {
                if (showsOfflineData()) refresh()
            }
        }
    }

    fun onSearchChange(text: String) {
        val value = text.take(ProductQuery.MAX_SEARCH_LENGTH)
        if (value == _uiState.value.search) return
        savedStateHandle[KEY_SEARCH] = value
        _uiState.update { it.copy(search = value) }
        searchJob?.cancel()
        if (value.isBlank()) {
            // Mengosongkan kolom langsung mengembalikan daftar, tanpa menunggu.
            applyQuery()
        } else {
            searchJob = viewModelScope.launch {
                delay(SEARCH_DEBOUNCE_MS)
                applyQuery()
            }
        }
    }

    /** Tombol "Cari" di papan ketik: jalankan sekarang, jangan tunggu penundaan. */
    fun onSearchSubmit() {
        searchJob?.cancel()
        applyQuery()
    }

    fun onCategorySelected(categoryId: Long?) {
        if (categoryId == _uiState.value.selectedCategoryId) return
        savedStateHandle[KEY_CATEGORY] = categoryId
        _uiState.update { it.copy(selectedCategoryId = categoryId) }
        searchJob?.cancel()
        applyQuery()
    }

    fun onSortSelected(sort: ProductSort) {
        if (sort == _uiState.value.sort) return
        savedStateHandle[KEY_SORT] = sort.wireValue
        _uiState.update { it.copy(sort = sort) }
        searchJob?.cancel()
        applyQuery()
    }

    /** Dari keadaan "tidak ada yang cocok": kembali ke daftar tanpa pencarian dan filter kategori. */
    fun clearFilters() {
        savedStateHandle[KEY_SEARCH] = ""
        savedStateHandle[KEY_CATEGORY] = null
        _uiState.update { it.copy(search = "", selectedCategoryId = null) }
        searchJob?.cancel()
        applyQuery()
    }

    /** Tarik-untuk-segarkan, tombol muat ulang, dan "Coba lagi". Ketukan ganda tidak mengirim dua kali. */
    fun refresh() {
        when (val content = _uiState.value.content) {
            ShopContent.Loading -> return
            is ShopContent.Error -> if (content.retrying) return
            is ShopContent.Products -> if (content.refreshing) return
            is ShopContent.Empty -> Unit
        }
        searchJob?.cancel()
        appliedQuery = currentQuery()
        loadCategories()
        loadFirstPage(keepContent = true)
    }

    /** Dipanggil layar ketika guliran mendekati akhir daftar. */
    fun loadMore() {
        val content = _uiState.value.content as? ShopContent.Products ?: return
        // Setelah gagal, halaman berikutnya hanya dicoba lagi atas permintaan pelanggan.
        if (content.loadMoreError != null) return
        startLoadMore(content)
    }

    fun retryLoadMore() {
        val content = _uiState.value.content as? ShopContent.Products ?: return
        startLoadMore(content)
    }

    private fun currentQuery(): ProductQuery = _uiState.value.let { state ->
        ProductQuery(search = state.search.trim(), categoryId = state.selectedCategoryId, sort = state.sort)
    }

    private fun applyQuery() {
        val query = currentQuery()
        if (query == appliedQuery) return
        appliedQuery = query
        loadFirstPage(keepContent = false)
    }

    private fun loadFirstPage(keepContent: Boolean) {
        loadJob?.cancel()
        val query = appliedQuery
        val kept = _uiState.value.content.takeIf { keepContent }
        _uiState.update { state ->
            state.copy(
                content = when (kept) {
                    is ShopContent.Products -> kept.copy(refreshing = true, loadingMore = false, loadMoreError = null)
                    is ShopContent.Error -> kept.copy(retrying = true)
                    else -> ShopContent.Loading
                },
            )
        }
        loadJob = viewModelScope.launch {
            val result = repository.products(query, FIRST_PAGE)
            val content = when (result) {
                is ApiResult.Success -> result.value.toContent(query)
                is ApiResult.Failure -> firstPageFailure(query, result.error, kept)
            }
            // Kueri sudah berganti selama menunggu: jawaban ini tidak boleh tampil.
            ensureActive()
            if (result is ApiResult.Success) nextPage = FIRST_PAGE + 1
            _uiState.update { it.copy(content = content) }
        }
    }

    private fun Paged<ProductSummary>.toContent(query: ProductQuery): ShopContent =
        if (items.isEmpty()) {
            ShopContent.Empty(filtered = query.isFiltered)
        } else {
            ShopContent.Products(items = items, canLoadMore = page.hasMore)
        }

    private suspend fun firstPageFailure(query: ProductQuery, error: ApiError, kept: ShopContent?): ShopContent = when {
        // Toko ditutup: daftar lama tidak boleh tetap tampil seolah masih dapat dibeli.
        error.isStoreUnavailable -> ShopContent.Error(error)
        kept is ShopContent.Products ->
            kept.copy(refreshing = false, loadingMore = false, loadMoreError = null, staleReason = error)
        error.isOffline && query.isDefault -> {
            val cached = repository.cachedProducts()
            if (cached.isEmpty()) ShopContent.Error(error) else ShopContent.Products(items = cached, staleReason = error)
        }
        else -> ShopContent.Error(error)
    }

    private fun startLoadMore(content: ShopContent.Products) {
        if (!content.canLoadMore || content.loadingMore || content.refreshing) return
        val query = appliedQuery
        val page = nextPage
        updateProducts { it.copy(loadingMore = true, loadMoreError = null) }
        loadJob = viewModelScope.launch {
            val result = repository.products(query, page)
            ensureActive()
            when (result) {
                is ApiResult.Success -> {
                    nextPage = page + 1
                    val loaded = result.value
                    updateProducts { current ->
                        current.copy(
                            // Daftar di server bisa bergeser di antara dua halaman: tanpa duplikat.
                            items = (current.items + loaded.items).distinctBy(ProductSummary::slug),
                            // Halaman kosong menghentikan pemuatan, apa pun kata `meta`.
                            canLoadMore = loaded.page.hasMore && loaded.items.isNotEmpty(),
                            loadingMore = false,
                        )
                    }
                }
                is ApiResult.Failure ->
                    if (result.error.isStoreUnavailable) {
                        _uiState.update { it.copy(content = ShopContent.Error(result.error)) }
                    } else {
                        updateProducts { it.copy(loadingMore = false, loadMoreError = result.error) }
                    }
            }
        }
    }

    private fun updateProducts(transform: (ShopContent.Products) -> ShopContent.Products) {
        _uiState.update { state ->
            val content = state.content as? ShopContent.Products ?: return@update state
            state.copy(content = transform(content))
        }
    }

    private fun loadCategories() {
        categoriesJob?.cancel()
        categoriesJob = viewModelScope.launch {
            when (val result = repository.categories()) {
                is ApiResult.Success -> {
                    ensureActive()
                    _uiState.update { it.copy(categories = result.value) }
                    // Kategori terpilih sudah tidak ada: filternya tidak lagi terlihat, jadi dilepas.
                    val selected = _uiState.value.selectedCategoryId
                    if (selected != null && result.value.none { it.id == selected }) onCategorySelected(null)
                }
                // Kategori hanya alat bantu: kegagalannya tidak menjadi galat layar.
                is ApiResult.Failure -> if (result.error.isOffline && _uiState.value.categories.isEmpty()) {
                    val cached = repository.cachedCategories()
                    ensureActive()
                    _uiState.update { state -> if (state.categories.isEmpty()) state.copy(categories = cached) else state }
                }
            }
        }
    }

    private fun showsOfflineData(): Boolean = when (val content = _uiState.value.content) {
        is ShopContent.Products -> content.staleReason?.isOffline == true
        is ShopContent.Error -> content.error.isOffline
        else -> false
    }

    companion object {
        const val SEARCH_DEBOUNCE_MS = 400L
        private const val FIRST_PAGE = 1
        private const val KEY_SEARCH = "catalog_search"
        private const val KEY_CATEGORY = "catalog_category_id"
        private const val KEY_SORT = "catalog_sort"
    }
}
