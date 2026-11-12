package com.boothpos.customer.core.data.local

import androidx.room.ColumnInfo
import androidx.room.Dao
import androidx.room.Embedded
import androidx.room.Entity
import androidx.room.ForeignKey
import androidx.room.Index
import androidx.room.Insert
import androidx.room.OnConflictStrategy
import androidx.room.PrimaryKey
import androidx.room.Query
import androidx.room.Relation
import androidx.room.Transaction
import androidx.room.Upsert
import kotlinx.coroutines.flow.Flow

/** Batas tetap cache katalog. Cache adalah bantuan offline/penampil cepat, bukan salinan katalog. */
object CatalogCacheLimits {
    const val MAX_PRODUCTS = 200
    const val MAX_CATEGORIES = 100

    /** `list_position` untuk produk yang masuk cache lewat halaman detail, bukan lewat daftar. */
    const val UNLISTED_POSITION = Int.MAX_VALUE
}

/** Kartu produk (`ProductCard` kontrak §6). Hanya field publik; harga sebagai string desimal. */
@Entity(tableName = "cached_products", indices = [Index("cached_at")])
data class CachedProductEntity(
    @PrimaryKey val slug: String,
    val name: String,
    @ColumnInfo(name = "image_url") val imageUrl: String?,
    @ColumnInfo(name = "category_id") val categoryId: Long,
    @ColumnInfo(name = "category_name") val categoryName: String,
    @ColumnInfo(name = "seller_name") val sellerName: String,
    @ColumnInfo(name = "price_min") val priceMin: String,
    @ColumnInfo(name = "price_max") val priceMax: String,
    @ColumnInfo(name = "in_stock") val inStock: Boolean,
    /** Urutan di daftar bawaan dari server; [CatalogCacheLimits.UNLISTED_POSITION] bila bukan dari daftar. */
    @ColumnInfo(name = "list_position") val listPosition: Int,
    @ColumnInfo(name = "cached_at") val cachedAt: Long,
)

/** Bagian tambahan `ProductDetail`; ikut terhapus bila produknya dipangkas dari cache. */
@Entity(
    tableName = "cached_product_details",
    foreignKeys = [
        ForeignKey(
            entity = CachedProductEntity::class,
            parentColumns = ["slug"],
            childColumns = ["slug"],
            onDelete = ForeignKey.CASCADE,
        ),
    ],
)
data class CachedProductDetailEntity(
    @PrimaryKey val slug: String,
    val description: String?,
    @ColumnInfo(name = "cached_at") val cachedAt: Long,
)

@Entity(
    tableName = "cached_variants",
    foreignKeys = [
        ForeignKey(
            entity = CachedProductEntity::class,
            parentColumns = ["slug"],
            childColumns = ["product_slug"],
            onDelete = ForeignKey.CASCADE,
        ),
    ],
    indices = [Index("product_slug")],
)
data class CachedVariantEntity(
    @PrimaryKey val id: Long,
    @ColumnInfo(name = "product_slug") val productSlug: String,
    val name: String,
    @ColumnInfo(name = "image_url") val imageUrl: String?,
    val price: String,
    @ColumnInfo(name = "in_stock") val inStock: Boolean,
    @ColumnInfo(name = "max_qty") val maxQty: Int,
    val position: Int,
)

@Entity(tableName = "cached_categories")
data class CachedCategoryEntity(
    @PrimaryKey val id: Long,
    val name: String,
    @ColumnInfo(name = "image_url") val imageUrl: String?,
    val position: Int,
)

data class CachedProductWithDetail(
    @Embedded val product: CachedProductEntity,
    @Relation(parentColumn = "slug", entityColumn = "slug") val detail: CachedProductDetailEntity?,
    @Relation(parentColumn = "slug", entityColumn = "product_slug") val variants: List<CachedVariantEntity>,
)

@Dao
abstract class CatalogCacheDao {

    /** Daftar offline: hanya produk yang berasal dari daftar bawaan, dalam urutan server. */
    @Query(
        "SELECT * FROM cached_products WHERE list_position < ${CatalogCacheLimits.UNLISTED_POSITION} " +
            "ORDER BY list_position ASC, slug ASC LIMIT :limit",
    )
    abstract fun observeListedProducts(limit: Int): Flow<List<CachedProductEntity>>

    @Transaction
    @Query("SELECT * FROM cached_products WHERE slug = :slug")
    abstract suspend fun findProductWithDetail(slug: String): CachedProductWithDetail?

    @Query("SELECT * FROM cached_categories ORDER BY position ASC, id ASC")
    abstract fun observeCategories(): Flow<List<CachedCategoryEntity>>

    @Query("SELECT COUNT(*) FROM cached_products")
    abstract suspend fun countProducts(): Int

