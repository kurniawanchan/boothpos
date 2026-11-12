package com.boothpos.customer.core.data.cart

import androidx.room.withTransaction
import com.boothpos.customer.core.data.domain.cart.CartAddResult
import com.boothpos.customer.core.data.domain.cart.CartRepository
import com.boothpos.customer.core.data.domain.model.CartItem
import com.boothpos.customer.core.data.domain.model.CartLine
import com.boothpos.customer.core.data.domain.model.CartLineRefresh
import com.boothpos.customer.core.data.domain.model.StoreConfig
import com.boothpos.customer.core.data.domain.money.Money
import com.boothpos.customer.core.data.domain.session.SignOutReason
import com.boothpos.customer.core.data.domain.session.UserScopedDataCleaner
import com.boothpos.customer.core.data.domain.store.StoreConfigRepository
import com.boothpos.customer.core.data.local.AppDatabase
import com.boothpos.customer.core.data.local.CartLineEntity
import java.time.Clock
import javax.inject.Inject
import javax.inject.Singleton
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.flow.map

/**
 * Keranjang di Room. Aturan di sini hanya menjaga BENTUK masukan (jumlah 1..batas, jumlah baris
 * maksimum) dengan batas yang diberikan server; ketersediaan dan harga sebenarnya diputuskan
 * server saat quote/checkout.
 */
@Singleton
class CartRepositoryImpl @Inject constructor(
    private val database: AppDatabase,
    private val storeConfig: StoreConfigRepository,
    private val clock: Clock,
) : CartRepository {

    private val dao = database.cartDao()

    override val lines: Flow<List<CartLine>> = dao.observeAll().map { rows -> rows.map { it.toDomain() } }

    override val itemCount: Flow<Int> = dao.observeTotalQuantity()

    override suspend fun add(item: CartItem, quantity: Int): CartAddResult {
        require(quantity >= 1) { "Jumlah harus minimal 1" }
        if (item.maxQuantity < 1) return CartAddResult.Unavailable
        val maxLines = storeConfig.config.first()?.maxLinesPerOrder ?: StoreConfig.DEFAULT_MAX_LINES_PER_ORDER
        return database.withTransaction {
            val existing = dao.find(item.variantId)
            if (existing == null && dao.count() >= maxLines) {
                return@withTransaction CartAddResult.CartFull(maxLines)
            }
            val wanted = (existing?.quantity ?: 0) + quantity
            val applied = wanted.coerceAtMost(item.maxQuantity)
            dao.upsert(
                CartLineEntity(
                    variantId = item.variantId,
                    productSlug = item.productSlug,
                    productName = item.productName,
                    variantName = item.variantName,
                    imageUrl = item.imageUrl,
                    unitPrice = Money.toWire(item.unitPrice),
                    quantity = applied,
                    maxQuantity = item.maxQuantity,
                    addedAt = existing?.addedAt ?: clock.millis(),
                ),
            )
            if (applied < wanted) CartAddResult.QuantityCapped(applied, item.maxQuantity) else CartAddResult.Added(applied)
        }
    }

    override suspend fun setQuantity(variantId: Long, quantity: Int): Int? = database.withTransaction {
        val existing = dao.find(variantId) ?: return@withTransaction null
        if (quantity <= 0) {
            dao.delete(variantId)
            return@withTransaction null
        }
        val applied = quantity.coerceAtMost(existing.maxQuantity.coerceAtLeast(1))
        dao.updateQuantity(variantId, applied)
        applied
    }

    override suspend fun remove(variantId: Long) = dao.delete(variantId)

    override suspend fun clear() = dao.clear()

    override suspend fun refresh(updates: List<CartLineRefresh>) {
        if (updates.isEmpty()) return
        database.withTransaction {
            updates.forEach { update ->
                dao.updateMaxQuantity(update.variantId, update.maxQuantity.coerceAtLeast(0))
                update.unitPrice?.let { dao.updateUnitPrice(update.variantId, Money.toWire(it)) }
            }
        }
    }

    private fun CartLineEntity.toDomain() = CartLine(
        variantId = variantId,
        productSlug = productSlug,
        productName = productName,
        variantName = variantName,
        imageUrl = imageUrl,
        unitPrice = Money.parseOrZero(unitPrice),
        quantity = quantity,
        maxQuantity = maxQuantity,
    )
}

/**
 * Keranjang dikosongkan saat pelanggan MENEKAN keluar (perangkat bisa dipakai orang lain), tetapi
 * dipertahankan bila sesi berakhir sendiri, supaya pelanggan dapat masuk lagi dan melanjutkan
 * checkout. ASUMSI yang dicatat di README.
 */
class CartSignOutCleaner @Inject constructor(private val cart: CartRepository) : UserScopedDataCleaner {
    override suspend fun clear(reason: SignOutReason) {
        if (reason == SignOutReason.UserRequested) cart.clear()
    }
}
