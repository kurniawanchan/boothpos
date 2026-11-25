package com.warkop.manager.domain

import com.warkop.core.domain.ApiError
import com.warkop.core.network.ApiClient
import com.warkop.manager.data.dto.AdjustBody
import com.warkop.manager.data.dto.AdjustItem
import com.warkop.manager.data.dto.NewVariantBody
import com.warkop.manager.data.dto.ProductBody
import com.warkop.manager.data.dto.ProductSavedDto
import com.warkop.manager.data.dto.ProductVariantDto
import com.warkop.manager.data.dto.VariantBody

/** Panggilan server yang dibutuhkan simpan produk; dipisah supaya urutan bisa dites tanpa jaringan. */
interface ProductSaveApi {
    suspend fun create(body: ProductBody): ProductSavedDto
    suspend fun update(id: Long, body: ProductBody): ProductSavedDto
    suspend fun createVariant(productId: Long, body: VariantBody): ProductVariantDto
    suspend fun updateVariant(id: Long, body: VariantBody)
    suspend fun productImage(id: Long, uri: String)
    suspend fun variantImage(id: Long, uri: String)
    suspend fun adjust(body: AdjustBody)
}

enum class SaveStep(val label: String) { PRODUCT("Produk"), VARIANT("Varian"), PRODUCT_PHOTO("Foto produk"), VARIANT_PHOTO("Foto varian"), STOCK("Stok") }

data class StepFailure(val step: SaveStep, val error: ApiError, val variantIndex: Int? = null)

data class SaveOutcome(val form: ProductForm, val failures: List<StepFailure>, val warnings: List<String>) {
    val ok: Boolean get() = failures.isEmpty()
}

/**
 * 094 / T066 — urutan simpan produk SAMA dengan `ProductFormDrawer.vue` (produk → varian → foto produk → foto varian →
 * koreksi stok), tetapi MEMBAIKI satu hal yang web tidak lakukan: kegagalan setelah produk tercipta tidak hilang dan
 * tidak pernah memicu `POST /products` kedua. Tiap langkah yang berhasil MENGUBAH formulir (id tercatat, kiriman dicatat
 * di `sent`, foto dikosongkan, stok asal = stok baru), jadi Simpan berikutnya otomatis hanya mengulang yang gagal atau
 * yang isinya berubah sejak itu.
 *
 * Perbedaan sadar dari web: varian yang dinonaktifkan saat BUAT diproses lewat `PUT /variants/{id}` per baris (web:
 * `PUT /products/{id}/variants` sekaligus); hasilnya sama — varian tercipta lalu dinonaktifkan.
 *
 * Tautan modifier (T068) disimpan oleh layarnya sendiri, bukan di sini.
 */
