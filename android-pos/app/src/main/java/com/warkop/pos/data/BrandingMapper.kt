package com.warkop.pos.data

import com.warkop.core.network.dto.BrandingDto
import com.warkop.core.network.dto.FeaturesDto
import com.warkop.pos.model.Branding
import com.warkop.pos.model.SystemMode

/** 077 — satu pemetaan identitas toko, dipakai sebelum login (branding) dan sesudahnya (features). */
object BrandingMapper {
    fun fromBranding(d: BrandingDto, serverBase: String?): Branding =
        build(d.systemMode, d.storeName, d.storeLogoUrl, null, serverBase)

    fun fromFeatures(d: FeaturesDto, serverBase: String?): Branding =
        build(d.systemMode, d.storeName, d.storeLogoUrl, d.outletCount, serverBase)

    // Logo selalu lewat ServerUrl.resolve: host APP_URL di server bisa `localhost` yang tak terjangkau tablet (059/075).
    private fun build(mode: String?, name: String?, logo: String?, outlets: Int?, serverBase: String?) = Branding(
        mode = SystemMode.parse(mode), storeName = name?.trim().orEmpty(), logoUrl = ServerUrl.resolve(serverBase, logo), outletCount = outlets,
    )
}
