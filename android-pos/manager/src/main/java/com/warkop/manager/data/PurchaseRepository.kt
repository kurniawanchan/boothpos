package com.warkop.manager.data

import com.warkop.core.network.apiCall
import com.warkop.manager.data.dto.MaterialAdjustBody
import com.warkop.manager.data.dto.MaterialBody
import com.warkop.manager.data.dto.MaterialDto
import com.warkop.manager.data.dto.MaterialMovementDto
import com.warkop.manager.data.dto.Paged
import com.warkop.manager.data.dto.PoBody
import com.warkop.manager.data.dto.PoPaymentBody
import com.warkop.manager.data.dto.PoStatusBody
import com.warkop.manager.data.dto.PurchaseOrderDto
import com.warkop.manager.data.dto.VendorBody
import com.warkop.manager.data.dto.VendorDto
import com.warkop.manager.data.dto.VendorPriceBody
import com.warkop.manager.data.dto.VendorPriceDto

/** 094 / T077 — Vendor, Bahan, PO. Hanya meneruskan ke endpoint yang sama dengan web. */
class PurchaseRepository(private val api: () -> ManagerApi) {
    // Vendor
    suspend fun vendors(search: String?, page: Int): Paged<VendorDto> = apiCall { api().vendors(search?.takeIf { it.isNotBlank() }, page = page) }
    suspend fun activeVendors(): List<VendorDto> = apiCall { api().vendors(active = 1, perPage = 100) }.data
    suspend fun vendor(id: Long): VendorDto = apiCall { api().vendor(id) }
    suspend fun saveVendor(id: Long?, body: VendorBody): VendorDto = apiCall { if (id == null) api().createVendor(body) else api().updateVendor(id, body) }
    suspend fun deleteVendor(id: Long) = apiUnit { api().deleteVendor(id) }

    // Bahan
    suspend fun materials(search: String?, kind: String?, stockStatus: String?, page: Int): Paged<MaterialDto> =
        apiCall { api().materials(search?.takeIf { it.isNotBlank() }, kind, stockStatus, page) }
    suspend fun material(id: Long): MaterialDto = apiCall { api().material(id) }
    suspend fun saveMaterial(id: Long?, body: MaterialBody): MaterialDto = apiCall { if (id == null) api().createMaterial(body) else api().updateMaterial(id, body) }
    suspend fun deleteMaterial(id: Long) = apiUnit { api().deleteMaterial(id) }
    suspend fun adjustMaterial(id: Long, body: MaterialAdjustBody) = apiCall { api().adjustMaterial(id, body) }
    suspend fun materialMovements(id: Long, page: Int): Paged<MaterialMovementDto> = apiCall { api().materialMovements(id, page) }
    suspend fun saveVendorPrice(materialId: Long, id: Long?, body: VendorPriceBody): VendorPriceDto =
        apiCall { if (id == null) api().createVendorPrice(materialId, body) else api().updateVendorPrice(id, body) }
    suspend fun deleteVendorPrice(id: Long) = apiUnit { api().deleteVendorPrice(id) }

    // PO
    suspend fun purchaseOrders(status: String?, outletId: Long?, page: Int): Paged<PurchaseOrderDto> = apiCall { api().purchaseOrders(status, outletId, page) }
    suspend fun purchaseOrder(id: Long): PurchaseOrderDto = apiCall { api().purchaseOrder(id) }
    suspend fun savePurchaseOrder(id: Long?, body: PoBody): PurchaseOrderDto = apiCall { if (id == null) api().createPurchaseOrder(body) else api().updatePurchaseOrder(id, body) }
    suspend fun deletePurchaseOrder(id: Long) = apiUnit { api().deletePurchaseOrder(id) }
    suspend fun setStatus(id: Long, status: String, cancelReason: String?): PurchaseOrderDto = apiCall { api().setPurchaseOrderStatus(id, PoStatusBody(status, cancelReason)) }
    suspend fun pay(id: Long, amount: Double, notes: String?): PurchaseOrderDto = apiCall { api().payPurchaseOrder(id, PoPaymentBody(amount = amount, notes = notes)) }
}
