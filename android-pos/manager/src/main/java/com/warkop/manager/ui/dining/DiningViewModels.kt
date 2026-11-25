package com.warkop.manager.ui.dining

import androidx.lifecycle.SavedStateHandle
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.warkop.core.domain.ApiError
import com.warkop.manager.data.RestaurantRepository
import com.warkop.manager.data.dto.AttachBody
import com.warkop.manager.data.dto.DiningTableDto
import com.warkop.manager.data.dto.ModifierGroupDto
import com.warkop.manager.data.dto.OutletRef
import com.warkop.manager.data.dto.StationDto
import com.warkop.manager.domain.FormStatus
import com.warkop.manager.domain.ModifierGroupForm
import com.warkop.manager.domain.OptionMaterial
import com.warkop.manager.domain.OptionRow
import com.warkop.manager.domain.SavedForm
import com.warkop.manager.domain.StationForm
import com.warkop.manager.domain.TableForm
import com.warkop.manager.ui.components.Load
import com.warkop.manager.ui.components.PagedList
import com.warkop.manager.ui.components.Pager
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch

enum class DiningTab(val label: String) { TABLES("Meja"), STATIONS("Stasiun"), MODIFIERS("Modifier") }

private fun ViewModel.act(status: MutableStateFlow<FormStatus>, onSessionError: (ApiError) -> Boolean, done: () -> Unit, call: suspend () -> Unit) {
    if (status.value.saving) return
    status.value = FormStatus.saving()
    viewModelScope.launch {
        try { call(); status.value = FormStatus(); done() } catch (e: ApiError) { if (!onSessionError(e)) status.value = FormStatus.from(e) }
    }
}

/** 094 / T090–T092 — daftar tiga tab Pengaturan Restoran. Meja & stasiun = outlet terpilih; grup modifier tanpa outlet. */
class DiningViewModel(
    private val repo: RestaurantRepository,
    private val handle: SavedStateHandle,
    private val outletId: Long,
    private val onSessionError: (ApiError) -> Boolean = { false },
) : ViewModel() {
    private val _tab = MutableStateFlow(handle.get<String>("tab")?.let { runCatching { DiningTab.valueOf(it) }.getOrNull() } ?: DiningTab.TABLES)
    val tab: StateFlow<DiningTab> = _tab.asStateFlow()
    private val _q = MutableStateFlow(handle.get<String>("q").orEmpty())
    val q: StateFlow<String> = _q.asStateFlow()
    private val tablePages = PagedList(viewModelScope, onSessionError) { page -> repo.tables(outletId, _q.value, page) }
    val tables: StateFlow<Load<Pager.State<DiningTableDto>>> = tablePages.state
    private val _stations = MutableStateFlow<Load<List<StationDto>>>(Load.Loading)
    val stations: StateFlow<Load<List<StationDto>>> = _stations.asStateFlow()
    private val _groups = MutableStateFlow<Load<List<ModifierGroupDto>>>(Load.Loading)
    val groups: StateFlow<Load<List<ModifierGroupDto>>> = _groups.asStateFlow()

    init { tablePages.reload(silent = false); loadStations(); loadGroups() }

    fun setTab(t: DiningTab) { _tab.value = t; handle["tab"] = t.name }
    fun setQuery(v: String) { _q.value = v; handle["q"] = v; tablePages.reload(silent = false, debounceMs = 300) }
    fun reloadTables() = tablePages.reload()
    fun moreTables() = tablePages.loadMore()

    fun loadStations() = loadInto(_stations) { repo.stations(outletId) }
    fun loadGroups() = loadInto(_groups) { repo.groups() }

    private fun <T> loadInto(flow: MutableStateFlow<Load<T>>, fetch: suspend () -> T) {
        viewModelScope.launch {
            val before = flow.value
            if (before is Load.Ready) flow.value = before.copy(refreshing = true)
            flow.value = try { Load.Ready(fetch()) } catch (e: ApiError) {
                if (onSessionError(e)) return@launch
                if (before is Load.Ready) before.copy(refreshing = false) else Load.Failed(e.message)
            }
        }
    }

    fun refreshAll() { reloadTables(); loadStations(); loadGroups() }
}

