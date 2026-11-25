package com.warkop.manager.domain

import com.warkop.manager.data.dto.CategoryBody
import com.warkop.manager.data.dto.CategoryDto
import kotlinx.serialization.Serializable

/**
 * 094 / T057 — isian formulir Kategori, cermin `CategoriesView.vue` + `StoreCategoryRequest`/`UpdateCategoryRequest`:
 * code 2 huruf (alpha, size:2) dikirim HURUF BESAR dan hanya saat buat (permanen); name ≤ 100; induk tanpa diri sendiri
 * (siklus ditolak server); urutan bulat (kosong → 0, sama dengan `Number(x) || 0` di web).
 */
@Serializable data class CategoryForm(
    val id: Long? = null,
    val code: String = "",
    val name: String = "",
    val parentId: Long? = null,
    val displayOrder: String = "0",
    val isActive: Boolean = true,
    val stationId: Long? = null,
    val imageUrl: String? = null,
) {
    val editing: Boolean get() = id != null

    fun toBody(): CategoryBody = CategoryBody(
        code = if (editing) null else code.uppercase(),
        name = name,
        parentId = parentId,
        displayOrder = displayOrder.toIntOrNull() ?: 0,
        isActive = isActive,
        stationId = stationId,
    )

    companion object {
        const val NAME_MAX = 100

        fun of(c: CategoryDto) = CategoryForm(c.id, c.code, c.name, c.parentId, c.displayOrder.toString(), c.isActive, c.stationId, c.imageUrl)

        /** Saat mengetik kode: hanya huruf, maksimal 2, langsung huruf besar (server `alpha` + `size:2`). */
        fun cleanCode(raw: String): String = raw.filter { it.isLetter() && it.code < 128 }.take(2).uppercase()
    }
}
