package com.warkop.manager.data

import com.warkop.core.network.SecretStore

/** Pengganti [SecureStore] di tes JVM (tanpa Android Keystore). */
class InMemoryStore : SecretStore {
    val values = mutableMapOf<String, String>()
    override fun get(key: String): String? = values[key]
    override fun put(key: String, value: String?) { if (value == null) values.remove(key) else values[key] = value }
    override fun clear(vararg keys: String) { keys.forEach { values.remove(it) } }
}
