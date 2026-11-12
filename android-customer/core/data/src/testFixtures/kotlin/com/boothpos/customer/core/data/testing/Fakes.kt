package com.boothpos.customer.core.data.testing

import com.boothpos.customer.core.data.domain.address.AddressRepository
import com.boothpos.customer.core.data.domain.cart.CartAddResult
import com.boothpos.customer.core.data.domain.cart.CartRepository
import com.boothpos.customer.core.data.domain.model.Account
import com.boothpos.customer.core.data.domain.model.Address
import com.boothpos.customer.core.data.domain.model.AddressInput
import com.boothpos.customer.core.data.domain.model.CartItem
import com.boothpos.customer.core.data.domain.model.CartLine
import com.boothpos.customer.core.data.domain.model.CartLineRefresh
import com.boothpos.customer.core.data.domain.model.StoreConfig
import com.boothpos.customer.core.data.domain.session.SessionRepository
import com.boothpos.customer.core.data.domain.session.SessionState
import com.boothpos.customer.core.data.domain.session.SignOutReason
import com.boothpos.customer.core.data.domain.store.StoreConfigRepository
import com.boothpos.customer.core.data.domain.util.ConnectivityObserver
import com.boothpos.customer.core.data.security.StoredSession
import com.boothpos.customer.core.data.security.TokenStore
import com.boothpos.customer.core.network.error.ApiError
import com.boothpos.customer.core.network.error.ApiResult
import java.time.Instant
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.map

// Fake bersama untuk tes JVM semua modul (testFixtures: tidak pernah ikut APK).
// Perilakunya sengaja sederhana dan sama dengan kontrak domain, bukan tiruan implementasi.

class InMemoryTokenStore(initial: StoredSession? = null) : TokenStore {
    @Volatile
    var stored: StoredSession? = initial

    override fun peekToken(): String? = stored?.token

    override suspend fun load(): StoredSession? = stored

    override suspend fun save(session: StoredSession) {
        stored = session
    }

    override suspend fun clear() {
        stored = null
    }
}

class FakeSessionRepository(initial: SessionState = SessionState.SignedOut()) : SessionRepository {
    private val _state = MutableStateFlow(initial)
    override val state = _state

    var signOutCalls = 0
        private set

    override suspend fun startSession(token: String, expiresAt: Instant?, account: Account) {
        _state.value = SessionState.SignedIn(account, expiresAt)
    }

    override suspend fun updateAccount(account: Account) {
        val current = _state.value as? SessionState.SignedIn ?: return
        _state.value = current.copy(account = account)
    }

    override suspend fun signOut() {
        signOutCalls++
        _state.value = SessionState.SignedOut(SignOutReason.UserRequested)
    }

    /** Meniru 401 dari server: sesi berakhir tanpa diminta pengguna. */
    fun expire() {
        _state.value = SessionState.SignedOut(SignOutReason.SessionExpired)
    }

    fun set(state: SessionState) {
        _state.value = state
    }
}

class FakeCartRepository(initial: List<CartLine> = emptyList()) : CartRepository {
    private val state = MutableStateFlow(initial)
    override val lines: Flow<List<CartLine>> = state
    override val itemCount: Flow<Int> = state.map { lines -> lines.sumOf { it.quantity } }

    val current: List<CartLine> get() = state.value

    override suspend fun add(item: CartItem, quantity: Int): CartAddResult {
        if (item.maxQuantity < 1) return CartAddResult.Unavailable
        val existing = state.value.firstOrNull { it.variantId == item.variantId }
        val wanted = (existing?.quantity ?: 0) + quantity
        val applied = wanted.coerceAtMost(item.maxQuantity)
        val line = CartLine(
            variantId = item.variantId,
            productSlug = item.productSlug,
            productName = item.productName,
            variantName = item.variantName,
            imageUrl = item.imageUrl,
            unitPrice = item.unitPrice,
            quantity = applied,
            maxQuantity = item.maxQuantity,
        )
        state.value = if (existing == null) state.value + line else state.value.map { if (it.variantId == item.variantId) line else it }
        return if (applied < wanted) CartAddResult.QuantityCapped(applied, item.maxQuantity) else CartAddResult.Added(applied)
    }

