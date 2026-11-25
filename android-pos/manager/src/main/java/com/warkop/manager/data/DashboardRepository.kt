package com.warkop.manager.data

import com.warkop.core.network.ApiClient
import com.warkop.core.network.apiCall
import com.warkop.manager.data.dto.AttentionDto
import com.warkop.manager.data.dto.DashboardOrdersDto
import com.warkop.manager.data.dto.DashboardSummaryDto
import com.warkop.manager.data.dto.MaterialStatsDto
import com.warkop.manager.data.dto.OpenShiftsDto
import com.warkop.manager.domain.DashboardModel
import com.warkop.manager.domain.MoneyField
import com.warkop.manager.domain.Period
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.decodeFromJsonElement

/** Statistik bahan siap tampil; rupiah dibedakan antara disembunyikan server dan tak terhitung. */
data class MaterialStats(val dto: MaterialStatsDto, val stockValue: MoneyField, val wastedValue: MoneyField)

/** 094 / T052 — pembaca Dashboard. Satu endpoint server per wilayah, sama dengan web; tanpa cache klien. */
class DashboardRepository(private val api: () -> ManagerApi) {
    suspend fun summary(p: Period): DashboardSummaryDto = apiCall { api().dashboardSummary(p.query) }
    suspend fun attention(): AttentionDto = apiCall { api().dashboardAttention() }
    suspend fun shifts(): OpenShiftsDto = apiCall { api().dashboardShifts() }
    suspend fun orders(p: Period): DashboardOrdersDto = apiCall { api().dashboardOrders(p.query) }

    suspend fun materials(p: Period): MaterialStats = materialStats(apiCall { api().dashboardMaterials(p.query) })

    companion object {
        fun materialStats(raw: JsonObject): MaterialStats = MaterialStats(
            dto = ApiClient.json.decodeFromJsonElement(raw),
            stockValue = moneyField(raw, "stock_value"),
            wastedValue = moneyField(raw, "wasted_value"),
        )

        /** Kunci tidak ada = server menyembunyikan (tanpa menu `reports`, DashboardController::materials). */
        private fun moneyField(raw: JsonObject, key: String): MoneyField {
            if (!raw.containsKey(key)) return MoneyField.Hidden
            val v = raw[key] as? JsonPrimitive ?: return MoneyField.Unknown
            return if (v.content == "null") MoneyField.Unknown else MoneyField.Shown(DashboardModel.rupiah(v.content))
        }
    }
}
