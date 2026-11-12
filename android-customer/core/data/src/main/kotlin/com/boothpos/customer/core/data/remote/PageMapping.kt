package com.boothpos.customer.core.data.remote

import com.boothpos.customer.core.data.domain.model.PageInfo
import com.boothpos.customer.core.network.dto.PageMetaDto

fun PageMetaDto.toDomain() = PageInfo(
    currentPage = currentPage,
    perPage = perPage,
    total = total,
    lastPage = lastPage,
)
