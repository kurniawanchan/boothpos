package com.warkop.manager.domain

import androidx.lifecycle.SavedStateHandle
import com.warkop.core.network.ApiClient
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.serialization.KSerializer

/**
 * 094 / FR-029, T075 — isian formulir yang bertahan saat rotasi DAN saat proses dibuat ulang sistem: nilai disimpan
 * sebagai JSON di [SavedStateHandle] tiap kali berubah. Kata sandi dan file foto TIDAK boleh masuk ke sini.
 */
class SavedForm<T>(private val handle: SavedStateHandle, private val key: String, private val serializer: KSerializer<T>, initial: () -> T) {
    private val _value = MutableStateFlow(
        handle.get<String>(key)?.let { runCatching { ApiClient.json.decodeFromString(serializer, it) }.getOrNull() } ?: initial(),
    )
    val value: StateFlow<T> = _value.asStateFlow()
    val current: T get() = _value.value

    /** True bila isian dipulihkan dari keadaan tersimpan (jangan timpa dengan data server yang baru dimuat). */
    val restored: Boolean = handle.contains(key)

    fun update(f: (T) -> T) = set(f(_value.value))

    fun set(v: T) {
        _value.value = v
        handle[key] = ApiClient.json.encodeToString(serializer, v)
    }
}
