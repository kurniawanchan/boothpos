package com.warkop.manager.ui.products

import androidx.lifecycle.SavedStateHandle
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.warkop.core.domain.ApiError
import com.warkop.manager.data.ProductRepository
import com.warkop.manager.data.dto.CategoryDto
import com.warkop.manager.data.dto.OutletPriceBody
import com.warkop.manager.data.dto.OutletPricesDto
import com.warkop.manager.data.dto.OutletRef
import com.warkop.manager.data.dto.PriceHistoryDto
import com.warkop.manager.data.dto.ProductDetailDto
import com.warkop.manager.data.dto.ProductRowDto
import com.warkop.manager.data.dto.ProductSummaryDto
import com.warkop.manager.data.dto.ReasonBody
import com.warkop.manager.data.dto.ReasonCodeDto
import com.warkop.manager.data.dto.StationDto
import com.warkop.manager.data.dto.TabCounts
import com.warkop.manager.domain.FormStatus
import com.warkop.manager.domain.ProductForm
import com.warkop.manager.domain.ProductFormMapping
import com.warkop.manager.domain.ProductSaveOrchestrator
import com.warkop.manager.domain.SavedForm
import com.warkop.manager.domain.VariantRow
import com.warkop.manager.ui.components.Load
import com.warkop.manager.ui.components.PagedList
import com.warkop.manager.ui.components.Pager
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch

/** Tab yang dilayani `GET /products` (tab bahan/modifier web dilayani layar lain di aplikasi). */
enum class ProductTab(val query: String, val label: String) { MENU("menu", "Menu item"), RETAIL("retail", "Item ritel") }

val PRODUCT_STATUS = linkedMapOf("draft" to "Draft", "active" to "Aktif", "discontinued" to "Discontinued")

/**
 * 094 / T063 — daftar Produk, cermin `ProductsView.vue` untuk tab menu/ritel: cari, saring status, angka kepala dari
 * `/products/summary`. Outlet = outlet terpilih, dikirim lewat `outlet_id[]` TANPA header (pola 070).
 */
class ProductsViewModel(
    private val repo: ProductRepository,
    private val handle: SavedStateHandle,
    private val outletId: Long,
    onSessionError: (ApiError) -> Boolean = { false },
) : ViewModel() {
    private val _tab = MutableStateFlow(handle.get<String>("tab")?.let { runCatching { ProductTab.valueOf(it) }.getOrNull() } ?: ProductTab.MENU)
    val tab: StateFlow<ProductTab> = _tab.asStateFlow()
    private val _q = MutableStateFlow(handle.get<String>("q").orEmpty())
    val q: StateFlow<String> = _q.asStateFlow()
    private val _status = MutableStateFlow(handle.get<String>("status"))
    val status: StateFlow<String?> = _status.asStateFlow()
    private val _counts = MutableStateFlow(TabCounts())
    val counts: StateFlow<TabCounts> = _counts.asStateFlow()
    private val _summary = MutableStateFlow<ProductSummaryDto?>(null)
    val summary: StateFlow<ProductSummaryDto?> = _summary.asStateFlow()

    private val list = PagedList(viewModelScope, onSessionError) { page ->
        val res = repo.page(_tab.value.query, listOf(outletId), _q.value, listOfNotNull(_status.value), page)
        _counts.value = res.counts
        com.warkop.manager.data.dto.Paged(res.data, res.meta)
    }
    val rows: StateFlow<Load<Pager.State<ProductRowDto>>> = list.state

    init { list.reload(silent = false); loadSummary() }

    private fun loadSummary() { viewModelScope.launch { _summary.value = runCatching { repo.summary(listOf(outletId)) }.getOrNull() ?: _summary.value } }

    fun setTab(t: ProductTab) { _tab.value = t; handle["tab"] = t.name; list.reload(silent = false) }
    fun setQuery(v: String) { _q.value = v; handle["q"] = v; list.reload(silent = false, debounceMs = 300) }
    fun setStatus(s: String?) { _status.value = s; handle["status"] = s; list.reload(silent = false) }
    fun refresh() { list.reload(); loadSummary() }
    fun loadMore() = list.loadMore()
}

