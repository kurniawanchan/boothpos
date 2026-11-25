package com.warkop.manager.ui.products

import androidx.lifecycle.SavedStateHandle
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.warkop.core.domain.ApiError
import com.warkop.manager.data.ProductRepository
import com.warkop.manager.data.dto.ModifierOptionGroupDto
import com.warkop.manager.data.dto.ProductVariantDto
import com.warkop.manager.data.dto.RecipeRowDto
import com.warkop.manager.domain.FormStatus
import com.warkop.manager.domain.GroupChoice
import com.warkop.manager.domain.ModifierLinksForm
import com.warkop.manager.domain.RecipeBlock
import com.warkop.manager.domain.RecipeForm
import com.warkop.manager.domain.RecipeLine
import com.warkop.manager.domain.SavedForm
import com.warkop.manager.ui.components.Load
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch

/** 094 / T068 — tautan modifier satu produk. Data awal dari detail produk; daftar grup dari `/modifier-groups`. */
class ModifierLinksViewModel(
    private val repo: ProductRepository,
    handle: SavedStateHandle,
    private val productId: Long,
    private val onSessionError: (ApiError) -> Boolean = { false },
) : ViewModel() {
    val form = SavedForm(handle, "links.form", ModifierLinksForm.serializer()) { ModifierLinksForm() }
    private val _loading = MutableStateFlow(!form.restored)
    val loading: StateFlow<Boolean> = _loading.asStateFlow()
    /** null = gagal dimuat (grup baru tidak bisa ditautkan, aturan yang ada tetap bisa diubah — `ml_groups_failed`). */
    private val _groups = MutableStateFlow<List<GroupChoice>?>(emptyList())
    val groups: StateFlow<List<GroupChoice>?> = _groups.asStateFlow()
    private val _status = MutableStateFlow(FormStatus())
    val status: StateFlow<FormStatus> = _status.asStateFlow()

    init {
        viewModelScope.launch {
            if (_loading.value) {
                try {
                    val d = repo.detail(productId)
                    form.set(ModifierLinksForm.of(d.modifierLinks, d.modifierExcluded, emptySet()))
                } catch (e: ApiError) { if (!onSessionError(e)) _status.value = FormStatus(message = e.message) }
                _loading.value = false
            }
            _groups.value = try { repo.modifierGroups().also { g -> form.update { it.markSingle(g) } } } catch (e: ApiError) { if (onSessionError(e)) return@launch; null }
        }
    }

    fun edit(f: (ModifierLinksForm) -> ModifierLinksForm) { form.update(f); if (_status.value.fieldErrors.isNotEmpty()) _status.value = FormStatus() }

    /** [onDone] menerima pesan hasil: langsung berlaku atau menunggu rilis menu. */
    fun save(onDone: (String) -> Unit) {
        val f = form.current
        if (_status.value.saving) return
        val local = f.localErrors()
        if (local.isNotEmpty()) { _status.value = FormStatus(fieldErrors = local); return }
        _status.value = FormStatus.saving()
        viewModelScope.launch {
            try {
                val applied = repo.saveLinks(productId, f.toBody())
                // Pengecualian kini tersimpan: jadi dasar perbandingan berikutnya.
                form.update { it.copy(originalExcluded = it.excludedIds()) }
                _status.value = FormStatus()
                onDone(if (applied) "Tautan modifier disimpan." else "Perubahan tautan modifier menunggu rilis menu berikutnya.")
            } catch (e: ApiError) {
                if (!onSessionError(e)) _status.value = FormStatus.from(e)
            }
        }
    }
}

/** 094 / T069 — daftar resep produk (semua resep aktif DIJUMLAHKAN; tidak ada resep "terpilih"). */
class RecipesViewModel(
    private val repo: ProductRepository,
    private val productId: Long,
    private val onSessionError: (ApiError) -> Boolean = { false },
) : ViewModel() {
    private val _list = MutableStateFlow<Load<List<RecipeRowDto>>>(Load.Loading)
    val list: StateFlow<Load<List<RecipeRowDto>>> = _list.asStateFlow()
    private val _status = MutableStateFlow(FormStatus())
    val status: StateFlow<FormStatus> = _status.asStateFlow()

    /** Dipanggil layar setiap kali panel tampil (kembali dari formulir resep = daftar segar). */
    fun load() {
        viewModelScope.launch {
            val before = _list.value
            if (before is Load.Ready) _list.value = before.copy(refreshing = true)
            _list.value = try { Load.Ready(repo.recipes(productId)) } catch (e: ApiError) {
                if (onSessionError(e)) return@launch
                if (before is Load.Ready) before.copy(refreshing = false) else Load.Failed(e.message)
            }
        }
    }

    fun delete(id: Long) {
        _status.value = FormStatus.saving()
        viewModelScope.launch {
            try { repo.deleteRecipe(id); _status.value = FormStatus(); load() } catch (e: ApiError) { if (!onSessionError(e)) _status.value = FormStatus.from(e) }
        }
    }
}

