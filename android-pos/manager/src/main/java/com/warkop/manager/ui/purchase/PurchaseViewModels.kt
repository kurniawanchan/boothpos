package com.warkop.manager.ui.purchase

import androidx.lifecycle.SavedStateHandle
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.warkop.core.domain.ApiError
import com.warkop.manager.data.PurchaseRepository
import com.warkop.manager.data.dto.MaterialAdjustBody
import com.warkop.manager.data.dto.MaterialDto
import com.warkop.manager.data.dto.MaterialMovementDto
import com.warkop.manager.data.dto.PurchaseOrderDto
import com.warkop.manager.data.dto.VendorDto
import com.warkop.manager.domain.FormStatus
import com.warkop.manager.domain.MaterialAdjustForm
import com.warkop.manager.domain.MaterialForm
import com.warkop.manager.domain.PurchaseOrderForm
import com.warkop.manager.domain.SavedForm
import com.warkop.manager.domain.VendorForm
import com.warkop.manager.domain.VendorPriceForm
import com.warkop.manager.ui.components.Load
import com.warkop.manager.ui.components.PagedList
import com.warkop.manager.ui.components.Pager
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch

/** Aksi simpan/hapus seragam: status saving, 422 → kolom, 409/403 → pesan, sesi berakhir → kerangka. */
private fun ViewModel.runAction(status: MutableStateFlow<FormStatus>, onSessionError: (ApiError) -> Boolean, done: () -> Unit, call: suspend () -> Unit) {
    if (status.value.saving) return
    status.value = FormStatus.saving()
    viewModelScope.launch {
        try { call(); status.value = FormStatus(); done() } catch (e: ApiError) { if (!onSessionError(e)) status.value = FormStatus.from(e) }
    }
}

// ---------------- Vendor (T078) ----------------

class VendorsViewModel(private val repo: PurchaseRepository, private val handle: SavedStateHandle, onSessionError: (ApiError) -> Boolean = { false }) : ViewModel() {
    private val _q = MutableStateFlow(handle.get<String>("q").orEmpty())
    val q: StateFlow<String> = _q.asStateFlow()
    private val paged = PagedList(viewModelScope, onSessionError) { page -> repo.vendors(_q.value, page) }
    val list: StateFlow<Load<Pager.State<VendorDto>>> = paged.state
    init { paged.reload(silent = false) }
    fun setQuery(v: String) { _q.value = v; handle["q"] = v; paged.reload(silent = false, debounceMs = 300) }
    fun reload() = paged.reload()
    fun loadMore() = paged.loadMore()
}

class VendorFormViewModel(private val repo: PurchaseRepository, handle: SavedStateHandle, private val id: Long?, private val onSessionError: (ApiError) -> Boolean = { false }) : ViewModel() {
    val form = SavedForm(handle, "vendor.form", VendorForm.serializer()) { VendorForm(id = id) }
    private val _status = MutableStateFlow(FormStatus())
    val status: StateFlow<FormStatus> = _status.asStateFlow()
    private val _loading = MutableStateFlow(id != null && !form.restored)
    val loading: StateFlow<Boolean> = _loading.asStateFlow()

    init {
        if (_loading.value && id != null) viewModelScope.launch {
            try { form.set(VendorForm.of(repo.vendor(id))) } catch (e: ApiError) { if (!onSessionError(e)) _status.value = FormStatus(message = e.message) }
            _loading.value = false
        }
    }

    fun edit(f: (VendorForm) -> VendorForm) { form.update(f); if (_status.value.fieldErrors.isNotEmpty()) _status.value = FormStatus() }

    fun save(done: () -> Unit) {
        val f = form.current
        f.localErrors().takeIf { it.isNotEmpty() }?.let { _status.value = FormStatus(fieldErrors = it); return }
        runAction(_status, onSessionError, done) { form.set(VendorForm.of(repo.saveVendor(f.id, f.toBody()))) }
    }

