package com.warkop.manager.data

import com.warkop.core.network.apiCall
import com.warkop.manager.data.dto.CategoryBody
import com.warkop.manager.data.dto.CategoryDto
import com.warkop.manager.data.dto.Paged
import com.warkop.manager.data.dto.StationDto
import okhttp3.MultipartBody

/** 094 / T056 — Kategori (dan nanti Produk/Stok). Hanya meneruskan ke endpoint yang sama dengan web. */
class InventoryRepository(private val api: () -> ManagerApi) {
    suspend fun categories(search: String?, page: Int): Paged<CategoryDto> = apiCall { api().categories(search?.takeIf { it.isNotBlank() }, page) }

    /** Semua kategori untuk pemilih induk (web: `per_page: 100`). */
    suspend fun allCategories(): List<CategoryDto> = apiCall { api().categories(null, 1, 100) }.data

    suspend fun category(id: Long): CategoryDto = apiCall { api().category(id) }

    suspend fun saveCategory(id: Long?, body: CategoryBody): CategoryDto =
        apiCall { if (id == null) api().createCategory(body) else api().updateCategory(id, body) }

    suspend fun deleteCategory(id: Long) = apiUnit { api().deleteCategory(id) }

    suspend fun uploadCategoryImage(id: Long, image: MultipartBody.Part): CategoryDto = apiCall { api().uploadCategoryImage(id, image) }

    suspend fun stations(): List<StationDto> = apiCall { api().stations() }.data
}
