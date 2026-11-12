package com.boothpos.customer.core.data.prefs

import androidx.datastore.core.DataStore
import androidx.datastore.preferences.core.Preferences
import androidx.datastore.preferences.core.edit
import androidx.datastore.preferences.core.stringPreferencesKey
import com.boothpos.customer.core.data.remote.StoreConfigDto
import javax.inject.Inject
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.distinctUntilChanged
import kotlinx.coroutines.flow.map
import kotlinx.serialization.SerializationException
import kotlinx.serialization.json.Json

/**
 * Konfigurasi toko terakhir (nama toko, instruksi, batas keranjang) di DataStore. Nilainya publik
 * dan tidak sensitif; data sensitif (token, akun) TIDAK pernah disimpan di sini.
 */
class StoreConfigCache @Inject constructor(
    private val dataStore: DataStore<Preferences>,
    private val json: Json,
) {
    val config: Flow<StoreConfigDto?> = dataStore.data
        .map { preferences -> preferences[KEY]?.let(::decode) }
        .distinctUntilChanged()

    suspend fun save(config: StoreConfigDto) {
        dataStore.edit { it[KEY] = json.encodeToString(StoreConfigDto.serializer(), config) }
    }

    private fun decode(raw: String): StoreConfigDto? = try {
        json.decodeFromString(StoreConfigDto.serializer(), raw)
    } catch (_: SerializationException) {
        null
    } catch (_: IllegalArgumentException) {
        null
    }

    private companion object {
        val KEY = stringPreferencesKey("store_config")
    }
}
