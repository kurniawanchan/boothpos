package com.boothpos.customer.feature.checkout.data

import com.boothpos.customer.core.data.domain.session.UserScopedDataCleaner
import com.boothpos.customer.feature.checkout.domain.CheckoutRepository
import com.boothpos.customer.feature.checkout.domain.IdempotencyKeyGenerator
import com.boothpos.customer.feature.checkout.domain.PendingOrderIntentStore
import com.boothpos.customer.feature.checkout.domain.UuidIdempotencyKeyGenerator
import dagger.Binds
import dagger.Module
import dagger.Provides
import dagger.hilt.InstallIn
import dagger.hilt.components.SingletonComponent
import dagger.multibindings.IntoSet
import javax.inject.Singleton
import retrofit2.Retrofit

@Module
@InstallIn(SingletonComponent::class)
abstract class CheckoutBindingsModule {
    @Binds
    abstract fun bindCheckoutRepository(impl: CheckoutRepositoryImpl): CheckoutRepository

    @Binds
    abstract fun bindIdempotencyKeyGenerator(impl: UuidIdempotencyKeyGenerator): IdempotencyKeyGenerator

    @Binds
    abstract fun bindPendingOrderIntentStore(impl: DataStorePendingOrderIntentStore): PendingOrderIntentStore

    @Binds
    @IntoSet
    abstract fun bindPendingOrderIntentCleaner(impl: PendingOrderIntentCleaner): UserScopedDataCleaner
}

@Module
@InstallIn(SingletonComponent::class)
object CheckoutDataModule {
    @Provides
    @Singleton
    fun provideCheckoutApi(retrofit: Retrofit): CheckoutApi = retrofit.create(CheckoutApi::class.java)
}