/** 094 / T064 — detail produk + aksi 86/discontinue/aktifkan lagi (tombol mengikuti `can` dari server). */
class ProductDetailViewModel(
    private val repo: ProductRepository,
    private val productId: Long,
    private val onSessionError: (ApiError) -> Boolean = { false },
) : ViewModel() {
    private val _detail = MutableStateFlow<Load<ProductDetailDto>>(Load.Loading)
    val detail: StateFlow<Load<ProductDetailDto>> = _detail.asStateFlow()
    private val _history = MutableStateFlow<List<PriceHistoryDto>>(emptyList())
    val history: StateFlow<List<PriceHistoryDto>> = _history.asStateFlow()
    private val _reasons = MutableStateFlow<List<ReasonCodeDto>>(emptyList())
    val reasons: StateFlow<List<ReasonCodeDto>> = _reasons.asStateFlow()
    private val _status = MutableStateFlow(FormStatus())
    val status: StateFlow<FormStatus> = _status.asStateFlow()

    /** Dipanggil layar SETIAP kali panel detail tampil (kembali dari formulir/tautan/resep = data segar). */
    fun load() {
        viewModelScope.launch {
            val before = _detail.value
            if (before is Load.Ready) _detail.value = before.copy(refreshing = true)
            _detail.value = try { Load.Ready(repo.detail(productId)) } catch (e: ApiError) {
                if (onSessionError(e)) return@launch
                if (before is Load.Ready) before.copy(refreshing = false) else Load.Failed(e.message)
            }
            _history.value = runCatching { repo.priceHistory(productId, 1).data }.getOrDefault(_history.value)
        }
    }

    /** Muat alasan untuk dialog (`sold_out` atau `discontinue`). */
    fun loadReasons(category: String) {
        _reasons.value = emptyList()
        viewModelScope.launch { _reasons.value = runCatching { repo.reasonCodes(category) }.getOrDefault(emptyList()) }
    }

    fun markSoldOut(reasonId: Long, note: String?, done: () -> Unit) = act(done) { repo.markSoldOut(productId, ReasonBody(reasonId, note?.trim()?.ifEmpty { null })) }
    fun undoSoldOut(done: () -> Unit) = act(done) { repo.undoSoldOut(productId) }
    fun discontinue(reasonId: Long, note: String?, done: () -> Unit) = act(done) { repo.discontinue(productId, ReasonBody(reasonId, note?.trim()?.ifEmpty { null })) }
    fun reactivate(done: () -> Unit) = act(done) { repo.reactivate(productId) }

    private fun act(done: () -> Unit, call: suspend () -> Unit) {
        if (_status.value.saving) return
        _status.value = FormStatus.saving()
        viewModelScope.launch {
            try { call(); _status.value = FormStatus(); load(); done() } catch (e: ApiError) {
                if (!onSessionError(e)) _status.value = FormStatus.from(e)
            }
        }
    }
}

/** 094 / T065 — formulir produk (buat/ubah) dengan simpan bertahap [ProductSaveOrchestrator]. */
class ProductFormViewModel(
    private val repo: ProductRepository,
    handle: SavedStateHandle,
    private val productId: Long?,
    private val outletId: Long,
    private val canSeeCost: Boolean,
    private val onSessionError: (ApiError) -> Boolean = { false },
) : ViewModel() {
    val form = SavedForm(handle, "product.form", ProductForm.serializer()) { ProductForm(homeOutletId = outletId, outletIds = setOf(outletId)) }
    private val _status = MutableStateFlow(FormStatus())
    val status: StateFlow<FormStatus> = _status.asStateFlow()
    private val _warnings = MutableStateFlow<List<String>>(emptyList())
    val warnings: StateFlow<List<String>> = _warnings.asStateFlow()
    private val _loading = MutableStateFlow(productId != null && !form.restored)
    val loading: StateFlow<Boolean> = _loading.asStateFlow()

    private val _categories = MutableStateFlow<List<CategoryDto>>(emptyList())
    val categories: StateFlow<List<CategoryDto>> = _categories.asStateFlow()
    private val _stations = MutableStateFlow<List<StationDto>>(emptyList())
    val stations: StateFlow<List<StationDto>> = _stations.asStateFlow()
    private val _outlets = MutableStateFlow<List<OutletRef>>(emptyList())
    val outlets: StateFlow<List<OutletRef>> = _outlets.asStateFlow()
    private val _multi = MutableStateFlow(false)
    val multi: StateFlow<Boolean> = _multi.asStateFlow()

    init {
        viewModelScope.launch { _categories.value = runCatching { repo.categories() }.getOrDefault(emptyList()) }
        viewModelScope.launch { _stations.value = runCatching { repo.stations() }.getOrDefault(emptyList()) }
        viewModelScope.launch { runCatching { repo.outlets() }.onSuccess { (o, m) -> _outlets.value = o; _multi.value = m } }
        if (_loading.value && productId != null) viewModelScope.launch {
            try { form.set(ProductFormMapping.fromDetail(repo.detail(productId), outletId)) } catch (e: ApiError) {
                if (!onSessionError(e)) _status.value = FormStatus(message = e.message)
            }
            _loading.value = false
        }
    }

    fun edit(f: (ProductForm) -> ProductForm) {
        form.update(f)
        if (_status.value.fieldErrors.isNotEmpty()) _status.value = _status.value.copy(fieldErrors = emptyMap())
    }

    fun editVariant(key: String, f: (VariantRow) -> VariantRow) = edit { p -> p.copy(variants = p.variants.map { if (it.key == key) f(it) else it }) }

    /** Baris baru saat ubah: nama "Standard", harga 0 (FD:336). Saat buat: kosong (FD:67). */
    fun addVariant() = edit { p -> p.copy(variants = p.variants + if (p.editing) VariantRow(name = "Standard", sellPrice = "0", currentStock = "0", originalStock = "0") else VariantRow()) }

    /** Baris tersimpan hanya dinonaktifkan (web: tombol x = is_active false); baris baru dibuang. */
    fun removeVariant(key: String) = edit { p ->
        val row = p.variants.first { it.key == key }
        if (row.id != null) p.copy(variants = p.variants.map { if (it.key == key) it.copy(isActive = false) else it })
        else if (p.variants.size > 1) p.copy(variants = p.variants.filterNot { it.key == key }) else p
    }

    fun save(onDone: (Long) -> Unit) {
        if (_status.value.saving) return
        val f = form.current
        val local = f.localErrors()
        if (local.isNotEmpty()) { _status.value = FormStatus(fieldErrors = local, message = "Periksa isian yang ditandai."); return }
        _status.value = FormStatus.saving()
        viewModelScope.launch {
            val out = ProductSaveOrchestrator(repo, canSeeCost, _multi.value, outletId).save(f)
            form.set(out.form)
            _warnings.value = out.warnings
            val sessionEnd = out.failures.firstOrNull { onSessionError(it.error) }
            if (sessionEnd != null) return@launch
            _status.value = if (out.ok) FormStatus() else ProductFormMapping.status(out.failures).let {
                // Produk sudah tercipta: beri tahu bahwa Simpan berikutnya hanya mengulang bagian yang gagal.
                if (out.form.id != null) it.copy(message = listOfNotNull(it.message, "Bagian lain sudah tersimpan. Tekan Simpan untuk mengulang yang gagal saja.").joinToString("\n")) else it
            }
            if (out.ok) onDone(out.form.id!!)
        }
    }
}