    @Upsert
    protected abstract suspend fun upsertProducts(products: List<CachedProductEntity>)

    @Insert(onConflict = OnConflictStrategy.IGNORE)
    protected abstract suspend fun insertProductIfAbsent(product: CachedProductEntity): Long

    @Query(
        "UPDATE cached_products SET name = :name, image_url = :imageUrl, category_id = :categoryId, " +
            "category_name = :categoryName, seller_name = :sellerName, price_min = :priceMin, " +
            "price_max = :priceMax, in_stock = :inStock, cached_at = :cachedAt WHERE slug = :slug",
    )
    protected abstract suspend fun updateCardKeepingPosition(
        slug: String,
        name: String,
        imageUrl: String?,
        categoryId: Long,
        categoryName: String,
        sellerName: String,
        priceMin: String,
        priceMax: String,
        inStock: Boolean,
        cachedAt: Long,
    )

    @Upsert
    protected abstract suspend fun upsertDetail(detail: CachedProductDetailEntity)

    @Query("DELETE FROM cached_variants WHERE product_slug = :slug")
    protected abstract suspend fun deleteVariants(slug: String)

    @Insert(onConflict = OnConflictStrategy.REPLACE)
    protected abstract suspend fun insertVariants(variants: List<CachedVariantEntity>)

    /** Menyisakan [cap] produk yang paling baru di-cache; detail dan variannya ikut terhapus (CASCADE). */
    @Query(
        "DELETE FROM cached_products WHERE slug NOT IN " +
            "(SELECT slug FROM cached_products ORDER BY cached_at DESC, list_position ASC LIMIT :cap)",
    )
    protected abstract suspend fun pruneProducts(cap: Int)

    @Query("DELETE FROM cached_categories")
    protected abstract suspend fun deleteCategories()

    @Insert(onConflict = OnConflictStrategy.REPLACE)
    protected abstract suspend fun insertCategories(categories: List<CachedCategoryEntity>)

    @Query("DELETE FROM cached_products")
    abstract suspend fun clearProducts()

    /** Produk yang dijawab server "tidak ada lagi" (404) dibuang; detail dan variannya ikut (CASCADE). */
    @Query("DELETE FROM cached_products WHERE slug = :slug")
    abstract suspend fun deleteProduct(slug: String)

    @Query("UPDATE cached_products SET list_position = ${CatalogCacheLimits.UNLISTED_POSITION}")
    protected abstract suspend fun unlistAllProducts()

    /**
     * Halaman PERTAMA daftar bawaan menggantikan daftar offline lama: produk yang tidak lagi ada di
     * daftar server dikeluarkan dari daftar offline (tetap tersimpan sebagai cache detail sampai
     * terpangkas), supaya daftar offline tidak pernah mencampur urutan lama dan baru.
     */
    @Transaction
    open suspend fun replaceListedProducts(products: List<CachedProductEntity>, cap: Int = CatalogCacheLimits.MAX_PRODUCTS) {
        unlistAllProducts()
        upsertProducts(products)
        pruneProducts(cap)
    }

    /** Menyimpan kartu dari daftar lalu memangkas cache ke batas tetap, dalam satu transaksi. */
    @Transaction
    open suspend fun cacheListedProducts(products: List<CachedProductEntity>, cap: Int = CatalogCacheLimits.MAX_PRODUCTS) {
        upsertProducts(products)
        pruneProducts(cap)
    }

    /**
     * Menyimpan detail satu produk. Posisi daftar produk yang sudah ada dipertahankan, supaya
     * membuka detail tidak mengeluarkan produk itu dari daftar offline.
     */
    @Transaction
    open suspend fun cacheProductDetail(
        product: CachedProductEntity,
        description: String?,
        variants: List<CachedVariantEntity>,
        cap: Int = CatalogCacheLimits.MAX_PRODUCTS,
    ) {
        if (insertProductIfAbsent(product) == -1L) {
            updateCardKeepingPosition(
                slug = product.slug,
                name = product.name,
                imageUrl = product.imageUrl,
                categoryId = product.categoryId,
                categoryName = product.categoryName,
                sellerName = product.sellerName,
                priceMin = product.priceMin,
                priceMax = product.priceMax,
                inStock = product.inStock,
                cachedAt = product.cachedAt,
            )
        }
        upsertDetail(CachedProductDetailEntity(product.slug, description, product.cachedAt))
        deleteVariants(product.slug)
        insertVariants(variants.map { it.copy(productSlug = product.slug) })
        pruneProducts(cap)
    }

    @Transaction
    open suspend fun replaceCategories(categories: List<CachedCategoryEntity>) {
        deleteCategories()
        insertCategories(categories.take(CatalogCacheLimits.MAX_CATEGORIES))
    }
}
