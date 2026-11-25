package com.warkop.manager.ui.dashboard

import androidx.lifecycle.SavedStateHandle
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.warkop.core.domain.ApiError
import com.warkop.manager.data.DashboardRepository
import com.warkop.manager.data.MaterialStats
import com.warkop.manager.data.dto.AttentionDto
import com.warkop.manager.data.dto.DashboardOrdersDto
import com.warkop.manager.data.dto.OpenShiftsDto
import com.warkop.manager.data.dto.RevenueDto
import com.warkop.manager.domain.DashboardModel
import com.warkop.manager.domain.KpiCard
import com.warkop.manager.domain.Period
import com.warkop.manager.ui.components.Load
import kotlinx.coroutines.Job
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

data class Summary(val cards: List<KpiCard>, val revenue: RevenueDto?, val businessDate: String?, val computedAt: String?)

data class DashboardState(
    val period: Period = Period.TODAY,
    val summary: Load<Summary> = Load.Loading,
    val materials: Load<MaterialStats> = Load.Loading,
    val attention: Load<AttentionDto> = Load.Loading,
    val shifts: Load<OpenShiftsDto> = Load.Loading,
    val orders: Load<DashboardOrdersDto> = Load.Loading,
) {
    val refreshing: Boolean get() = listOf(summary, materials, attention, shifts, orders).any { it is Load.Ready<*> && it.refreshing }
}

/**
 * 094 / T052 — Dashboard, cermin `DashboardView.vue`. Tiap wilayah punya keadaan sendiri: gagalnya satu tidak
 * mengosongkan yang lain. Penyegaran SENYAP (tarik atau otomatis) mempertahankan data lama bila gagal; galat penuh hanya
 * bila belum ada data sama sekali. "Perlu tindakan" dan "Shift terbuka" menggambarkan hari ini, jadi TIDAK ikut periode.
 *
 * Keputusan pengembang 2026-11-25: TANPA penyegaran otomatis (web: tiap 30 dtk). Satu putaran = 5 permintaan ke server toko
 * yang melayani hampir berurutan, dan HP manajer sering di data seluler; data diperbarui saat layar dibuka dan lewat
 * tarik-untuk-segarkan.
 */
class DashboardViewModel(
    private val repo: DashboardRepository,
    private val handle: SavedStateHandle,
    /** Dipanggil bila galat berarti sesi berakhir (401 / 403 manager_app_denied) — kerangka membawa ke login. */
    private val onSessionError: (ApiError) -> Boolean = { false },
) : ViewModel() {
    private val _state = MutableStateFlow(DashboardState(period = handle.get<String>(PERIOD)?.let { q -> Period.entries.firstOrNull { it.query == q } } ?: Period.TODAY))
    val state: StateFlow<DashboardState> = _state.asStateFlow()

    private var periodJob: Job? = null

    init { loadAll(silent = false) }

    fun setPeriod(p: Period) {
        if (p == _state.value.period) return
        handle[PERIOD] = p.query
        _state.update { it.copy(period = p, summary = Load.Loading, materials = Load.Loading, orders = Load.Loading) }
        periodJob?.cancel()
        // Respons periode lama yang tiba belakangan tidak boleh menimpa periode baru (pola requestSeq di web).
        periodJob = viewModelScope.launch { loadPeriodRegions(silent = false) }
    }

    fun refresh() = loadAll(silent = true)

    private fun loadAll(silent: Boolean) {
        periodJob?.cancel()
        periodJob = viewModelScope.launch { loadPeriodRegions(silent) }
        viewModelScope.launch { region(silent, { attention }, { s, v -> s.copy(attention = v) }) { repo.attention() } }
        viewModelScope.launch { region(silent, { shifts }, { s, v -> s.copy(shifts = v) }) { repo.shifts() } }
    }

    private suspend fun loadPeriodRegions(silent: Boolean) {
        val p = _state.value.period
        region(silent, { summary }, { s, v -> s.copy(summary = v) }) {
            val dto = repo.summary(p)
            Summary(DashboardModel.cards(dto), dto.revenue, dto.businessDate, dto.computedAt)
        }
        region(silent, { materials }, { s, v -> s.copy(materials = v) }) { repo.materials(p) }
        region(silent, { orders }, { s, v -> s.copy(orders = v) }) { repo.orders(p) }
    }

    private suspend fun <T> region(
        silent: Boolean,
        get: DashboardState.() -> Load<T>,
        set: (DashboardState, Load<T>) -> DashboardState,
        fetch: suspend () -> T,
    ) {
        val before = _state.value.get()
        if (silent && before is Load.Ready) _state.update { set(it, before.copy(refreshing = true)) }
        else if (!silent) _state.update { set(it, Load.Loading) }
        val next: Load<T> = try {
            Load.Ready(fetch())
        } catch (e: ApiError) {
            if (onSessionError(e)) return
            val current = _state.value.get()
            if (current is Load.Ready) current.copy(refreshing = false) else Load.Failed(e.message)
        }
        _state.update { set(it, next) }
    }

    companion object {
        private const val PERIOD = "dashboard.period"
    }
}