    /** 409 bila vendor masih punya harga bahan; pesan server ditampilkan. */
    fun delete(done: () -> Unit) { val vid = form.current.id ?: return; runAction(_status, onSessionError, done) { repo.deleteVendor(vid) } }
}

// ---------------- Bahan (T079–T081) ----------------

class MaterialsViewModel(private val repo: PurchaseRepository, private val handle: SavedStateHandle, onSessionError: (ApiError) -> Boolean = { false }) : ViewModel() {
    private val _q = MutableStateFlow(handle.get<String>("q").orEmpty())
    val q: StateFlow<String> = _q.asStateFlow()
    private val _kind = MutableStateFlow(handle.get<String>("kind"))
    val kind: StateFlow<String?> = _kind.asStateFlow()
    private val _stock = MutableStateFlow(handle.get<String>("stock"))
    val stock: StateFlow<String?> = _stock.asStateFlow()
    private val paged = PagedList(viewModelScope, onSessionError) { page -> repo.materials(_q.value, _kind.value, _stock.value, page) }
    val list: StateFlow<Load<Pager.State<MaterialDto>>> = paged.state
    init { paged.reload(silent = false) }
    fun setQuery(v: String) { _q.value = v; handle["q"] = v; paged.reload(silent = false, debounceMs = 300) }
    fun setKind(v: String?) { _kind.value = v; handle["kind"] = v; paged.reload(silent = false) }
    fun setStock(v: String?) { _stock.value = v; handle["stock"] = v; paged.reload(silent = false) }
    fun reload() = paged.reload()
    fun loadMore() = paged.loadMore()
}

/** Detail bahan: formulir, koreksi stok, riwayat, harga vendor — satu panel, data detail dari `GET /materials/{id}`. */
class MaterialViewModel(
    private val repo: PurchaseRepository,
    handle: SavedStateHandle,
    private val id: Long?,
    private val outletId: Long,
    private val onSessionError: (ApiError) -> Boolean = { false },
) : ViewModel() {
    val form = SavedForm(handle, "material.form", MaterialForm.serializer()) { MaterialForm(id = id) }
    val adjust = SavedForm(handle, "material.adjust", MaterialAdjustForm.serializer()) { MaterialAdjustForm() }
    val priceForm = SavedForm(handle, "material.price", VendorPriceForm.serializer()) { VendorPriceForm() }
    private val _detail = MutableStateFlow<MaterialDto?>(null)
    val detail: StateFlow<MaterialDto?> = _detail.asStateFlow()
    private val _movements = MutableStateFlow<List<MaterialMovementDto>>(emptyList())
    val movements: StateFlow<List<MaterialMovementDto>> = _movements.asStateFlow()
    private val _vendors = MutableStateFlow<List<VendorDto>>(emptyList())
    val vendors: StateFlow<List<VendorDto>> = _vendors.asStateFlow()
    private val _status = MutableStateFlow(FormStatus())
    val status: StateFlow<FormStatus> = _status.asStateFlow()
    private val _adjustStatus = MutableStateFlow(FormStatus())
    val adjustStatus: StateFlow<FormStatus> = _adjustStatus.asStateFlow()
    private val _priceStatus = MutableStateFlow(FormStatus())
    val priceStatus: StateFlow<FormStatus> = _priceStatus.asStateFlow()
    private val _loading = MutableStateFlow(id != null)
    val loading: StateFlow<Boolean> = _loading.asStateFlow()

    init { if (id != null) load(fillForm = !form.restored) }

    fun load(fillForm: Boolean = false) {
        val mid = form.current.id ?: id ?: return
        viewModelScope.launch {
            try {
                val m = repo.material(mid)
                _detail.value = m
                if (fillForm) form.set(MaterialForm.of(m))
            } catch (e: ApiError) { if (!onSessionError(e)) _status.value = FormStatus(message = e.message) }
            _loading.value = false
            _movements.value = runCatching { repo.materialMovements(mid, 1).data }.getOrDefault(_movements.value)
        }
    }

    fun loadVendors() { if (_vendors.value.isEmpty()) viewModelScope.launch { _vendors.value = runCatching { repo.activeVendors() }.getOrDefault(emptyList()) } }

    fun edit(f: (MaterialForm) -> MaterialForm) { form.update(f); if (_status.value.fieldErrors.isNotEmpty()) _status.value = FormStatus() }

    fun save(done: (Long) -> Unit) {
        val f = form.current
        f.localErrors().takeIf { it.isNotEmpty() }?.let { _status.value = FormStatus(fieldErrors = it); return }
        runAction(_status, onSessionError, { form.current.id?.let(done) }) {
            val saved = repo.saveMaterial(f.id, f.toBody())
            form.set(MaterialForm.of(saved).copy(customUnit = f.customUnit))
            load()
        }
    }

    /** 409 bila masih punya harga vendor, baris BOM, atau dipakai pilihan modifier aktif. */
    fun delete(done: () -> Unit) { val mid = form.current.id ?: return; runAction(_status, onSessionError, done) { repo.deleteMaterial(mid) } }

    fun editAdjust(f: (MaterialAdjustForm) -> MaterialAdjustForm) { adjust.update(f); _adjustStatus.value = FormStatus() }

    fun submitAdjust(done: () -> Unit) {
        val mid = form.current.id ?: return
        val a = adjust.current
        a.localErrors().takeIf { it.isNotEmpty() }?.let { _adjustStatus.value = FormStatus(fieldErrors = it); return }
        runAction(_adjustStatus, onSessionError, done) {
            repo.adjustMaterial(mid, MaterialAdjustBody(a.qty.toDouble(), a.reason.trim(), outletId))
            adjust.set(MaterialAdjustForm())
            load()
        }
    }

    fun editPrice(f: (VendorPriceForm) -> VendorPriceForm) { priceForm.update(f); _priceStatus.value = FormStatus() }

    fun savePrice(done: () -> Unit) {
        val mid = form.current.id ?: return
        val p = priceForm.current
        p.localErrors().takeIf { it.isNotEmpty() }?.let { _priceStatus.value = FormStatus(fieldErrors = it); return }
        runAction(_priceStatus, onSessionError, done) { repo.saveVendorPrice(mid, p.id, p.toBody()); priceForm.set(VendorPriceForm()); load() }
    }

    fun deletePrice(priceId: Long) = runAction(_priceStatus, onSessionError, {}) { repo.deleteVendorPrice(priceId); load() }
}

