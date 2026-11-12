package com.boothpos.customer.core.data.local

import androidx.room.ColumnInfo
import androidx.room.Dao
import androidx.room.Entity
import androidx.room.PrimaryKey
import androidx.room.Query
import androidx.room.Upsert
import kotlinx.coroutines.flow.Flow

/** Satu varian = satu baris. Harga disimpan sebagai string desimal, sama seperti di kawat. */
@Entity(tableName = "cart_lines")
data class CartLineEntity(
    @PrimaryKey @ColumnInfo(name = "variant_id") val variantId: Long,
    @ColumnInfo(name = "product_slug") val productSlug: String,
    @ColumnInfo(name = "product_name") val productName: String,
    @ColumnInfo(name = "variant_name") val variantName: String,
    @ColumnInfo(name = "image_url") val imageUrl: String?,
    @ColumnInfo(name = "unit_price") val unitPrice: String,
    val quantity: Int,
    @ColumnInfo(name = "max_quantity") val maxQuantity: Int,
    @ColumnInfo(name = "added_at") val addedAt: Long,
)

@Dao
interface CartDao {
    @Query("SELECT * FROM cart_lines ORDER BY added_at ASC, variant_id ASC")
    fun observeAll(): Flow<List<CartLineEntity>>

    @Query("SELECT COALESCE(SUM(quantity), 0) FROM cart_lines")
    fun observeTotalQuantity(): Flow<Int>

    @Query("SELECT * FROM cart_lines WHERE variant_id = :variantId")
    suspend fun find(variantId: Long): CartLineEntity?

    @Query("SELECT COUNT(*) FROM cart_lines")
    suspend fun count(): Int

    @Upsert
    suspend fun upsert(line: CartLineEntity)

    @Query("UPDATE cart_lines SET quantity = :quantity WHERE variant_id = :variantId")
    suspend fun updateQuantity(variantId: Long, quantity: Int)

    @Query("UPDATE cart_lines SET max_quantity = :maxQuantity WHERE variant_id = :variantId")
    suspend fun updateMaxQuantity(variantId: Long, maxQuantity: Int)

    @Query("UPDATE cart_lines SET unit_price = :unitPrice WHERE variant_id = :variantId")
    suspend fun updateUnitPrice(variantId: Long, unitPrice: String)

    @Query("DELETE FROM cart_lines WHERE variant_id = :variantId")
    suspend fun delete(variantId: Long)

    @Query("DELETE FROM cart_lines")
    suspend fun clear()
}
