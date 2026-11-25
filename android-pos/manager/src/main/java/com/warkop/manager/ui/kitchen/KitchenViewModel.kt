package com.warkop.manager.ui.kitchen

import androidx.lifecycle.SavedStateHandle
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.warkop.core.domain.ApiError
import com.warkop.core.network.apiCall
import com.warkop.manager.data.ManagerApi
import com.warkop.manager.data.apiUnit
import com.warkop.manager.data.dto.StationDto
import com.warkop.manager.data.dto.TicketDto
import com.warkop.manager.data.dto.TicketStatusBody
import com.warkop.manager.domain.KitchenRules
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch
import kotlinx.coroutines.sync.Mutex
import kotlinx.coroutines.sync.withLock

data class KitchenState(
    val stations: List<StationDto>? = null,
    val stationId: Long? = null,
    val tickets: List<TicketDto>? = null,
    /** Galat muat terakhir; tiket lama tetap tampil (web: banner "mencoba lagi otomatis"). */
    val loadError: String? = null,
    val busy: Boolean = false,
    val actionError: String? = null,
)

/**
 * 094 / T088 — papan Dapur & Bar. Polling `GET /stations/{id}/tickets?version=` tiap 5 dtk HANYA selama layar tampil
 * ([pollLoop] dipanggil di dalam `repeatOnLifecycle(RESUMED)` — dibatalkan otomatis saat ke belakang), pola
 * `SelfServicePoller` aplikasi kasir. Satu permintaan pada satu waktu ([fetchLock]); hasil untuk stasiun lama dibuang.
 */
class KitchenViewModel(
    private val api: () -> ManagerApi,
    private val handle: SavedStateHandle,
    private val onSessionError: (ApiError) -> Boolean = { false },
) : ViewModel() {
    private val _state = MutableStateFlow(KitchenState(stationId = handle.get<Long>("station")))
    val state: StateFlow<KitchenState> = _state.asStateFlow()
    private var version: String? = null
    private val fetchLock = Mutex()

    init { loadStations() }

    fun loadStations() {
        viewModelScope.launch {
            try {
                val list = apiCall { api().stations() }.data
                val current = _state.value.stationId?.takeIf { id -> list.any { it.id == id } } ?: list.firstOrNull()?.id
                _state.value = _state.value.copy(stations = list, stationId = current)
                handle["station"] = current
            } catch (e: ApiError) {
                if (!onSessionError(e)) _state.value = _state.value.copy(stations = emptyList(), loadError = e.message)
            }
        }
    }

    fun selectStation(id: Long) {
        if (id == _state.value.stationId) return
        version = null
        handle["station"] = id
        _state.value = _state.value.copy(stationId = id, tickets = null, loadError = null)
        viewModelScope.launch { fetch(full = true) }
    }

    /** Putaran polling selama layar tampil. Mulai dengan muat penuh (data mungkin basi selama di belakang). */
    suspend fun pollLoop() {
        var failures = 0
        var full = true
        while (true) {
            val ok = fetch(full)
            failures = if (ok) 0 else failures + 1
            full = !ok // setelah gagal, muat berikutnya penuh (pola web)
            delay(KitchenRules.pollDelayMs(failures))
        }
    }

    /** @return true bila berhasil (termasuk "tidak berubah"). */
    suspend fun fetch(full: Boolean): Boolean = fetchLock.withLock {
        val station = _state.value.stationId ?: return@withLock true
        try {
            val res = apiCall { api().tickets(station, if (full) null else version) }
            if (station != _state.value.stationId) return@withLock true // stasiun berganti saat permintaan berjalan
            version = res.version
            if (!res.unchanged) _state.value = _state.value.copy(tickets = res.tickets.orEmpty(), loadError = null)
            else if (_state.value.loadError != null) _state.value = _state.value.copy(loadError = null)
            true
        } catch (e: ApiError) {
            if (onSessionError(e)) return@withLock false
            _state.value = _state.value.copy(loadError = "Tidak dapat memuat tiket. Mencoba lagi otomatis…")
            false
        }
    }

    /** Maju satu langkah. Sukses → muat penuh; 403/409 → pesan + muat ulang (status mungkin sudah berubah di perangkat lain). */
    fun advance(itemId: Long, to: String) {
        if (_state.value.busy) return
        _state.value = _state.value.copy(busy = true, actionError = null)
        viewModelScope.launch {
            try {
                apiUnit { api().setTicketItemStatus(itemId, TicketStatusBody(to)) }
            } catch (e: ApiError) {
                if (onSessionError(e)) return@launch
                _state.value = _state.value.copy(actionError = e.message)
            }
            version = null
            fetch(full = true)
            _state.value = _state.value.copy(busy = false)
        }
    }
}
