package com.warkop.manager.ui.stock

import androidx.lifecycle.SavedStateHandle
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.warkop.core.domain.ApiError
import com.warkop.manager.data.StockRepository
import com.warkop.manager.data.dto.LowStockDto
import com.warkop.manager.data.dto.OutletRef
import com.warkop.manager.data.dto.StockMovementDto
import com.warkop.manager.data.dto.TransferDetailDto
import com.warkop.manager.data.dto.TransferSummaryDto
import com.warkop.manager.domain.AdjustForm
import com.warkop.manager.domain.AdjustRow
import com.warkop.manager.domain.FormStatus
import com.warkop.manager.domain.ReceiveForm
import com.warkop.manager.domain.ReceiveRow
import com.warkop.manager.domain.SavedForm
import com.warkop.manager.domain.TransferForm
import com.warkop.manager.domain.TransferRow
import com.warkop.manager.ui.components.Load
import com.warkop.manager.ui.components.PagedList
import com.warkop.manager.ui.components.Pager
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch

enum class StockTab(val label: String) { MOVEMENTS("Riwayat"), LOW("Stok menipis"), TRANSFERS("Transfer") }

/**
 * 094 / T059 — layar Stok, cermin `StockView.vue` (buku besar pergerakan + daftar stok menipis) dan `TransfersView.vue`.
 * Tab Transfer hanya bila pengguna melihat lebih dari satu outlet (`/me/scope` multi), sama dengan tombol web.
 */
class StockViewModel(
    private val repo: StockRepository,
    private val handle: SavedStateHandle,
    private val onSessionError: (ApiError) -> Boolean = { false },
) : ViewModel() {
    private val _tab = MutableStateFlow(handle.get<String>("tab")?.let { runCatching { StockTab.valueOf(it) }.getOrNull() } ?: StockTab.MOVEMENTS)
    val tab: StateFlow<StockTab> = _tab.asStateFlow()

    private val _type = MutableStateFlow(handle.get<String>("type"))
    val type: StateFlow<String?> = _type.asStateFlow()

    private val _transferStatus = MutableStateFlow(handle.get<String>("tstatus"))
    val transferStatus: StateFlow<String?> = _transferStatus.asStateFlow()

    private val _outlets = MutableStateFlow<List<OutletRef>>(emptyList())
    val outlets: StateFlow<List<OutletRef>> = _outlets.asStateFlow()
    private val _multi = MutableStateFlow(false)
    val multi: StateFlow<Boolean> = _multi.asStateFlow()

    private val movementsList = PagedList(viewModelScope, onSessionError) { page -> repo.movements(_type.value, page) }
    val movements: StateFlow<Load<Pager.State<StockMovementDto>>> = movementsList.state

    private val transfersList = PagedList(viewModelScope, onSessionError) { page -> repo.transfers(_transferStatus.value, page) }
    val transfers: StateFlow<Load<Pager.State<TransferSummaryDto>>> = transfersList.state

    private val _low = MutableStateFlow<Load<List<LowStockDto>>>(Load.Loading)
    val low: StateFlow<Load<List<LowStockDto>>> = _low.asStateFlow()

    init {
        movementsList.reload(silent = false)
        viewModelScope.launch {
            runCatching { repo.scope() }.onSuccess { _outlets.value = it.outlets; _multi.value = it.multi }
        }
        if (_tab.value == StockTab.LOW) loadLow()
        if (_tab.value == StockTab.TRANSFERS) transfersList.reload(silent = false)
    }

    fun setTab(t: StockTab) {
        if (t == _tab.value) return
        _tab.value = t
        handle["tab"] = t.name
        when (t) {
            StockTab.LOW -> if (_low.value !is Load.Ready) loadLow()
            StockTab.TRANSFERS -> if (transfers.value !is Load.Ready) transfersList.reload(silent = false)
            StockTab.MOVEMENTS -> Unit
        }
    }

    fun setType(t: String?) { _type.value = t; handle["type"] = t; movementsList.reload(silent = false) }
    fun setTransferStatus(s: String?) { _transferStatus.value = s; handle["tstatus"] = s; transfersList.reload(silent = false) }

    fun refresh() = when (_tab.value) {
        StockTab.MOVEMENTS -> movementsList.reload()
        StockTab.LOW -> loadLow()
        StockTab.TRANSFERS -> transfersList.reload()
    }

    fun loadMoreMovements() = movementsList.loadMore()
    fun loadMoreTransfers() = transfersList.loadMore()

    private fun loadLow() {
        val before = _low.value
        _low.value = if (before is Load.Ready) before.copy(refreshing = true) else Load.Loading
        viewModelScope.launch {
            _low.value = try { Load.Ready(repo.low()) } catch (e: ApiError) {
                if (onSessionError(e)) return@launch
                if (before is Load.Ready) before.copy(refreshing = false) else Load.Failed(e.message)
            }
        }
    }
}

