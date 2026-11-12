package com.boothpos.customer.core.data.local

import androidx.room.Database
import androidx.room.RoomDatabase

/**
 * Basis data lokal: keranjang dan cache katalog berbatas. TIDAK berisi token, akun, alamat, atau
 * pesanan. Skema diekspor ke `core/data/schemas/`; perubahan skema setelah rilis pertama wajib
 * disertai Migration (tidak ada fallback destruktif).
 */
@Database(
    entities = [
        CartLineEntity::class,
        CachedProductEntity::class,
        CachedProductDetailEntity::class,
        CachedVariantEntity::class,
        CachedCategoryEntity::class,
    ],
    version = 1,
    exportSchema = true,
)
abstract class AppDatabase : RoomDatabase() {
    abstract fun cartDao(): CartDao

    abstract fun catalogCacheDao(): CatalogCacheDao

    companion object {
        const val NAME = "boothpos_customer.db"
    }
}
