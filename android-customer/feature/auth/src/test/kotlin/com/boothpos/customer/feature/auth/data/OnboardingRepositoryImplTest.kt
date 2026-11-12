package com.boothpos.customer.feature.auth.data

import androidx.datastore.core.DataStore
import androidx.datastore.preferences.core.PreferenceDataStoreFactory
import androidx.datastore.preferences.core.Preferences
import java.io.File
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.cancel
import kotlinx.coroutines.test.runTest
import org.junit.After
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Rule
import org.junit.Test
import org.junit.rules.TemporaryFolder

class OnboardingRepositoryImplTest {

    @get:Rule
    val folder = TemporaryFolder()

    private val scope = CoroutineScope(Dispatchers.IO + SupervisorJob())

    private fun dataStore(): DataStore<Preferences> =
        PreferenceDataStoreFactory.create(scope = scope) { File(folder.root, "uji.preferences_pb") }

    @After
    fun tearDown() = scope.cancel()

    @Test
    fun `sambutan tertunda sampai ditandai sudah tampil lalu tidak tertunda lagi`() = runTest {
        val repository = OnboardingRepositoryImpl(dataStore())

        assertTrue(repository.isWelcomePending())
        repository.markWelcomeSeen()

        assertFalse(repository.isWelcomePending())
    }
}
