package com.boothpos.customer.core.network.di

import com.boothpos.customer.core.network.HttpClientFactory
import com.boothpos.customer.core.network.NetworkConfig
import com.boothpos.customer.core.network.NetworkJson
import com.boothpos.customer.core.network.RetrofitFactory
import com.boothpos.customer.core.network.TokenProvider
import com.boothpos.customer.core.network.UnauthorizedEvents
import dagger.Lazy
import dagger.Module
import dagger.Provides
import dagger.hilt.InstallIn
import dagger.hilt.components.SingletonComponent
import javax.inject.Singleton
import kotlinx.serialization.json.Json
import okhttp3.OkHttpClient
import retrofit2.Retrofit

/**
 * [NetworkConfig] disediakan oleh :app (BuildConfig) dan [TokenProvider] oleh :core:data;
 * modul ini hanya merakit klien HTTP.
 */
@Module
@InstallIn(SingletonComponent::class)
object NetworkModule {

    @Provides
    @Singleton
    fun provideJson(): Json = NetworkJson.create()

    @Provides
    @Singleton
    fun provideOkHttpClient(
        config: NetworkConfig,
        tokenProvider: TokenProvider,
        unauthorizedEvents: UnauthorizedEvents,
    ): OkHttpClient = HttpClientFactory.create(config, tokenProvider, unauthorizedEvents)

    @Provides
    @Singleton
    fun provideRetrofit(config: NetworkConfig, json: Json, client: Lazy<OkHttpClient>): Retrofit =
        RetrofitFactory.create(config, json) { request -> client.get().newCall(request) }
}