    override suspend fun setQuantity(variantId: Long, quantity: Int): Int? {
        val existing = state.value.firstOrNull { it.variantId == variantId } ?: return null
        if (quantity <= 0) {
            remove(variantId)
            return null
        }
        val applied = quantity.coerceAtMost(existing.maxQuantity.coerceAtLeast(1))
        state.value = state.value.map { if (it.variantId == variantId) it.copy(quantity = applied) else it }
        return applied
    }

    override suspend fun remove(variantId: Long) {
        state.value = state.value.filterNot { it.variantId == variantId }
    }

    override suspend fun clear() {
        state.value = emptyList()
    }

    override suspend fun refresh(updates: List<CartLineRefresh>) {
        val byId = updates.associateBy { it.variantId }
        state.value = state.value.map { line ->
            val update = byId[line.variantId] ?: return@map line
            line.copy(unitPrice = update.unitPrice ?: line.unitPrice, maxQuantity = update.maxQuantity.coerceAtLeast(0))
        }
    }
}

class FakeStoreConfigRepository(initial: StoreConfig? = null) : StoreConfigRepository {
    private val state = MutableStateFlow(initial)
    override val config: Flow<StoreConfig?> = state

    /** Hasil `refresh()` berikutnya; bila sukses, nilainya juga menjadi konfigurasi tersimpan. */
    var refreshResult: ApiResult<StoreConfig> = ApiResult.Failure(ApiError.Network)
    var refreshCalls = 0
        private set

    override suspend fun refresh(): ApiResult<StoreConfig> {
        refreshCalls++
        val result = refreshResult
        if (result is ApiResult.Success) state.value = result.value
        return result
    }
}

class FakeAddressRepository(initial: List<Address> = emptyList()) : AddressRepository {
    var addresses: List<Address> = initial

    /** Bila diisi, setiap panggilan berikutnya gagal dengan galat ini. */
    var failure: ApiError? = null
    private var nextId = (initial.maxOfOrNull { it.id } ?: 0L) + 1

    private fun <T> respond(block: () -> T): ApiResult<T> =
        failure?.let { ApiResult.Failure(it) } ?: ApiResult.Success(block())

    override suspend fun list(): ApiResult<List<Address>> = respond { addresses.sortedByDescending { it.isDefault } }

    override suspend fun create(input: AddressInput): ApiResult<Address> = respond {
        val created = input.toAddress(nextId++, forceDefault = addresses.isEmpty())
        store(created)
        created
    }

    override suspend fun update(id: Long, input: AddressInput): ApiResult<Address> = respond {
        val updated = input.toAddress(id, forceDefault = false)
        addresses = addresses.filterNot { it.id == id }
        store(updated)
        updated
    }

    override suspend fun delete(id: Long): ApiResult<Unit> = respond {
        addresses = addresses.filterNot { it.id == id }
    }

    private fun store(address: Address) {
        addresses = (if (address.isDefault) addresses.map { it.copy(isDefault = false) } else addresses) + address
    }

    private fun AddressInput.toAddress(id: Long, forceDefault: Boolean) = Address(
        id = id,
        label = label,
        recipientName = recipientName,
        phone = phone,
        addressLine = addressLine,
        city = city,
        province = province,
        postalCode = postalCode,
        notes = notes,
        isDefault = isDefault || forceDefault,
    )
}

/** Status koneksi yang diatur tes; nilai awal dan setiap perubahan dipancarkan seperti aslinya. */
class FakeConnectivityObserver(initiallyOnline: Boolean = true) : ConnectivityObserver {
    private val state = MutableStateFlow(initiallyOnline)
    override val isOnline: Flow<Boolean> = state

    fun set(online: Boolean) {
        state.value = online
    }
}
