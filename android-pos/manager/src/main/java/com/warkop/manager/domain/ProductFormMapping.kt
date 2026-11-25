package com.warkop.manager.domain

import com.warkop.core.domain.ApiError
import com.warkop.manager.data.dto.ProductDetailDto

/** 094 / T065, T070 — pengisian formulir dari detail server dan pemetaan galat tiap langkah simpan ke kolom/bagian. */
object ProductFormMapping {
    /**
     * Isi formulir ubah dari `GET /products/{id}`. `outlet_ids` tidak ada di respons: diambil dari `outlets[].carried`
     * (hanya bila multi; satu outlet → outlet itu). Segmen tidak dikirim server dan tidak bisa diubah.
     * Stok asal = `outlet_stock` (saldo outlet header = outlet terpilih).
     */
    fun fromDetail(d: ProductDetailDto, selectedOutletId: Long): ProductForm = ProductForm(
        id = d.id,
        outletIds = d.outlets?.filter { it.carried }?.map { it.id }?.toSet() ?: setOf(selectedOutletId),
        homeOutletId = d.homeOutletId,
        categoryId = d.categoryId,
        name = d.name,
        description = d.description.orEmpty(),
        shortName = d.shortName.orEmpty(),
        taxCategory = d.taxCategory,
        prepMinutes = d.prepMinutes?.toString().orEmpty(),
        isRetail = d.isRetail,
        stockSource = d.stockSource,
        useAsIngredient = d.useAsIngredient,
        ingredientUnit = d.ingredientUnit.orEmpty(),
        isActive = d.isActive,
        stationId = d.stationId,
        variants = d.variants.map { v ->
            val stock = v.outletStock?.toString().orEmpty()
            VariantRow(
                id = v.id, name = v.variantName, sellPrice = ProductForm.moneyInput(v.sellPrice), costPrice = ProductForm.moneyInput(v.costPrice) .ifEmpty { "0" },
                lowStockAlert = v.lowStockAlert?.toString().orEmpty(), trackStock = v.trackStock, isActive = v.isActive,
                originalStock = stock, currentStock = stock, bomComplete = v.bomComplete,
            )
        },
    )

    /**
     * Galat per langkah → [FormStatus]. 422 langkah produk memakai kunci server apa adanya (sama dengan payload);
     * 422 langkah varian ke-i dipindah ke `variants.i.<kolom>`; 422 langkah stok ke `stock_adjustment_reason` bila
     * menyangkut alasan; sisanya pesan umum berlabel langkahnya.
     */
    fun status(failures: List<StepFailure>): FormStatus {
        val fields = mutableMapOf<String, String>()
        val messages = mutableListOf<String>()
        failures.forEach { f ->
            val e = f.error
            if (e is ApiError.Validation && e.fields.isNotEmpty()) {
                e.fields.forEach { (key, msgs) ->
                    val mapped = when (f.step) {
                        SaveStep.VARIANT -> "variants.${f.variantIndex}.$key"
                        SaveStep.VARIANT_PHOTO -> "variants.${f.variantIndex}.image"
                        SaveStep.PRODUCT_PHOTO -> "image"
                        SaveStep.STOCK -> if (key == "reason") "stock_adjustment_reason" else "stock.$key"
                        SaveStep.PRODUCT -> key
                    }
                    fields[mapped] = FormStatus.readable(mapped, msgs.firstOrNull())
                }
            } else {
                val where = f.variantIndex?.let { "${f.step.label} baris ${it + 1}" } ?: f.step.label
                messages += "$where: ${e.message}"
            }
        }
        val stockOther = fields.keys.filter { it.startsWith("stock.") }
        if (stockOther.isNotEmpty()) messages += "Stok: " + stockOther.joinToString("; ") { fields.getValue(it) }
        return FormStatus(fieldErrors = fields - stockOther.toSet(), message = messages.joinToString("\n").ifEmpty { null })
    }
}
