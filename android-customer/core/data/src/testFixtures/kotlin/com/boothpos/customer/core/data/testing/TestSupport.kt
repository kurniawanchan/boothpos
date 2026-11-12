package com.boothpos.customer.core.data.testing

import com.boothpos.customer.core.data.domain.model.Account
import com.boothpos.customer.core.data.domain.model.FulfilmentMethod
import com.boothpos.customer.core.data.domain.model.StoreConfig
import com.boothpos.customer.core.data.domain.util.DispatcherProvider
import java.time.Instant
import kotlinx.coroutines.CoroutineDispatcher
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.test.StandardTestDispatcher
import kotlinx.coroutines.test.TestDispatcher
import kotlinx.coroutines.test.resetMain
import kotlinx.coroutines.test.setMain
import org.junit.rules.TestWatcher
import org.junit.runner.Description

class TestDispatcherProvider(dispatcher: CoroutineDispatcher) : DispatcherProvider {
    override val main: CoroutineDispatcher = dispatcher
    override val io: CoroutineDispatcher = dispatcher
    override val default: CoroutineDispatcher = dispatcher
}

/** Mengganti Dispatchers.Main untuk tes ViewModel (`viewModelScope`). */
@OptIn(ExperimentalCoroutinesApi::class)
class MainDispatcherRule(val dispatcher: TestDispatcher = StandardTestDispatcher()) : TestWatcher() {
    override fun starting(description: Description) = Dispatchers.setMain(dispatcher)

    override fun finished(description: Description) = Dispatchers.resetMain()
}

/** Data contoh yang jelas-jelas palsu; tidak ada data pribadi sungguhan di tes. */
object TestData {
    fun account(
        id: Long = 1,
        name: String = "Pelanggan Uji",
        email: String = "pelanggan.uji@example.test",
        phone: String? = "0800-0000-0000",
    ) = Account(
        id = id,
        name = name,
        email = email,
        phone = phone,
        emailVerified = false,
        createdAt = Instant.parse("2026-11-11T03:00:00Z"),
    )

    fun storeConfig(enabled: Boolean = true, maxQtyPerLine: Int = 20, maxLinesPerOrder: Int = 50) = StoreConfig(
        enabled = enabled,
        storeName = "Toko Uji",
        currency = "IDR",
        fulfilmentMethods = setOf(FulfilmentMethod.Pickup, FulfilmentMethod.Courier),
        pickupInstructions = "Ambil di meja depan.",
        courierInstructions = null,
        maxQtyPerLine = maxQtyPerLine,
        maxLinesPerOrder = maxLinesPerOrder,
    )
}
