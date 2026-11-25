package com.warkop.manager.ui.categories

import androidx.lifecycle.SavedStateHandle
import com.warkop.core.domain.ApiError
import com.warkop.manager.data.InventoryRepository
import com.warkop.manager.data.ManagerApi
import com.warkop.manager.data.dto.CategoryBody
import com.warkop.manager.data.dto.CategoryDto
import com.warkop.manager.data.dto.DataList
import com.warkop.manager.data.dto.Paged
import com.warkop.manager.data.dto.StationDto
import com.warkop.manager.domain.CategoryForm
import com.warkop.manager.domain.SavedForm
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.runBlocking
import kotlinx.coroutines.test.UnconfinedTestDispatcher
import kotlinx.coroutines.test.resetMain
import kotlinx.coroutines.test.setMain
import kotlinx.coroutines.withTimeout
import okhttp3.ResponseBody.Companion.toResponseBody
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test
import retrofit2.Response

/** 094 / T057 — aturan formulir Kategori, cermin `CategoriesView.vue` + request server. */
@OptIn(ExperimentalCoroutinesApi::class)
class CategoryFormTest {
    private val created = mutableListOf<CategoryBody>()
    private val updated = mutableListOf<Pair<Long, CategoryBody>>()
    private var deleteCode = 204

    private val api = object : ManagerApi by unused() {
        override suspend fun categories(search: String?, page: Int, perPage: Int) = Paged<CategoryDto>()
        override suspend fun stations(active: Int) = DataList<StationDto>()
        override suspend fun category(id: Long) = CategoryDto(id, "MN", "Minuman", stationId = 3)
        override suspend fun createCategory(body: CategoryBody): CategoryDto { created += body; return CategoryDto(77, body.code!!, body.name) }
        override suspend fun updateCategory(id: Long, body: CategoryBody): CategoryDto { updated += id to body; return CategoryDto(id, "MN", body.name) }
        override suspend fun deleteCategory(id: Long): Response<Unit> =
            if (deleteCode == 204) Response.success(204, Unit)
            else Response.error(deleteCode, """{"message":"Kategori masih punya produk aktif."}""".toResponseBody())
    }

    @Before fun setUp() = Dispatchers.setMain(UnconfinedTestDispatcher())
    @After fun tearDown() = Dispatchers.resetMain()

    private fun vm(id: Long? = null, handle: SavedStateHandle = SavedStateHandle()) = CategoryFormViewModel(InventoryRepository { api }, handle, id)

    private fun CategoryFormViewModel.awaitIdle() = runBlocking { withTimeout(5_000) { status.first { !it.saving } } }

    @Test fun `kode hanya huruf, maksimal dua, huruf besar`() {
        assertEquals("MN", CategoryForm.cleanCode("m1n"))
        assertEquals("AB", CategoryForm.cleanCode("abc"))
        assertEquals("", CategoryForm.cleanCode("é9"))
    }

    @Test fun `buat mengirim kode huruf besar, ubah tidak mengirim kode`() {
        assertEquals("MN", CategoryForm(code = "mn", name = "X").toBody().code)
        assertNull(CategoryForm(id = 5, code = "MN", name = "X").toBody().code)
        assertEquals(0, CategoryForm(name = "X", displayOrder = "").toBody().displayOrder)
    }

    @Test fun `simpan baru lalu simpan lagi tidak membuat kategori kedua`() {
        val vm = vm()
        vm.edit { it.copy(code = "mn", name = "Minuman") }
        vm.save({ error("tanpa foto") }) {}
        vm.awaitIdle()
        vm.edit { it.copy(name = "Minuman dingin") }
        vm.save({ error("tanpa foto") }) {}
        vm.awaitIdle()
        assertEquals(1, created.size)
        assertEquals(listOf(77L), updated.map { it.first })
        assertNull(updated.single().second.code)
    }

    @Test fun `validasi lokal kode dan nama sebelum memanggil server`() {
        val vm = vm()
        vm.save({ error("tanpa foto") }) {}
        assertEquals(setOf("code", "name"), vm.status.value.fieldErrors.keys)
        assertTrue(created.isEmpty())
    }

    @Test fun `409 nonaktifkan ditampilkan, bukan dianggap berhasil`() {
        deleteCode = 409
        val vm = vm(id = 5)
        runBlocking { withTimeout(5_000) { vm.loading.first { !it } } }
        var done = false
        vm.deactivate { done = true }
        vm.awaitIdle()
        assertTrue(!done)
        assertEquals("Kategori masih punya produk aktif.", vm.status.value.message)
    }

    @Test fun `isian tersimpan dipulihkan setelah proses dibuat ulang`() {
        val handle = SavedStateHandle()
        SavedForm(handle, "category.form", CategoryForm.serializer()) { CategoryForm() }.set(CategoryForm(code = "KO", name = "Kopi"))
        val restored = vm(handle = handle)
        assertEquals("Kopi", restored.form.current.name)
        // Mode ubah dengan isian tersimpan: data server TIDAK menimpa ketikan pengguna.
        val h2 = SavedStateHandle()
        SavedForm(h2, "category.form", CategoryForm.serializer()) { CategoryForm() }.set(CategoryForm(id = 5, code = "MN", name = "Sedang diketik"))
        val editing = vm(id = 5, handle = h2)
        assertEquals("Sedang diketik", editing.form.current.name)
    }
}

private fun unused(): ManagerApi = java.lang.reflect.Proxy.newProxyInstance(
    ManagerApi::class.java.classLoader, arrayOf(ManagerApi::class.java),
) { _, m, _ -> error("ManagerApi.${m.name} tidak dipakai di tes ini") } as ManagerApi