/** Pencarian bertunda 300 ms (pola `useDebouncedFn`); hasil lama dibuang bila kata kunci berubah. */
private class Search<T>(private val scope: kotlinx.coroutines.CoroutineScope, private val find: suspend (String) -> List<T>) {
    val query = MutableStateFlow("")
    val results = MutableStateFlow<List<T>>(emptyList())
    private var job: Job? = null

    fun set(q: String) {
        query.value = q
        job?.cancel()
        if (q.isBlank()) { results.value = emptyList(); return }
        job = scope.launch {
            delay(300)
            results.value = runCatching { find(q.trim()) }.getOrDefault(emptyList())
        }
    }

    fun clear() { job?.cancel(); query.value = ""; results.value = emptyList() }
}

/** 094 / T060 — koreksi stok di outlet terpilih. */
class StockAdjustViewModel(
    private val repo: StockRepository,
    handle: SavedStateHandle,
    private val outletId: Long,
    private val onSessionError: (ApiError) -> Boolean = { false },
) : ViewModel() {
    val form = SavedForm(handle, "stock.adjust", AdjustForm.serializer()) { AdjustForm() }
    private val search = Search(viewModelScope) { q -> repo.findVariants(q, outletId) }
    val query: StateFlow<String> = search.query
    val results: StateFlow<List<AdjustRow>> = search.results
    private val _status = MutableStateFlow(FormStatus())
    val status: StateFlow<FormStatus> = _status.asStateFlow()

    fun setQuery(q: String) = search.set(q)
    fun add(row: AdjustRow) { form.update { it.add(row) }; search.clear() }
    fun setReason(v: String) { form.update { it.copy(reason = v.take(AdjustForm.REASON_MAX)) }; _status.value = FormStatus() }
    fun setDelta(variantId: Long, v: String) = form.update { f -> f.copy(rows = f.rows.map { if (it.variantId == variantId) it.copy(delta = v) else it }) }
    fun remove(variantId: Long) = form.update { f -> f.copy(rows = f.rows.filterNot { it.variantId == variantId }) }

    fun submit(onDone: () -> Unit) {
        val f = form.current
        if (_status.value.saving) return
        if (!f.canSubmit) {
            _status.value = FormStatus(fieldErrors = buildMap {
                if (f.reason.isBlank()) put("reason", "Alasan wajib diisi.")
                if (f.deltas().isEmpty()) put("items", "Tambahkan minimal satu item dengan selisih bukan nol.")
            })
            return
        }
        _status.value = FormStatus.saving()
        viewModelScope.launch {
            try {
                repo.adjust(f.toBody(outletId))
                form.set(AdjustForm())
                _status.value = FormStatus()
                onDone()
            } catch (e: ApiError) {
                if (!onSessionError(e)) _status.value = FormStatus.from(e)
            }
        }
    }
}