/** 094 / T069 — formulir resep (buat/ubah). */
class RecipeFormViewModel(
    private val repo: ProductRepository,
    handle: SavedStateHandle,
    private val productId: Long,
    private val recipeId: Long?,
    private val onSessionError: (ApiError) -> Boolean = { false },
) : ViewModel() {
    val form = SavedForm(handle, "recipe.form", RecipeForm.serializer()) { RecipeForm() }
    private val _loading = MutableStateFlow(true)
    val loading: StateFlow<Boolean> = _loading.asStateFlow()
    private val _variants = MutableStateFlow<List<ProductVariantDto>>(emptyList())
    val variants: StateFlow<List<ProductVariantDto>> = _variants.asStateFlow()
    private val _options = MutableStateFlow<List<ModifierOptionGroupDto>>(emptyList())
    val options: StateFlow<List<ModifierOptionGroupDto>> = _options.asStateFlow()
    private val _status = MutableStateFlow(FormStatus())
    val status: StateFlow<FormStatus> = _status.asStateFlow()

    /** Pencarian bahan per tabel: kunci tabel yang sedang mencari, jenis, kata kunci, hasil. */
    val searchBlock = MutableStateFlow<String?>(null)
    val searchKind = MutableStateFlow("material")
    val searchQuery = MutableStateFlow("")
    private val _results = MutableStateFlow<List<RecipeLine>>(emptyList())
    val results: StateFlow<List<RecipeLine>> = _results.asStateFlow()
    private var searchJob: Job? = null

    init {
        viewModelScope.launch {
            try {
                val product = repo.detail(productId)
                _variants.value = product.variants
                if (!form.restored) form.set(
                    if (recipeId == null) RecipeForm.new(product.name, product.variants.map { it.id to (it.isActive && !it.bomComplete) })
                    else RecipeForm.of(repo.recipe(recipeId)),
                )
            } catch (e: ApiError) { if (!onSessionError(e)) _status.value = FormStatus(message = e.message) }
            _loading.value = false
            _options.value = runCatching { repo.recipeOptions(productId) }.getOrDefault(emptyList())
        }
    }

    fun edit(f: (RecipeForm) -> RecipeForm) { form.update(f); if (_status.value.fieldErrors.isNotEmpty()) _status.value = _status.value.copy(fieldErrors = emptyMap()) }
    fun editBlock(key: String, f: (RecipeBlock) -> RecipeBlock) = edit { r -> r.copy(blocks = r.blocks.map { if (it.key == key) f(it) else it }) }
    fun addBlock() = edit { it.copy(blocks = it.blocks + RecipeBlock()) }
    fun removeBlock(key: String) = edit { r -> r.copy(blocks = r.blocks.filterNot { it.key == key }) }
    fun editLine(block: String, line: String, f: (RecipeLine) -> RecipeLine) = editBlock(block) { b -> b.copy(lines = b.lines.map { if (it.key == line) f(it) else it }) }
    fun removeLine(block: String, line: String) = editBlock(block) { b -> b.copy(lines = b.lines.filterNot { it.key == line }) }

    fun startSearch(block: String) { searchBlock.value = block; searchQuery.value = ""; _results.value = emptyList() }
    fun setSearchKind(k: String) { searchKind.value = k; setQuery(searchQuery.value) }
    fun setQuery(q: String) {
        searchQuery.value = q
        searchJob?.cancel()
        if (q.isBlank()) { _results.value = emptyList(); return }
        searchJob = viewModelScope.launch { delay(300); _results.value = runCatching { repo.searchIngredients(searchKind.value, q.trim(), productId) }.getOrDefault(emptyList()) }
    }
    fun pick(line: RecipeLine) {
        val block = searchBlock.value ?: return
        editBlock(block) { b -> if (b.lines.any { it.kind == line.kind && it.itemId == line.itemId }) b else b.copy(lines = b.lines + line.copy(key = java.util.UUID.randomUUID().toString())) }
        searchBlock.value = null; _results.value = emptyList()
    }

    fun save(onDone: () -> Unit) {
        val f = form.current
        if (_status.value.saving) return
        val local = f.localErrors()
        if (local.isNotEmpty()) { _status.value = FormStatus(fieldErrors = local, message = "Periksa isian yang ditandai."); return }
        _status.value = FormStatus.saving()
        viewModelScope.launch {
            try {
                val saved = repo.saveRecipe(productId, f.id, f.toBody())
                form.set(RecipeForm.of(saved))
                _status.value = FormStatus()
                onDone()
            } catch (e: ApiError) {
                // 409 cost_source_conflict / recipe_archived: pesan server apa adanya.
                if (!onSessionError(e)) _status.value = FormStatus.from(e)
            }
        }
    }
}