/** 094 / T067 — harga per outlet satu varian (`GET/PUT /variants/{id}/outlet-prices[/{outlet}]`); kosong = ikut harga dasar. */
class OutletPricesViewModel(
    private val repo: ProductRepository,
    private val variantId: Long,
    private val onSessionError: (ApiError) -> Boolean = { false },
) : ViewModel() {
    private val _data = MutableStateFlow<Load<OutletPricesDto>>(Load.Loading)
    val data: StateFlow<Load<OutletPricesDto>> = _data.asStateFlow()
    /** Isian per outlet (rupiah bulat; kosong = ikut dasar) dan catatan. */
    val inputs = MutableStateFlow<Map<Long, String>>(emptyMap())
    val note = MutableStateFlow("")
    private val _status = MutableStateFlow(FormStatus())
    val status: StateFlow<FormStatus> = _status.asStateFlow()
    private val _result = MutableStateFlow<String?>(null)
    val result: StateFlow<String?> = _result.asStateFlow()

    init { load() }

    fun load() {
        viewModelScope.launch {
            _data.value = try {
                repo.outletPrices(variantId).also { d -> inputs.value = d.outlets.associate { it.id to ProductForm.moneyInput(it.price) } }.let { Load.Ready(it) }
            } catch (e: ApiError) { if (onSessionError(e)) return@launch; Load.Failed(e.message) }
        }
    }

    /** Kirim hanya outlet yang berubah dari nilai server. Hasil pending (perlu persetujuan/rilis) dilaporkan. */
    fun save() {
        val d = (_data.value as? Load.Ready)?.data ?: return
        val changed = d.outlets.filter { (inputs.value[it.id] ?: "") != ProductForm.moneyInput(it.price) }
        if (changed.isEmpty() || _status.value.saving) return
        _status.value = FormStatus.saving()
        viewModelScope.launch {
            var pending = 0
            for (o in changed) {
                val raw = inputs.value[o.id].orEmpty()
                try {
                    val r = repo.setOutletPrice(variantId, o.id, OutletPriceBody(if (raw.isBlank()) null else ProductForm.money(raw), note.value.trim().ifEmpty { null }))
                    if (r.pending || r.requiresApproval) pending++
                } catch (e: ApiError) {
                    if (onSessionError(e)) return@launch
                    _status.value = FormStatus(message = "${o.name}: ${e.message}")
                    load()
                    return@launch
                }
            }
            _status.value = FormStatus()
            _result.value = if (pending > 0) "$pending perubahan menunggu persetujuan atau rilis menu." else "Harga outlet tersimpan."
            load()
        }
    }
}
