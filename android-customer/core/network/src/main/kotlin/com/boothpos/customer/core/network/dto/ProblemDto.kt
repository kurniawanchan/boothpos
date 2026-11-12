package com.boothpos.customer.core.network.dto

import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable
import kotlinx.serialization.json.JsonElement

/** Amplop galat `application/problem+json` (kontrak §3). Semua field toleran terhadap ketiadaan. */
@Serializable
data class ProblemDto(
    val type: String? = null,
    val title: String? = null,
    val status: Int? = null,
    val detail: String? = null,
    val code: String? = null,
    val errors: JsonElement? = null,
    @SerialName("request_id") val requestId: String? = null,
)