class ProductSaveOrchestrator(
    private val api: ProductSaveApi,
    /** Pengguna boleh melihat biaya (menu products/reports); tanpa itu `cost_price` tidak dikirim saat ubah. */
    private val canSeeCost: Boolean,
    /** Outlet terlihat > 1: `outlet_ids` dikirim (web hanya mengirimnya saat multi). */
    private val multi: Boolean,
    /** Outlet terpilih: tempat koreksi stok dibukukan. */
    private val outletId: Long,
) {
    suspend fun save(start: ProductForm): SaveOutcome {
        var f = start
        val failures = mutableListOf<StepFailure>()
        val warnings = mutableListOf<String>()

        // 1. Produk. Gagal = berhenti (tanpa produk tidak ada langkah lain yang bisa jalan).
        val productBody = productBody(f)
        val productKey = hash(productBody.copy(variants = null))
        if (f.id == null) {
            val created = try { api.create(productBody) } catch (e: ApiError) {
                return SaveOutcome(f, listOf(StepFailure(SaveStep.PRODUCT, e)), warnings)
            }
            warnings += created.warnings.map { it.message }
            // Varian dipetakan PER URUTAN, sama dengan web (respons membawa varian dalam urutan kiriman).
            val rows = f.variants.mapIndexed { i, r -> created.variants.getOrNull(i)?.let { v -> r.copy(id = v.id) } ?: r }
            f = f.copy(
                id = created.id,
                createdHere = true,
                variants = rows,
                // Kiriman yang SUDAH berlaku saat buat: varian aktif dengan nilai ini. Baris yang dinonaktifkan berbeda → PUT.
                sent = f.sent + ("product" to productKey) + rows.filter { it.id != null }.associate { "variant:${it.key}" to hash(variantBody(it.copy(isActive = true))) },
            )
        } else if (f.sent["product"] != productKey) {
            try {
                warnings += api.update(f.id!!, productBody).warnings.map { it.message }
                f = f.copy(sent = f.sent + ("product" to productKey))
            } catch (e: ApiError) {
                return SaveOutcome(f, listOf(StepFailure(SaveStep.PRODUCT, e)), warnings)
            }
        }
        val productId = f.id!!

        // 2. Varian: hanya yang baru atau berubah sejak kiriman terakhir yang berhasil.
        f.variants.forEachIndexed { i, row ->
            val body = variantBody(row)
            val key = hash(body)
            if (row.id != null && f.sent["variant:${row.key}"] == key) return@forEachIndexed
            try {
                val id = if (row.id == null) api.createVariant(productId, body).id else { api.updateVariant(row.id, body); row.id }
                f = f.copy(
                    variants = f.variants.map { if (it.key == row.key) it.copy(id = id) else it },
                    sent = f.sent + ("variant:${row.key}" to key),
                )
            } catch (e: ApiError) {
                failures += StepFailure(SaveStep.VARIANT, e, i)
            }
        }

        // 3. Foto produk.
        f.photo?.let { uri ->
            try { api.productImage(productId, uri); f = f.copy(photo = null) } catch (e: ApiError) { failures += StepFailure(SaveStep.PRODUCT_PHOTO, e) }
        }

        // 4. Foto varian (hanya varian yang sudah punya id).
        f.variants.forEachIndexed { i, row ->
            val uri = row.photo ?: return@forEachIndexed
            val id = row.id ?: return@forEachIndexed
            try {
                api.variantImage(id, uri)
                f = f.copy(variants = f.variants.map { if (it.key == row.key) it.copy(photo = null) else it })
            } catch (e: ApiError) {
                failures += StepFailure(SaveStep.VARIANT_PHOTO, e, i)
            }
        }

        // 5. Koreksi stok: selisih stok baru − stok asal, bukan nol, hanya sumber stok varian.
        val deltas = f.stockDeltas()
        if (deltas.isNotEmpty()) {
            val reason = if (f.createdHere) ProductForm.OPENING_REASON else f.stockReason.trim()
            try {
                api.adjust(AdjustBody(reason, outletId, deltas.map { (r, d) -> AdjustItem(r.id!!, d) }))
                val keys = deltas.map { it.first.key }.toSet()
                f = f.copy(variants = f.variants.map { if (it.key in keys) it.copy(originalStock = it.currentStock) else it }, stockReason = "")
            } catch (e: ApiError) {
                failures += StepFailure(SaveStep.STOCK, e)
            }
        }
        return SaveOutcome(f, failures, warnings)
    }

    fun productBody(f: ProductForm) = ProductBody(
        outletIds = if (multi) f.outletIds.sorted() else null,
        homeOutletId = f.homeOutletId ?: error("home_outlet_id kosong"),
        categoryId = f.categoryId ?: error("category_id kosong"),
        productSegment = if (f.id == null) f.segment.trim().ifEmpty { null } else null,
        name = f.name,
        description = f.description.ifEmpty { null },
        isActive = f.isActive,
        stationId = f.stationId,
        shortName = f.shortName.trim().ifEmpty { null },
        taxCategory = f.taxCategory,
        prepMinutes = f.prepMinutes.toIntOrNull(),
        isRetail = f.isRetail,
        stockSource = f.stockSource,
        useAsIngredient = f.useAsIngredient,
        ingredientUnit = if (f.useAsIngredient) f.ingredientUnit.trim().ifEmpty { null } else null,
        variants = if (f.id == null) f.variants.map { r ->
            NewVariantBody(r.name.trim().ifEmpty { "Standard" }, ProductForm.money(r.costPrice), ProductForm.money(r.sellPrice), r.lowStockAlert.toIntOrNull(), r.trackStock)
        } else null,
    )

    fun variantBody(r: VariantRow) = VariantBody(
        variantName = r.name.trim().ifEmpty { "Standard" },
        // Harga modal terkunci saat BOM lengkap (server 409 bila diubah); tanpa izin biaya tidak dikirim.
        costPrice = if (canSeeCost && !r.bomComplete) ProductForm.money(r.costPrice) else null,
        sellPrice = ProductForm.money(r.sellPrice),
        lowStockAlert = r.lowStockAlert.toIntOrNull(),
        isActive = r.isActive,
        trackStock = r.trackStock,
    )

    private fun hash(body: ProductBody) = ApiClient.json.encodeToString(ProductBody.serializer(), body)
    private fun hash(body: VariantBody) = ApiClient.json.encodeToString(VariantBody.serializer(), body)
}