// ---------------- PO (T082–T083) ----------------

class PurchaseOrdersViewModel(private val repo: PurchaseRepository, private val handle: SavedStateHandle, onSessionError: (ApiError) -> Boolean = { false }) : ViewModel() {
    private val _status = MutableStateFlow(handle.get<String>("status"))
    val status: StateFlow<String?> = _status.asStateFlow()
    private val paged = PagedList(viewModelScope, onSessionError) { page -> repo.purchaseOrders(_status.value, null, page) }
    val list: StateFlow<Load<Pager.State<PurchaseOrderDto>>> = paged.state
    init { paged.reload(silent = false) }
    fun setStatus(v: String?) { _status.value = v; handle["status"] = v; paged.reload(silent = false) }
    fun reload() = paged.reload()
    fun loadMore() = paged.loadMore()
}

/** Formulir PO (buat/ubah) dengan pilihan vendor dan bahan. Outlet PO = outlet terpilih. */
class PurchaseOrderFormViewModel(
    private val repo: PurchaseRepository,
    handle: SavedStateHandle,
    private val id: Long?,
    private val outletId: Long,
    private val onSessionError: (ApiError) -> Boolean = { false },
) : ViewModel() {
    val form = SavedForm(handle, "po.form", PurchaseOrderForm.serializer()) { PurchaseOrderForm(id = id) }
    private val _status = MutableStateFlow(FormStatus())
    val status: StateFlow<FormStatus> = _status.asStateFlow()
    private val _loading = MutableStateFlow(id != null && !form.restored)
    val loading: StateFlow<Boolean> = _loading.asStateFlow()
    private val _vendors = MutableStateFlow<List<VendorDto>>(emptyList())
    val vendors: StateFlow<List<VendorDto>> = _vendors.asStateFlow()
    private val _materials = MutableStateFlow<List<MaterialDto>>(emptyList())
    val materials: StateFlow<List<MaterialDto>> = _materials.asStateFlow()

    init {
        viewModelScope.launch { _vendors.value = runCatching { repo.activeVendors() }.getOrDefault(emptyList()) }
        viewModelScope.launch { _materials.value = runCatching { repo.materials(null, null, null, 1).data }.getOrDefault(emptyList()) }
        if (_loading.value && id != null) viewModelScope.launch {
            try { form.set(PurchaseOrderForm.of(repo.purchaseOrder(id))) } catch (e: ApiError) { if (!onSessionError(e)) _status.value = FormStatus(message = e.message) }
            _loading.value = false
        }
    }

    fun searchMaterials(q: String) { viewModelScope.launch { _materials.value = runCatching { repo.materials(q, null, null, 1).data }.getOrDefault(_materials.value) } }

    fun edit(f: (PurchaseOrderForm) -> PurchaseOrderForm) { form.update(f); if (_status.value.fieldErrors.isNotEmpty()) _status.value = FormStatus() }

    fun save(done: (Long) -> Unit) {
        val f = form.current
        f.localErrors().takeIf { it.isNotEmpty() }?.let { _status.value = FormStatus(fieldErrors = it); return }
        runAction(_status, onSessionError, { form.current.id?.let(done) }) { form.set(PurchaseOrderForm.of(repo.savePurchaseOrder(f.id, f.toBody(outletId)))) }
    }
}

