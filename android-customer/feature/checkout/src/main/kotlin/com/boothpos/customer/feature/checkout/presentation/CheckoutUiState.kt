package com.boothpos.customer.feature.checkout.presentation

import com.boothpos.customer.core.data.domain.model.Address
import com.boothpos.customer.core.data.domain.model.FulfilmentMethod
import com.boothpos.customer.core.data.domain.validation.FieldIssue
import com.boothpos.customer.core.network.error.ApiError
import com.boothpos.customer.feature.checkout.domain.CartIssueReason
import com.boothpos.customer.feature.checkout.domain.Quote
import java.math.BigDecimal

enum class CheckoutStep { Method, Details, Review }

/** Galat satu kolom: dari pemeriksaan di perangkat, atau pesan server (422, sudah dilokalkan). */
sealed interface CheckoutFieldError {
    data class Local(val issue: FieldIssue) : CheckoutFieldError

    data class Server(val message: String) : CheckoutFieldError
}

sealed interface AddressesState {
    data object NotLoaded : AddressesState

    data object Loading : AddressesState

    data class Failed(val error: ApiError) : AddressesState

    data class Loaded(val addresses: List<Address>) : AddressesState
}

/** Total yang dilihat pelanggan sebelumnya vs total baru dari server (409 `price_changed`). */
data class PriceChange(val previousTotal: BigDecimal, val newTotal: BigDecimal)

enum class ReviewNotice {
    /** Harga server berbeda dari cuplikan harga di keranjang. */
    PricesUpdated,

    /** Kunci pesanan ditolak (`idempotency_key_reused`): rincian dihitung ulang, konfirmasi lagi. */
    ConfirmAgain,
}

/** Baris keranjang yang ditolak server, dengan nama dari keranjang lokal untuk ditampilkan. */
data class CartIssueLine(
    val variantId: Long,
    val name: String?,
    val reason: CartIssueReason,
    val maxQuantity: Int,
)

/** Keadaan langkah ringkasan. Hanya [Ready] yang boleh menampilkan tombol "Buat pesanan". */
sealed interface ReviewState {
    data object Idle : ReviewState

    data object LoadingQuote : ReviewState

    data class QuoteFailed(val error: ApiError) : ReviewState

    data class CartInvalid(val lines: List<CartIssueLine>) : ReviewState

    data class Ready(
        val quote: Quote,
        /** Permintaan buat pesanan sedang berjalan: tombol nonaktif, tidak ada pengiriman kedua. */
        val placing: Boolean = false,
        val priceChange: PriceChange? = null,
        val notice: ReviewNotice? = null,
        /** Penolakan PASTI dari server (pesanan tidak dibuat) yang bukan kode khusus checkout. */
        val submitError: ApiError? = null,
    ) : ReviewState

    /**
     * Pesanan sudah dikirim tetapi jawabannya tidak sampai (timeout, koneksi putus, 5xx): server
     * mungkin sudah mencatatnya. Satu-satunya aksi adalah mengirim ulang dengan kunci YANG SAMA.
     * [quote] null setelah proses dipulihkan sistem.
     */
    data class StatusUnknown(val quote: Quote?, val checking: Boolean = false) : ReviewState
}

sealed interface CheckoutUiState {
    /** Sesi, keranjang, atau konfigurasi toko belum selesai dibaca. */
    data object Loading : CheckoutUiState

    /** Konfigurasi toko (metode + petunjuk) tidak dapat diambil. */
    data class LoadFailed(val error: ApiError, val retrying: Boolean = false) : CheckoutUiState

    data object SignInRequired : CheckoutUiState

    data object EmptyCart : CheckoutUiState

    /** Pesanan tercatat di server; keranjang sudah dikosongkan. */
    data class Placed(val orderNumber: String) : CheckoutUiState

    data class Form(
        val step: CheckoutStep,
        val methods: List<FulfilmentMethod>,
        val method: FulfilmentMethod?,
        val methodMissing: Boolean = false,
        /** Teks petunjuk dari server (`/storefront/config`), ditampilkan apa adanya. */
        val pickupInstructions: String?,
        val courierInstructions: String?,
        val contactName: String,
        val contactPhone: String,
        val notes: String,
        val contactNameError: CheckoutFieldError? = null,
        val contactPhoneError: CheckoutFieldError? = null,
        val notesError: CheckoutFieldError? = null,
        val addresses: AddressesState = AddressesState.NotLoaded,
        val selectedAddressId: Long? = null,
        val addressMissing: Boolean = false,
        val review: ReviewState = ReviewState.Idle,
    ) : CheckoutUiState {
        val needsAddress: Boolean get() = method == FulfilmentMethod.Courier

        val selectedAddress: Address?
            get() = (addresses as? AddressesState.Loaded)?.addresses?.firstOrNull { it.id == selectedAddressId }

        val instructions: String?
            get() = when (method) {
                FulfilmentMethod.Pickup -> pickupInstructions
                FulfilmentMethod.Courier -> courierInstructions
                null -> null
            }?.takeIf { it.isNotBlank() }

        /** Pesanan sedang dikirim: tidak boleh ada navigasi mundur yang membatalkan pemantauan hasilnya. */
        val submitting: Boolean
            get() = (review as? ReviewState.Ready)?.placing == true ||
                (review as? ReviewState.StatusUnknown)?.checking == true

        val statusUnknown: Boolean get() = review is ReviewState.StatusUnknown

        // Nama, telepon, catatan, dan alamat tidak boleh muncul bila state ini tercetak di log/laporan crash.
        override fun toString(): String = "Form(step=$step, method=$method, review=${review::class.simpleName})"
    }
}

sealed interface CheckoutEvent {
    /** Checkout dibuka tanpa sesi: buka alur masuk; setelah berhasil, layar ini dilanjutkan. */
    data object RequireSignIn : CheckoutEvent

    data class OrderPlaced(val orderNumber: String) : CheckoutEvent

    /** Keranjang harus diperbaiki (`cart_invalid`): kembali ke Keranjang. */
    data object BackToCart : CheckoutEvent
}
