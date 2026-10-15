package com.warkop.core.domain.util

import android.content.Context

/**
 * Token storage helper.
 * Security Hardening (T045):
 * - Plain SharedPreferences must be replaced with EncryptedSharedPreferences or encrypted DataStore
 *   backed by AndroidKeyStore before production release.
 * - Never log raw tokens or auth headers to console/logcat in production builds.
 */
object TokenStore {
    private const val PREFS_NAME = "token_prefs"
    private const val KEY_TOKEN = "auth_token"

    fun saveToken(context: Context, token: String) {
        val prefs = context.getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)
        prefs.edit().putString(KEY_TOKEN, token).apply()
    }

    fun getToken(context: Context): String? {
        val prefs = context.getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)
        return prefs.getString(KEY_TOKEN, null)
    }
}
