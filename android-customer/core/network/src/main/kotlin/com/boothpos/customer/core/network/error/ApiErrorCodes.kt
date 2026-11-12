package com.boothpos.customer.core.network.error

/** Kode mesin stabil dari kontrak §3. UI beralih pada nilai-nilai ini. */
object ApiErrorCodes {
    const val UNAUTHENTICATED = "unauthenticated"
    const val INVALID_CREDENTIALS = "invalid_credentials"
    const val FORBIDDEN = "forbidden"
    const val NOT_FOUND = "not_found"
    const val IDEMPOTENCY_KEY_REUSED = "idempotency_key_reused"
    const val CART_INVALID = "cart_invalid"
    const val PRICE_CHANGED = "price_changed"
    const val ADDRESS_LIMIT_REACHED = "address_limit_reached"
    const val VALIDATION_FAILED = "validation_failed"
    const val REGISTRATION_FAILED = "registration_failed"
    const val IDEMPOTENCY_KEY_REQUIRED = "idempotency_key_required"
    const val TOO_MANY_REQUESTS = "too_many_requests"
    const val CUSTOMER_COMMERCE_DISABLED = "customer_commerce_disabled"
    const val SERVER_ERROR = "server_error"

    /**
     * Kode sisi klien (bukan dari server): gerbang lisensi menjawab 423 dengan body staf
     * `{message, activated:false}`, bukan problem+json.
     */
    const val STORE_LOCKED = "store_locked"

    /** Kode sisi klien untuk status HTTP lain yang datang tanpa body problem+json. */
    const val HTTP_ERROR = "http_error"
}
