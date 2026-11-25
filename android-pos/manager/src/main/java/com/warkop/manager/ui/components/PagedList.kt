package com.warkop.manager.ui.components

import com.warkop.core.domain.ApiError
import com.warkop.manager.data.dto.Paged
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

/**
 * 094 — satu pengurus daftar berhalaman untuk semua ViewModel (pola `usePaginatedList` web): muat pertama, segarkan
 * senyap (data lama dipertahankan bila gagal), muat-lanjut, dan pembatalan permintaan lama saat filter berganti.
 */
class PagedList<T>(
    private val scope: CoroutineScope,
    private val onSessionError: (ApiError) -> Boolean,
    fetch: suspend (page: Int) -> Paged<T>,
) {
    private val pager = Pager(fetch)
    private val _state = MutableStateFlow<Load<Pager.State<T>>>(Load.Loading)
    val state: StateFlow<Load<Pager.State<T>>> = _state.asStateFlow()
    private var job: Job? = null

    /** Muat ulang halaman 1. [debounceMs] untuk kotak cari; filter baru menampilkan "memuat", segarkan biasa senyap. */
    fun reload(silent: Boolean = _state.value is Load.Ready, debounceMs: Long = 0) {
        job?.cancel()
        job = scope.launch {
            if (debounceMs > 0) delay(debounceMs)
            val before = _state.value
            _state.value = if (silent && before is Load.Ready) before.copy(refreshing = true) else Load.Loading
            _state.value = try {
                Load.Ready(pager.first())
            } catch (e: ApiError) {
                if (onSessionError(e)) return@launch
                if (silent && before is Load.Ready) before.copy(refreshing = false) else Load.Failed(e.message)
            }
        }
    }

    fun loadMore() {
        val cur = (_state.value as? Load.Ready)?.data ?: return
        if (cur.loadingMore || !cur.hasMore || job?.isActive == true) return
        _state.update { it.withLoadingMore(true) }
        job = scope.launch {
            try {
                _state.value = Load.Ready(pager.next(cur))
            } catch (e: ApiError) {
                if (!onSessionError(e)) _state.update { it.withLoadingMore(false) }
            }
        }
    }
}
