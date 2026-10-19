package com.warkop.core.network.dto

import kotlinx.serialization.Serializable

/**
 * 054 / US3 — `GET /devices/server-address`. Hanya alamat yang DIPILIH admin yang dikirim; alamat satunya dan
 * kredensial tidak pernah sampai ke tablet (FR-011). `url` null = pakai alamat pairing yang sudah tersimpan.
 */
@Serializable data class ServerAddressDto(val selected: String = "local", val url: String? = null)