/** Detail PO: transisi status (graf klien = graf server), pembayaran tunai, hapus draft. */
class PurchaseOrderDetailViewModel(
    private val repo: PurchaseRepository,
    private val id: Long,
    private val onSessionError: (ApiError) -> Boolean = { false },
) : ViewModel() {
    private val _po = MutableStateFlow<Load<PurchaseOrderDto>>(Load.Loading)
    val po: StateFlow<Load<PurchaseOrderDto>> = _po.asStateFlow()
    private val _status = MutableStateFlow(FormStatus())
    val status: StateFlow<FormStatus> = _status.asStateFlow()

    fun load() {
        viewModelScope.launch {
            val before = _po.value
            _po.value = try { Load.Ready(repo.purchaseOrder(id)) } catch (e: ApiError) {
                if (onSessionError(e)) return@launch
                if (before is Load.Ready) before else Load.Failed(e.message)
            }
        }
    }

    private fun applyResult(call: suspend () -> PurchaseOrderDto, done: () -> Unit) = runAction(_status, onSessionError, done) { _po.value = Load.Ready(call()) }

    /** 409 transisi tidak sah (mis. status sudah berubah di web) ditampilkan dan data dimuat ulang. */
    fun transition(to: String, cancelReason: String?, done: () -> Unit) {
        if (_status.value.saving) return
        _status.value = FormStatus.saving()
        viewModelScope.launch {
            try {
                _po.value = Load.Ready(repo.setStatus(id, to, cancelReason?.trim()?.take(255)?.ifEmpty { null }))
                _status.value = FormStatus()
                done()
            } catch (e: ApiError) {
                if (onSessionError(e)) return@launch
                _status.value = FormStatus.from(e)
                load() // status mungkin sudah berubah di tempat lain: tombol mengikuti data terbaru
            }
        }
    }

    fun pay(amount: String, notes: String, done: () -> Unit) {
        val a = amount.toDoubleOrNull()
        if (a == null || a < 0.01) { _status.value = FormStatus(fieldErrors = mapOf("amount" to "Nominal minimal 0,01.")); return }
        applyResult({ repo.pay(id, a, notes.trim().take(1000).ifEmpty { null }) }, done)
    }

    /** Hanya draft; selain itu 409 dari server. */
    fun delete(done: () -> Unit) = runAction(_status, onSessionError, done) { repo.deletePurchaseOrder(id) }
}
