package com.boothpos.customer.core.data.di

import android.content.Context
import androidx.datastore.core.DataStore
import androidx.datastore.core.handlers.ReplaceFileCorruptionHandler
import androidx.datastore.preferences.core.PreferenceDataStoreFactory
import androidx.datastore.preferences.core.Preferences
import androidx.datastore.preferences.core.emptyPreferences
import androidx.datastore.preferences.preferencesDataStoreFile
import androidx.room.Room
import com.boothpos.customer.core.data.address.AddressRepositoryImpl
import com.boothpos.customer.core.data.cart.CartRepositoryImpl
import com.boothpos.customer.core.data.cart.CartSignOutCleaner
import com.boothpos.customer.core.data.connectivity.AndroidConnectivityObserver
import com.boothpos.customer.core.data.domain.address.AddressRepository
import com.boothpos.customer.core.data.domain.cart.CartRepository
import com.boothpos.customer.core.data.domain.session.SessionRepository
import com.boothpos.customer.core.data.domain.session.UserScopedDataCleaner
import com.boothpos.customer.core.data.domain.store.StoreConfigRepository
import com.boothpos.customer.core.data.domain.util.ConnectivityObserver
import com.boothpos.customer.core.data.domain.util.DispatcherProvider
import com.boothpos.customer.core.data.local.AppDatabase
import com.boothpos.customer.core.data.local.CartDao
import com.boothpos.customer.core.data.local.CatalogCacheDao
import com.boothpos.customer.core.data.remote.AddressApi
import com.boothpos.customer.core.data.remote.SessionApi
import com.boothpos.customer.core.data.remote.StorefrontConfigApi
import com.boothpos.customer.core.data.security.AesGcmTokenStore
import com.boothpos.customer.core.data.security.AndroidKeystoreKeyProvider
import com.boothpos.customer.core.data.security.TokenStore
import com.boothpos.customer.core.data.session.ApiTokenRevoker
import com.boothpos.customer.core.data.session.SessionRepositoryImpl
import com.boothpos.customer.core.data.session.TokenRevoker
import com.boothpos.customer.core.data.store.StoreConfigRepositoryImpl
import com.boothpos.customer.core.network.TokenProvider
import dagger.Binds
import dagger.Module
import dagger.Provides
import dagger.hilt.InstallIn
import dagger.hilt.android.qualifiers.ApplicationContext
import dagger.hilt.components.SingletonComponent
import dagger.multibindings.IntoSet
import java.io.File
import java.time.Clock
import javax.inject.Inject
import javax.inject.Qualifier
import javax.inject.Singleton
import kotlinx.coroutines.CoroutineDispatcher
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.serialization.json.Json
import retrofit2.Retrofit

/** Scope sepanjang umur proses, untuk pekerjaan yang tidak boleh batal bersama layar (sesi). */
@Qualifier
@Retention(AnnotationRetention.BINARY)
annotation class ApplicationScope

class DefaultDispatcherProvider @Inject constructor() : DispatcherProvider {
    override val main: CoroutineDispatcher = Dispatchers.Main
    override val io: CoroutineDispatcher = Dispatchers.IO
    override val default: CoroutineDispatcher = Dispatchers.Default
}

@Module
@InstallIn(SingletonComponent::class)
abstract class DataBindingsModule {
    @Binds
    abstract fun bindDispatchers(impl: DefaultDispatcherProvider): DispatcherProvider

    @Binds
    abstract fun bindSessionRepository(impl: SessionRepositoryImpl): SessionRepository

    @Binds
    abstract fun bindTokenRevoker(impl: ApiTokenRevoker): TokenRevoker

    @Binds
    abstract fun bindCartRepository(impl: CartRepositoryImpl): CartRepository

    @Binds
    abstract fun bindAddressRepository(impl: AddressRepositoryImpl): AddressRepository

    @Binds
    abstract fun bindStoreConfigRepository(impl: StoreConfigRepositoryImpl): StoreConfigRepository

    @Binds
    abstract fun bindConnectivityObserver(impl: AndroidConnectivityObserver): ConnectivityObserver

    // Modul fitur yang menyimpan data milik pengguna menambahkan pembersihnya sendiri dengan @IntoSet.
    @Binds
    @IntoSet
    abstract fun bindCartCleaner(impl: CartSignOutCleaner): UserScopedDataCleaner
}

@Module
@InstallIn(SingletonComponent::class)
object DataModule {

    @Provides
    @Singleton
    fun provideClock(): Clock = Clock.systemUTC()

    @Provides
    @Singleton
    @ApplicationScope
    fun provideApplicationScope(dispatchers: DispatcherProvider): CoroutineScope =
        CoroutineScope(SupervisorJob() + dispatchers.default)

    @Provides
    @Singleton
    fun provideDatabase(@ApplicationContext context: Context): AppDatabase =
        Room.databaseBuilder(context, AppDatabase::class.java, AppDatabase.NAME).build()

    @Provides
    fun provideCartDao(database: AppDatabase): CartDao = database.cartDao()

    @Provides
    fun provideCatalogCacheDao(database: AppDatabase): CatalogCacheDao = database.catalogCacheDao()

    @Provides
    @Singleton
    fun providePreferencesDataStore(
        @ApplicationContext context: Context,
        dispatchers: DispatcherProvider,
    ): DataStore<Preferences> = PreferenceDataStoreFactory.create(
        corruptionHandler = ReplaceFileCorruptionHandler { emptyPreferences() },
        scope = CoroutineScope(SupervisorJob() + dispatchers.io),
        produceFile = { context.preferencesDataStoreFile("customer_prefs") },
    )

    @Provides
    @Singleton
    fun provideTokenStore(
        @ApplicationContext context: Context,
        json: Json,
        dispatchers: DispatcherProvider,
    ): TokenStore = AesGcmTokenStore(
        // noBackupFilesDir: tidak pernah ikut cadangan, selain aturan ekstraksi di manifest.
        file = File(context.noBackupFilesDir, "session.bin"),
        keyProvider = AndroidKeystoreKeyProvider(),
        json = json,
        ioDispatcher = dispatchers.io,
    )

    @Provides
    @Singleton
    fun provideTokenProvider(tokenStore: TokenStore): TokenProvider = TokenProvider { tokenStore.peekToken() }

    @Provides
    @Singleton
    fun provideStorefrontConfigApi(retrofit: Retrofit): StorefrontConfigApi = retrofit.create(StorefrontConfigApi::class.java)

    @Provides
    @Singleton
    fun provideAddressApi(retrofit: Retrofit): AddressApi = retrofit.create(AddressApi::class.java)

    @Provides
    @Singleton
    fun provideSessionApi(retrofit: Retrofit): SessionApi = retrofit.create(SessionApi::class.java)
}
