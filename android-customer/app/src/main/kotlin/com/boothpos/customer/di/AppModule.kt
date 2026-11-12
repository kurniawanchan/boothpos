package com.boothpos.customer.di

import com.boothpos.customer.BuildConfig
import com.boothpos.customer.core.network.NetworkConfig
import dagger.Module
import dagger.Provides
import dagger.hilt.InstallIn
import dagger.hilt.components.SingletonComponent
import javax.inject.Singleton

@Module
@InstallIn(SingletonComponent::class)
object AppModule {

    /**
     * Alamat API berasal dari properti Gradle `boothpos.apiBaseUrl` (lihat app/build.gradle.kts).
     * Pemeriksaan HTTPS diulang saat runtime sebagai pengaman kedua di samping tugas Gradle
     * `checkReleaseApiBaseUrl` dan network security config.
     */
    @Provides
    @Singleton
    fun provideNetworkConfig(): NetworkConfig {
        val baseUrl = BuildConfig.API_BASE_URL
        check(BuildConfig.DEBUG || baseUrl.startsWith("https://")) { "Build rilis wajib memakai HTTPS" }
        return NetworkConfig(baseUrl)
    }
}
