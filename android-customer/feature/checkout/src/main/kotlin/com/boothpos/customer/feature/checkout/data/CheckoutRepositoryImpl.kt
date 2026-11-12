package com.boothpos.customer.feature.checkout.data

import com.boothpos.customer.core.data.domain.model.FulfilmentMethod
import com.boothpos.customer.core.data.domain.money.Money
import com.boothpos.customer.core.network.error.ApiError
import com.boothpos.customer.core.network.error.ApiErrorCodes
import com.boothpos.customer.core.network.error.ApiResult
import com.boothpos.customer.core.network.error.SafeApiCall
import com.boothpos.customer.feature.checkout.domain.CartIssue
import com.boothpos.customer.feature.checkout.domain.CartIssueReason
import com.boothpos.customer.feature.checkout.domain.CheckoutRepository
import com.boothpos.customer.feature.checkout.domain.CheckoutRequest
import com.boothpos.customer.feature.checkout.domain.PlaceOrderOutcome
import com.boothpos.customer.feature.checkout.domain.PlacedOrder
import com.boothpos.customer.feature.checkout.domain.Quote
import com.boothpos.customer.feature.checkout.domain.QuoteLine
import com.boothpos.customer.feature.checkout.domain.QuoteOutcome
import java.math.BigDecimal
import javax.inject.Inject
import javax.inject.Singleton
import kotlinx.serialization.SerializationException
import kotlinx.serialization.builtins.ListSerializer
import kotlinx.serialization.json.Json

/**
 * Checkout terhadap API pelanggan. Tidak ada pengulangan otomatis di sini maupun di OkHttp
 * (`retryOnConnectionFailure(false)`): satu panggilan = paling banyak satu permintaan.
 */
@Singleton
class CheckoutRepositoryImpl @Inject constructor(
    private val api: CheckoutApi,
    private val safeApiCall: SafeApiCall,
    private val json: Json,
) : CheckoutRepository {

    override suspend fun quote(request: CheckoutRequest): QuoteOutcome {
        // Pemetaan di dalam safeApiCall: harga yang bukan desimal sah menjadi galat, bukan angka karangan.
        val result = safeApiCall { api.quote(request.toDto(expectedTotal = null)).data.toDomain(request.method) }
        return when (result) {
            is ApiResult.Success -> QuoteOutcome.Success(result.value)
            is ApiResult.Failure -> when (result.error.code()) {
                ApiErrorCodes.CART_INVALID -> QuoteOutcome.CartInvalid(cartIssues(result.error))
                else -> QuoteOutcome.Failure(result.error)
            }
        }
    }

    override suspend fun placeOrder(
        request: CheckoutRequest,
        expectedTotal: BigDecimal,
        idempotencyKey: String,
    ): PlaceOrderOutcome {
        val result = safeApiCall {
            PlacedOrder(api.create(idempotencyKey, request.toDto(expectedTotal)).data.orderNumber)
        }
        return when (result) {
            is ApiResult.Success -> PlaceOrderOutcome.Placed(result.value)
            is ApiResult.Failure -> when (result.error.code()) {
                ApiErrorCodes.PRICE_CHANGED -> PlaceOrderOutcome.PriceChanged(changedQuote(result.error, request.method))
                ApiErrorCodes.CART_INVALID -> PlaceOrderOutcome.CartInvalid(cartIssues(result.error))
                ApiErrorCodes.IDEMPOTENCY_KEY_REUSED -> PlaceOrderOutcome.IdempotencyKeyReused
                else -> PlaceOrderOutcome.Failure(result.error)
            }
        }
    }

    private fun ApiError.code(): String? = (this as? ApiError.Http)?.code

    /** `errors.items`; entri yang tak terbaca diabaikan (jawaban server diperlakukan tak tepercaya). */
    private fun cartIssues(error: ApiError): List<CartIssue> {
        val items = (error as? ApiError.Http)?.errors?.get(ERRORS_ITEMS) ?: return emptyList()
        val parsed = try {
            json.decodeFromJsonElement(ListSerializer(CartIssueDto.serializer()), items)
        } catch (_: SerializationException) {
            return emptyList()
        } catch (_: IllegalArgumentException) {
            return emptyList()
        }
        return parsed.map { dto ->
            val max = dto.maxQty.coerceAtLeast(0)
            CartIssue(
                variantId = dto.variantId,
                reason = when (dto.reason) {
                    REASON_UNAVAILABLE -> CartIssueReason.Unavailable
                    REASON_QTY_LIMIT -> CartIssueReason.QuantityLimit
                    REASON_INSUFFICIENT_STOCK -> CartIssueReason.InsufficientStock
                    // Alasan baru dari server yang belum dikenal: perlakukan menurut batasnya.
                    else -> if (max < 1) CartIssueReason.Unavailable else CartIssueReason.InsufficientStock
                },
                maxQuantity = if (dto.reason == REASON_UNAVAILABLE) 0 else max,
            )
        }
    }

    /** `errors.quote` pada `price_changed`; null bila tidak ada atau tidak terbaca (pemanggil meminta quote baru). */
    private fun changedQuote(error: ApiError, method: FulfilmentMethod): Quote? {
        val quote = (error as? ApiError.Http)?.errors?.get(ERRORS_QUOTE) ?: return null
        return try {
            json.decodeFromJsonElement(QuoteDto.serializer(), quote).toDomain(method)
        } catch (_: SerializationException) {
            null
        } catch (_: IllegalArgumentException) {
            null
        }
    }

    private companion object {
        const val ERRORS_ITEMS = "items"
        const val ERRORS_QUOTE = "quote"
        const val REASON_UNAVAILABLE = "unavailable"
        const val REASON_INSUFFICIENT_STOCK = "insufficient_stock"
        const val REASON_QTY_LIMIT = "qty_limit"
    }
}

internal fun CheckoutRequest.toDto(expectedTotal: BigDecimal?) = CheckoutRequestDto(
    fulfilmentMethod = method.wireValue,
    addressId = addressId,
    contact = CheckoutContactDto(name = contactName.trim(), phone = contactPhone.trim()),
    items = items.map { CheckoutItemDto(variantId = it.variantId, qty = it.quantity) },
    notes = notes?.trim()?.takeIf { it.isNotEmpty() },
    expectedTotal = expectedTotal?.let(Money::toWire),
)

private fun money(raw: String): BigDecimal =
    Money.parse(raw) ?: throw SerializationException("Nilai uang dari server bukan desimal yang sah")

internal fun QuoteDto.toDomain(requestedMethod: FulfilmentMethod) = Quote(
    method = FulfilmentMethod.fromWire(fulfilmentMethod) ?: requestedMethod,
    lines = lines.map { line ->
        QuoteLine(
            variantId = line.variantId,
            productSlug = line.productSlug,
            name = line.name,
            imageUrl = line.imageUrl?.takeIf { it.isNotBlank() },
            unitPrice = money(line.unitPrice),
            quantity = line.qty,
            lineTotal = money(line.lineTotal),
            maxQuantity = line.maxQty.coerceAtLeast(0),
        )
    },
    subtotal = money(subtotal),
    shippingCost = money(shippingCost),
    shippingCostPending = shippingCostPending,
    total = money(total),
)
