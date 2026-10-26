package com.warkop.pos.data

/** 077 — [SecretStore] di memori untuk uji; sama kontraknya dengan TokenVault tanpa Keystore/Context. */
class InMemoryStore : SecretStore {
    val map = mutableMapOf<String, String>()
    override fun get(key: String): String? = map[key]
    override fun put(key: String, value: String?) { if (value == null) map.remove(key) else map[key] = value }
    override fun clear(vararg keys: String) { keys.forEach { map.remove(it) } }
}
