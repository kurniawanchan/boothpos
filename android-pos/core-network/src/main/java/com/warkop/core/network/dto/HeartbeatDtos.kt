package com.warkop.core.network.dto

import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable

/**
 * 048 / US6 — detak jantung perangkat (`POST /devices/heartbeat`). Header `X-Device-Id` dipasang oleh klien HTTP.
 * `clock_ms` = jam perangkat (epoch ms); `queue_depth` = jumlah transaksi yang belum terkirim (0: tidak ada antrean lokal).
 */
@Serializable data class HeartbeatRequestDto(
    @SerialName("app_version") val appVersion: String,
    @SerialName("clock_ms") val clockMs: Long,
    @SerialName("queue_depth") val queueDepth: Int,
)

/**
 * Respons heartbeat. `skew_seconds` positif = jam perangkat lebih cepat dari server. `menu_release` null bila belum ada rilis.
 */
@Serializable data class HeartbeatResponseDto(
    @SerialName("server_time_ms") val serverTimeMs: Long = 0,
    @SerialName("skew_seconds") val skewSeconds: Int = 0,
    @SerialName("menu_release") val menuRelease: String? = null,
)
