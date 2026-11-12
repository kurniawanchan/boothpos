package com.boothpos.customer.core.data.address

import com.boothpos.customer.core.data.domain.address.AddressRepository
import com.boothpos.customer.core.data.domain.model.Address
import com.boothpos.customer.core.data.domain.model.AddressInput
import com.boothpos.customer.core.data.remote.AddressApi
import com.boothpos.customer.core.data.remote.AddressDto
import com.boothpos.customer.core.data.remote.toDomain
import com.boothpos.customer.core.data.remote.toRequest
import com.boothpos.customer.core.network.error.ApiResult
import com.boothpos.customer.core.network.error.SafeApiCall
import com.boothpos.customer.core.network.error.map
import javax.inject.Inject
import javax.inject.Singleton

@Singleton
class AddressRepositoryImpl @Inject constructor(
    private val api: AddressApi,
    private val safeApiCall: SafeApiCall,
) : AddressRepository {

    override suspend fun list(): ApiResult<List<Address>> =
        safeApiCall { api.list() }.map { it.data.map(AddressDto::toDomain) }

    override suspend fun create(input: AddressInput): ApiResult<Address> =
        safeApiCall { api.create(input.toRequest()) }.map { it.data.toDomain() }

    override suspend fun update(id: Long, input: AddressInput): ApiResult<Address> =
        safeApiCall { api.update(id, input.toRequest()) }.map { it.data.toDomain() }

    override suspend fun delete(id: Long): ApiResult<Unit> = safeApiCall.unit { api.delete(id) }
}
