package com.boothpos.customer.core.data.store

import com.boothpos.customer.core.data.domain.model.StoreConfig
import com.boothpos.customer.core.data.domain.store.StoreConfigRepository
import com.boothpos.customer.core.data.prefs.StoreConfigCache
import com.boothpos.customer.core.data.remote.StoreConfigDto
import com.boothpos.customer.core.data.remote.StorefrontConfigApi
import com.boothpos.customer.core.data.remote.toDomain
import com.boothpos.customer.core.network.error.ApiResult
import com.boothpos.customer.core.network.error.SafeApiCall
import com.boothpos.customer.core.network.error.map
import javax.inject.Inject
import javax.inject.Singleton
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.map

@Singleton
class StoreConfigRepositoryImpl @Inject constructor(
    private val api: StorefrontConfigApi,
    private val cache: StoreConfigCache,
    private val safeApiCall: SafeApiCall,
) : StoreConfigRepository {

    override val config: Flow<StoreConfig?> = cache.config.map { it?.toDomain() }

    override suspend fun refresh(): ApiResult<StoreConfig> =
        safeApiCall { api.config().data }.map { dto ->
            saveToCache(dto)
            dto.toDomain()
        }

    /**
     * Cache hanya pengaman untuk offline: DataStore yang gagal menulis (disk penuh, berkas rusak,
     * `IOException`) TIDAK BOLEH menjatuhkan aplikasi saat dibuka. Jawaban server yang sudah sukses
     * tetap dikembalikan; yang hilang hanya salinan offline-nya. Sengaja tanpa log (pesan
     * pengecualian IO bisa memuat jalur berkas). Pembatalan coroutine tetap diteruskan.
     */
    private suspend fun saveToCache(dto: StoreConfigDto) {
        try {
            cache.save(dto)
        } catch (e: CancellationException) {
            throw e
        } catch (_: Exception) {
            // Lihat KDoc: kegagalan cache tidak menggagalkan refresh.
        }
    }
}