/** 094 / T090 — formulir Meja + QR (alamat dari server; gambar QR dibuat web di klien — di aplikasi dibagikan sebagai tautan). */
class TableFormViewModel(
    private val repo: RestaurantRepository,
    handle: SavedStateHandle,
    private val initial: DiningTableDto?,
    private val outletId: Long,
    private val onSessionError: (ApiError) -> Boolean = { false },
) : ViewModel() {
    val form = SavedForm(handle, "table.form", TableForm.serializer()) { initial?.let(TableForm::of) ?: TableForm(outletId = outletId) }
    private val _status = MutableStateFlow(FormStatus())
    val status: StateFlow<FormStatus> = _status.asStateFlow()
    private val _outlets = MutableStateFlow<List<OutletRef>>(emptyList())
    val outlets: StateFlow<List<OutletRef>> = _outlets.asStateFlow()
    private val _qr = MutableStateFlow<String?>(null)
    val qr: StateFlow<String?> = _qr.asStateFlow()

    init { viewModelScope.launch { _outlets.value = runCatching { repo.outlets() }.getOrDefault(emptyList()) } }

    fun edit(f: (TableForm) -> TableForm) { form.update(f); if (_status.value.fieldErrors.isNotEmpty()) _status.value = FormStatus() }

    fun save(done: () -> Unit) {
        val f = form.current
        f.localErrors().takeIf { it.isNotEmpty() }?.let { _status.value = FormStatus(fieldErrors = it); return }
        act(_status, onSessionError, done) { form.set(TableForm.of(repo.saveTable(f.id, f.toBody()))) }
    }

    fun delete(done: () -> Unit) { val id = form.current.id ?: return; act(_status, onSessionError, done) { repo.deleteTable(id) } }
    fun loadQr() { val id = form.current.id ?: return; act(_status, onSessionError, {}) { _qr.value = repo.tableQrUrl(id) } }
    /** QR lama berhenti berlaku. */
    fun rotateQr() { val id = form.current.id ?: return; act(_status, onSessionError, {}) { _qr.value = repo.rotateTableQr(id) } }
}

/** 094 / T091 — formulir Stasiun. */
class StationFormViewModel(
    private val repo: RestaurantRepository,
    handle: SavedStateHandle,
    private val initial: StationDto?,
    private val outletId: Long,
    nextOrder: Int,
    private val onSessionError: (ApiError) -> Boolean = { false },
) : ViewModel() {
    val form = SavedForm(handle, "station.form", StationForm.serializer()) { initial?.let(StationForm::of) ?: StationForm(displayOrder = nextOrder.toString(), outletId = outletId) }
    private val _status = MutableStateFlow(FormStatus())
    val status: StateFlow<FormStatus> = _status.asStateFlow()
    private val _outlets = MutableStateFlow<List<OutletRef>>(emptyList())
    val outlets: StateFlow<List<OutletRef>> = _outlets.asStateFlow()

    init { viewModelScope.launch { _outlets.value = runCatching { repo.outlets() }.getOrDefault(emptyList()) } }

    fun edit(f: (StationForm) -> StationForm) { form.update(f); if (_status.value.fieldErrors.isNotEmpty()) _status.value = FormStatus() }
    fun save(done: () -> Unit) {
        val f = form.current
        f.localErrors().takeIf { it.isNotEmpty() }?.let { _status.value = FormStatus(fieldErrors = it); return }
        act(_status, onSessionError, done) { form.set(StationForm.of(repo.saveStation(f.id, f.toBody()))) }
    }
    fun delete(done: () -> Unit) { val id = form.current.id ?: return; act(_status, onSessionError, done) { repo.deleteStation(id) } }
}

