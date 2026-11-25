package com.warkop.manager.data

import com.warkop.core.network.apiCall
import com.warkop.manager.data.dto.AttachBody
import com.warkop.manager.data.dto.DiningTableDto
import com.warkop.manager.data.dto.ModifierGroupDto
import com.warkop.manager.data.dto.OutletRef
import com.warkop.manager.data.dto.Paged
import com.warkop.manager.data.dto.QrBatchBody
import com.warkop.manager.data.dto.StationBody
import com.warkop.manager.data.dto.StationDto
import com.warkop.manager.data.dto.TableBody
import com.warkop.manager.domain.OptionMaterial
import kotlinx.serialization.json.JsonObject

/** 094 / T087 — Pengaturan Restoran. Daftar meja/stasiun memilih outlet lewat `outlet_id[]` tanpa header (pola 071). */
class RestaurantRepository(private val api: () -> ManagerApi) {
    suspend fun outlets(): List<OutletRef> = apiCall { api().scope() }.outlets

    suspend fun tables(outletId: Long, q: String?, page: Int): Paged<DiningTableDto> =
        apiCall { api().diningTables(listOf(outletId), q?.trim()?.takeIf { it.isNotEmpty() }, page) }.let { Paged(it.data, com.warkop.manager.data.dto.PageMeta(it.meta.currentPage, it.meta.perPage, it.meta.total, it.meta.lastPage)) }
    suspend fun saveTable(id: Long?, body: TableBody): DiningTableDto = apiCall { if (id == null) api().createTable(body) else api().updateTable(id, body) }
    suspend fun deleteTable(id: Long) = apiUnit { api().deleteTable(id) }
    suspend fun tableQrUrl(id: Long): String? = apiCall { api().tableQr(QrBatchBody(listOf(id))) }.items.firstOrNull()?.url
    suspend fun rotateTableQr(id: Long): String? = apiCall { api().rotateTableQr(id) }.url

    suspend fun stations(outletId: Long): List<StationDto> = apiCall { api().stationsAdmin(listOf(outletId)) }.data
    suspend fun saveStation(id: Long?, body: StationBody): StationDto = apiCall { if (id == null) api().createStation(body) else api().updateStation(id, body) }
    suspend fun deleteStation(id: Long) = apiUnit { api().deleteStation(id) }

    suspend fun groups(): List<ModifierGroupDto> = apiCall { api().modifierGroupsFull() }.data
    suspend fun saveGroup(id: Long?, body: JsonObject): ModifierGroupDto = apiCall { if (id == null) api().createModifierGroup(body) else api().updateModifierGroup(id, body) }
    suspend fun deleteGroup(id: Long) = apiUnit { api().deleteModifierGroup(id) }
    suspend fun attach(id: Long, body: AttachBody): ModifierGroupDto = apiCall { api().attachModifierGroup(id, body) }

    /** Pilihan dialog Attach: semua kategori + semua item menu & ritel di SEMUA outlet yang terlihat (halaman 100, maks 20). */
    suspend fun attachChoices(): Pair<List<Pair<Long, String>>, List<Pair<Long, String>>> {
        val cats = mutableListOf<Pair<Long, String>>()
        var page = 1
        do {
            val res = apiCall { api().categories(null, page, 100) }
            cats += res.data.map { it.id to it.name }
            page++
        } while (page <= res.meta.lastPage && page <= 20)
        val outletIds = outlets().map { it.id }
        val products = linkedMapOf<Long, String>()
        for (tab in listOf("menu", "retail")) {
            var p = 1
            do {
                val res = apiCall { api().products(tab, outletIds, page = p, perPage = 100) }
                res.data.forEach { products[it.id] = it.name }
                p++
            } while (p <= res.meta.lastPage && p <= 20)
        }
        return cats to products.map { it.key to it.value }
    }

    /** Cari bahan untuk opsi modifier. */
    suspend fun searchMaterials(q: String): List<OptionMaterial> = apiCall { api().materialsForRecipe(q) }.data.map { OptionMaterial(it.id, it.name, it.unit) }
}
