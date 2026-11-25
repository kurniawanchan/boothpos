package com.warkop.manager.ui.categories

import android.net.Uri
import androidx.lifecycle.SavedStateHandle
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.warkop.core.domain.ApiError
import com.warkop.manager.data.InventoryRepository
import com.warkop.manager.data.dto.CategoryDto
import com.warkop.manager.data.dto.StationDto
import com.warkop.manager.domain.CategoryForm
import com.warkop.manager.domain.FormStatus
import com.warkop.manager.domain.SavedForm
import com.warkop.manager.ui.components.Load
import com.warkop.manager.ui.components.Pager
import com.warkop.manager.ui.components.PagedList
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch
import okhttp3.MultipartBody

/** 094 / T057 — daftar Kategori: cari (tunda 300 ms seperti `useDebouncedFn` web), muat bertahap. */
class CategoriesViewModel(
    private val repo: InventoryRepository,
    private val handle: SavedStateHandle,
    onSessionError: (ApiError) -> Boolean = { false },
) : ViewModel() {
    private val _search = MutableStateFlow(handle.get<String>("q").orEmpty())
    val search: StateFlow<String> = _search.asStateFlow()

    private val paged = PagedList(viewModelScope, onSessionError) { page -> repo.categories(_search.value, page) }
    val list: StateFlow<Load<Pager.State<CategoryDto>>> = paged.state

    init { paged.reload(silent = false) }

    fun setSearch(q: String) {
        _search.value = q
        handle["q"] = q
        paged.reload(silent = false, debounceMs = 300)
    }

    fun reload() = paged.reload()
    fun loadMore() = paged.loadMore()
}

/**
 * 094 / T057 — formulir Kategori (buat/ubah) dan nonaktifkan. Urutan sama dengan web: simpan kategori DULU, lalu foto;
 * gagal foto TIDAK membatalkan kategori yang sudah tersimpan (pesan terpisah, pola `category_saved_image_failed`).
 */
class CategoryFormViewModel(
    private val repo: InventoryRepository,
    handle: SavedStateHandle,
    private val categoryId: Long?,
    private val onSessionError: (ApiError) -> Boolean = { false },
) : ViewModel() {
    val form = SavedForm(handle, "category.form", CategoryForm.serializer()) { CategoryForm(id = categoryId) }

    private val _status = MutableStateFlow(FormStatus())
    val status: StateFlow<FormStatus> = _status.asStateFlow()

    /** Pilihan induk (tanpa diri sendiri) dan stasiun; gagal muat = pemilih kosong, formulir tetap bisa dipakai. */
    private val _parents = MutableStateFlow<List<CategoryDto>>(emptyList())
    val parents: StateFlow<List<CategoryDto>> = _parents.asStateFlow()
    private val _stations = MutableStateFlow<List<StationDto>>(emptyList())
    val stations: StateFlow<List<StationDto>> = _stations.asStateFlow()

    /** Kategori yang sedang diubah belum termuat (hanya mode ubah tanpa isian tersimpan). */
    private val _loading = MutableStateFlow(categoryId != null && !form.restored)
    val loading: StateFlow<Boolean> = _loading.asStateFlow()

    private val _photo = MutableStateFlow<Uri?>(handle.get<String>("category.photo")?.let(Uri::parse))
    val photo: StateFlow<Uri?> = _photo.asStateFlow()
    private val photoHandle = handle

    init {
        if (_loading.value && categoryId != null) {
            viewModelScope.launch {
                try {
                    form.set(CategoryForm.of(repo.category(categoryId)))
                } catch (e: ApiError) {
                    if (!onSessionError(e)) _status.value = FormStatus(message = e.message)
                }
                _loading.value = false
            }
        }
        viewModelScope.launch {
            _parents.value = runCatching { repo.allCategories() }.getOrDefault(emptyList()).filter { it.id != categoryId }
        }
        viewModelScope.launch { _stations.value = runCatching { repo.stations() }.getOrDefault(emptyList()) }
    }

    fun edit(f: (CategoryForm) -> CategoryForm) {
        form.update(f)
        if (_status.value.fieldErrors.isNotEmpty() || _status.value.message != null) _status.value = FormStatus()
    }

    fun setPhoto(uri: Uri?) {
        _photo.value = uri
        photoHandle["category.photo"] = uri?.toString()
    }

    /**
     * [photoPart] mengubah foto terpilih menjadi bagian multipart (layar: `ImageUpload.part(resolver, uri)`); dipisah dari
     * ViewModel supaya tes JVM tidak butuh ContentResolver. [onDone] menerima pesan tambahan (mis. foto gagal) atau null.
     */
    fun save(photoPart: (Uri) -> MultipartBody.Part, onDone: (String?) -> Unit) {
        if (_status.value.saving) return
        val f = form.current
        val local = buildMap {
            if (!f.editing && f.code.length != 2) put("code", "Kode harus 2 huruf.")
            if (f.name.isBlank()) put("name", "Wajib diisi.")
        }
        if (local.isNotEmpty()) { _status.value = FormStatus(fieldErrors = local); return }
        _status.value = FormStatus.saving()
        viewModelScope.launch {
            val saved = try {
                repo.saveCategory(f.id, f.toBody())
            } catch (e: ApiError) {
                if (!onSessionError(e)) _status.value = FormStatus.from(e)
                return@launch
            }
            // Mulai sekarang mode ubah: simpan ulang tidak pernah membuat kategori kedua.
            form.set(CategoryForm.of(saved))
            val photoNote = _photo.value?.let { uri ->
                try {
                    repo.uploadCategoryImage(saved.id, photoPart(uri))
                    setPhoto(null)
                    null
                } catch (e: ApiError) {
                    if (onSessionError(e)) return@launch
                    "Kategori tersimpan, tetapi foto gagal diunggah: ${e.message}"
                }
            }
            _status.value = FormStatus(message = photoNote)
            onDone(photoNote)
        }
    }

    /** Server menonaktifkan; 409 (masih ada sub-kategori/produk aktif) ditampilkan apa adanya. */
    fun deactivate(onDone: () -> Unit) {
        val id = form.current.id ?: return
        _status.value = FormStatus.saving()
        viewModelScope.launch {
            try {
                repo.deleteCategory(id)
                _status.value = FormStatus()
                onDone()
            } catch (e: ApiError) {
                if (!onSessionError(e)) _status.value = FormStatus.from(e)
            }
        }
    }
}
