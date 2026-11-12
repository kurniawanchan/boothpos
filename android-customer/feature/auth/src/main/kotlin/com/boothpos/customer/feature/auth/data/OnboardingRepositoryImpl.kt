package com.boothpos.customer.feature.auth.data

import androidx.datastore.core.DataStore
import androidx.datastore.preferences.core.Preferences
import androidx.datastore.preferences.core.booleanPreferencesKey
import androidx.datastore.preferences.core.edit
import com.boothpos.customer.feature.auth.domain.OnboardingRepository
import java.io.IOException
import javax.inject.Inject
import kotlinx.coroutines.flow.first

class OnboardingRepositoryImpl @Inject constructor(
    private val dataStore: DataStore<Preferences>,
) : OnboardingRepository {

    override suspend fun isWelcomePending(): Boolean = try {
        dataStore.data.first()[WELCOME_SEEN] != true
    } catch (_: IOException) {
        // Preferensi tak terbaca: lebih baik tidak menampilkan apa-apa daripada mengulang layar
        // selamat datang setiap kali aplikasi dibuka.
        false
    }

    override suspend fun markWelcomeSeen() {
        try {
            dataStore.edit { it[WELCOME_SEEN] = true }
        } catch (_: IOException) {
            // Gagal menyimpan hanya berarti layar selamat datang tampil sekali lagi.
        }
    }

    private companion object {
        val WELCOME_SEEN = booleanPreferencesKey("auth_welcome_seen")
    }
}
