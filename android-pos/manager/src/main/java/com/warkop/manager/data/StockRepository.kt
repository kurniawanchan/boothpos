package com.warkop.manager.data

import com.warkop.core.network.apiCall
import com.warkop.manager.data.dto.AdjustBody
import com.warkop.manager.data.dto.LowStockDto
import com.warkop.manager.data.dto.NewTransferBody
import com.warkop.manager.data.dto.Paged
import com.warkop.manager.data.dto.ReceiveBody
import com.warkop.manager.data.dto.ScopeDto
import com.warkop.manager.data.dto.StockMovementDto
import com.warkop.manager.data.dto.TransferDetailDto
import com.warkop.manager.data.dto.TransferSummaryDto
import com.warkop.manager.domain.AdjustRow
import com.warkop.manager.domain.TransferRow

/** 094 / T058 — Stok. Outlet tiap permintaan = header X-Outlet-Id (outlet terpilih), sama dengan web. */
class StockRepository(private val api: () -> ManagerApi) {
    suspend fun scope(): ScopeDto = apiCall { api().scope() }
    suspend fun movements(type: String?, page: Int): Paged<StockMovementDto> = apiCall { api().stockMovements(type, page) }
    suspend fun low(): List<LowStockDto> = apiCall { api().lowStock() }.data
    suspend fun adjust(body: AdjustBody) = apiUnit { api().adjustStock(body) }

    /** Cari varian untuk koreksi; `onHand` = saldo di outlet itu (`stock_outlet`). */
    suspend fun findVariants(q: String, outletId: Long): List<AdjustRow> =
        apiCall { api().lookupVariants(q, 8, outletId) }.data.map { AdjustRow(it.variantId, it.sku, it.label, it.outletStock ?: it.currentStock) }

    /** Cari item/bahan untuk transfer; `available` = saldo di outlet ASAL. */
    suspend fun findTransferItems(kind: String, q: String, fromOutletId: Long): List<TransferRow> =
        if (kind == "variant") {
            apiCall { api().lookupVariants(q, 8, fromOutletId) }.data.map { TransferRow("variant", it.variantId, it.label, it.sku, (it.outletStock ?: 0).toString()) }
        } else {
            apiCall { api().searchMaterials(q) }.data.map { m ->
                TransferRow("material", m.id, m.name, m.code, m.outletStock.firstOrNull { it.outletId == fromOutletId }?.onHand ?: "0")
            }
        }

    suspend fun transfers(status: String?, page: Int): Paged<TransferSummaryDto> = apiCall { api().transfers(status, page) }
    suspend fun transfer(id: Long): TransferDetailDto = apiCall { api().transfer(id) }
    suspend fun createTransfer(body: NewTransferBody): TransferSummaryDto = apiCall { api().createTransfer(body) }
    suspend fun receive(id: Long, body: ReceiveBody): TransferSummaryDto = apiCall { api().receiveTransfer(id, body) }
    suspend fun cancel(id: Long): TransferSummaryDto = apiCall { api().cancelTransfer(id) }
}