/** 094 / T092 — formulir grup modifier + bahan per opsi. */
class GroupFormViewModel(
    private val repo: RestaurantRepository,
    handle: SavedStateHandle,
    initial: ModifierGroupDto?,
    private val onSessionError: (ApiError) -> Boolean = { false },
) : ViewModel() {
    val form = SavedForm(handle, "group.form", ModifierGroupForm.serializer()) { initial?.let(ModifierGroupForm::of) ?: ModifierGroupForm() }
    private val _status = MutableStateFlow(FormStatus())
    val status: StateFlow<FormStatus> = _status.asStateFlow()
    /** Opsi yang sedang mencari bahan + hasil. */
    val searchOption = MutableStateFlow<String?>(null)
    private val _results = MutableStateFlow<List<OptionMaterial>>(emptyList())
    val results: StateFlow<List<OptionMaterial>> = _results.asStateFlow()
    private var job: Job? = null

    fun edit(f: (ModifierGroupForm) -> ModifierGroupForm) { form.update(f); if (_status.value.fieldErrors.isNotEmpty()) _status.value = FormStatus() }
    fun editOption(key: String, f: (OptionRow) -> OptionRow) = edit { g -> g.copy(options = g.options.map { if (it.key == key) f(it) else it }) }

    fun search(q: String) {
        job?.cancel()
        if (q.isBlank()) { _results.value = emptyList(); return }
        job = viewModelScope.launch { delay(300); _results.value = runCatching { repo.searchMaterials(q.trim()) }.getOrDefault(emptyList()) }
    }

    fun pickMaterial(m: OptionMaterial) {
        val key = searchOption.value ?: return
        editOption(key) { o -> if (o.materials.any { it.materialId == m.materialId }) o else o.copy(materials = o.materials + m.copy(quantity = "1")) }
        searchOption.value = null; _results.value = emptyList()
    }

    fun save(done: () -> Unit) {
        val f = form.current
        f.localErrors().takeIf { it.isNotEmpty() }?.let { _status.value = FormStatus(fieldErrors = it, message = "Periksa isian yang ditandai."); return }
        act(_status, onSessionError, done) { form.set(ModifierGroupForm.of(repo.saveGroup(f.id, f.toJson()))) }
    }

    /** 409 bila grup pernah dipakai order — nonaktifkan saja. */
    fun delete(done: () -> Unit) { val id = form.current.id ?: return; act(_status, onSessionError, done) { repo.deleteGroup(id) } }
}

/** 094 / T092 — dialog Attach: PENGGANTIAN PENUH daftar produk & kategori. Kosong = "Tidak ada" (091). */
class AttachViewModel(
    private val repo: RestaurantRepository,
    private val group: ModifierGroupDto,
    private val onSessionError: (ApiError) -> Boolean = { false },
) : ViewModel() {
    val categoryIds = MutableStateFlow(group.categoryIds.toSet())
    val productIds = MutableStateFlow(group.productIds.toSet())
    private val _choices = MutableStateFlow<Pair<List<Pair<Long, String>>, List<Pair<Long, String>>>?>(null)
    val choices: StateFlow<Pair<List<Pair<Long, String>>, List<Pair<Long, String>>>?> = _choices.asStateFlow()
    private val _status = MutableStateFlow(FormStatus())
    val status: StateFlow<FormStatus> = _status.asStateFlow()

    init {
        viewModelScope.launch {
            _choices.value = try { repo.attachChoices() } catch (e: ApiError) { if (!onSessionError(e)) _status.value = FormStatus(message = e.message); emptyList<Pair<Long, String>>() to emptyList() }
        }
    }

    fun save(done: () -> Unit) = act(_status, onSessionError, done) { repo.attach(group.id, AttachBody(productIds.value.sorted(), categoryIds.value.sorted())) }
}
