package com.warkop.core.network.repo

import com.warkop.core.domain.util.TokenStore
import com.warkop.core.network.ApiService
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext

/**
 * Placeholder authentication repository.
 * Uses the stub ApiService and TokenStore.
 */
object AuthRepository {
    suspend fun login(username: String, password: String): Boolean = withContext(Dispatchers.IO) {
        // Simulate network call – always succeed and store a dummy token.
        val dummyToken = "dummy-token-$username"
        // In a real implementation you would call ApiService.
        TokenStore.saveToken(AppContext.provider, dummyToken)
        true
    }
}

// Simple provider for Context used in repository (replace with actual Android context in app).
object AppContext {
    lateinit var provider: android.content.Context
}
