package com.warkop.core.network.dto

import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable

/**
 * 077 — GET /pos-setup/branding (tanpa token): mode data dan identitas toko untuk layar PIN/penyiapan. Hanya tiga nilai;
 * server menolak menambah isi tanpa meninjau ulang pengecualian Konstitusi IV. Semua opsional: server lama tanpa rute ini
 * (404) ditangani pemanggil, dan nilai yang hilang jatuh ke bawaan (LIVE, tanpa logo).
 */
@Serializable data class BrandingDto(
    @SerialName("system_mode") val systemMode: String? = null,
    @SerialName("store_name") val storeName: String? = null,
    @SerialName("store_logo_url") val storeLogoUrl: String? = null,
)
