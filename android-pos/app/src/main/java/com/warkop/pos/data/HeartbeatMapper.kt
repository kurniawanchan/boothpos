package com.warkop.pos.data

import com.warkop.core.network.dto.HeartbeatResponseDto
import com.warkop.pos.state.HeartbeatResult

/** DTO heartbeat → model. Rilis kosong/blank dianggap belum ada rilis (null), bukan teks kosong di layar. */
object HeartbeatMapper {
    fun result(dto: HeartbeatResponseDto): HeartbeatResult = HeartbeatResult(
        serverTimeMs = dto.serverTimeMs,
        skewSeconds = dto.skewSeconds,
        menuRelease = dto.menuRelease?.takeIf { it.isNotBlank() },
    )
}