/** 094 / T061 — transfer baru dari outlet terpilih (asal dikunci: penugasan outlet di server hanya mengakui outlet header). */
class NewTransferViewModel(
    private val repo: StockRepository,
    handle: SavedStateHandle,
    val fromOutletId: Long,
    private val onSessionError: (ApiError) -> Boolean = { false },
) : ViewModel() {
    val form = SavedForm(handle, "stock.transfer", TransferForm.serializer()) { TransferForm() }
    private val _kind = MutableStateFlow("variant")
    val kind: StateFlow<String> = _kind.asStateFlow()
    private val search = Search(viewModelScope) { q -> repo.findTransferItems(_kind.value, q, fromOutletId) }
    val query: StateFlow<String> = search.query
    val results: StateFlow<List<TransferRow>> = search.results
    private val _destinations = MutableStateFlow<List<OutletRef>>(emptyList())
    val destinations: StateFlow<List<OutletRef>> = _destinations.asStateFlow()
    private val _status = MutableStateFlow(FormStatus())
    val status: StateFlow<FormStatus> = _status.asStateFlow()

    init {
        viewModelScope.launch { _destinations.value = runCatching { repo.scope().outlets }.getOrDefault(emptyList()).filter { it.id != fromOutletId } }
    }

    fun setKind(k: String) { _kind.value = k; search.clear() }
    fun setQuery(q: String) = search.set(q)
    fun add(row: TransferRow) { form.update { it.add(row) }; search.clear() }
    fun setTo(id: Long?) { form.update { it.copy(toOutletId = id) }; _status.value = FormStatus() }
    fun setNote(v: String) = form.update { it.copy(note = v.take(TransferForm.NOTE_MAX)) }
    fun setQty(index: Int, v: String) = form.update { f -> f.copy(rows = f.rows.mapIndexed { i, r -> if (i == index) r.copy(qty = v) else r }) }
    fun remove(index: Int) = form.update { f -> f.copy(rows = f.rows.filterIndexed { i, _ -> i != index }) }

    fun submit(onDone: (String) -> Unit) {
        val f = form.current
        if (_status.value.saving || !f.canSubmit(fromOutletId)) return
        _status.value = FormStatus.saving()
        viewModelScope.launch {
            try {
                val res = repo.createTransfer(f.toBody(fromOutletId))
                form.set(TransferForm())
                _status.value = FormStatus()
                onDone(res.number)
            } catch (e: ApiError) {
                // clientRef SENGAJA dipertahankan: kirim ulang (mis. setelah timeout) dijawab server 200 tanpa transfer kedua.
                if (!onSessionError(e)) _status.value = FormStatus.from(e)
            }
        }
    }
}

/** 094 / T061 — detail transfer: terima (penuh/sebagian dengan alasan) dan batal, hanya bila `can` server mengizinkan. */
class TransferDetailViewModel(
    private val repo: StockRepository,
    handle: SavedStateHandle,
    private val transferId: Long,
    private val onSessionError: (ApiError) -> Boolean = { false },
) : ViewModel() {
    private val _detail = MutableStateFlow<Load<TransferDetailDto>>(Load.Loading)
    val detail: StateFlow<Load<TransferDetailDto>> = _detail.asStateFlow()
    val receive = SavedForm(handle, "stock.receive", ReceiveForm.serializer()) { ReceiveForm() }
    private val _status = MutableStateFlow(FormStatus())
    val status: StateFlow<FormStatus> = _status.asStateFlow()

    init { load() }

    fun load() {
        viewModelScope.launch {
            _detail.value = try {
                repo.transfer(transferId).also { d -> if (receive.current.rows.isEmpty()) receive.set(ReceiveForm(d.lines.map(ReceiveRow::of))) }.let { Load.Ready(it) }
            } catch (e: ApiError) {
                if (onSessionError(e)) return@launch
                Load.Failed(e.message)
            }
        }
    }

    fun setQty(lineId: Long, v: String) = receive.update { f -> f.copy(rows = f.rows.map { if (it.lineId == lineId) it.copy(qty = v) else it }) }
    fun setReason(lineId: Long, v: String) = receive.update { f -> f.copy(rows = f.rows.map { if (it.lineId == lineId) it.copy(reason = v.take(255)) else it }) }

    fun submitReceive(onDone: () -> Unit) = act(onDone) { repo.receive(transferId, receive.current.toBody()) }
    fun cancel(onDone: () -> Unit) = act(onDone) { repo.cancel(transferId) }

    private fun act(onDone: () -> Unit, call: suspend () -> Unit) {
        if (_status.value.saving) return
        _status.value = FormStatus.saving()
        viewModelScope.launch {
            try {
                call()
                _status.value = FormStatus()
                receive.set(ReceiveForm())
                load()
                onDone()
            } catch (e: ApiError) {
                if (!onSessionError(e)) _status.value = FormStatus.from(e)
            }
        }
    }
}
