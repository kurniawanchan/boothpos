package com.boothpos.customer.core.ui.component

import androidx.annotation.StringRes
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.painterResource
import androidx.compose.ui.res.stringResource
import com.boothpos.customer.core.network.error.ApiError
import com.boothpos.customer.core.network.error.ApiErrorCodes
import com.boothpos.customer.core.network.error.isOffline
import com.boothpos.customer.core.network.error.isStoreUnavailable
import com.boothpos.customer.core.ui.R

/**
 * Pesan baku untuk galat yang sama di semua fitur. Mengembalikan null bila pesan `detail` dari
 * server (sudah dilokalkan dan aman, kontrak §3) yang harus dipakai; fitur tetap boleh menangani
 * kode khususnya sendiri (`cart_invalid`, `price_changed`, …) sebelum jatuh ke sini.
 */
@StringRes
fun apiErrorMessageRes(error: ApiError): Int? = when (error) {
    ApiError.Network -> R.string.core_ui_error_network
    ApiError.Timeout -> R.string.core_ui_error_timeout
    ApiError.Unknown -> R.string.core_ui_error_generic
    ApiError.SessionStorage -> R.string.core_ui_error_session_storage
    is ApiError.Http -> when (error.code) {
        ApiErrorCodes.TOO_MANY_REQUESTS -> R.string.core_ui_error_too_many_requests
        ApiErrorCodes.UNAUTHENTICATED -> R.string.core_ui_error_unauthenticated
        ApiErrorCodes.SERVER_ERROR, ApiErrorCodes.HTTP_ERROR, ApiErrorCodes.STORE_LOCKED -> R.string.core_ui_error_generic
        else -> if (error.detail.isNullOrBlank()) R.string.core_ui_error_generic else null
    }
}

@Composable
fun apiErrorMessage(error: ApiError): String {
    val res = apiErrorMessageRes(error)
    return if (res != null) stringResource(res) else (error as ApiError.Http).detail.orEmpty()
}

/**
 * Tampilan galat satu layar penuh untuk layar data: memilih "toko tidak tersedia", "tidak ada
 * koneksi", atau galat umum, semuanya dengan tombol coba lagi.
 */
@Composable
fun ApiErrorState(
    error: ApiError,
    onRetry: () -> Unit,
    modifier: Modifier = Modifier,
    retrying: Boolean = false,
) {
    when {
        error.isStoreUnavailable -> StoreUnavailableState(onRetry = onRetry, modifier = modifier, retrying = retrying)
        error.isOffline -> ErrorState(
            message = apiErrorMessage(error),
            onRetry = onRetry,
            modifier = modifier,
            title = stringResource(R.string.core_ui_error_network_title),
            icon = painterResource(R.drawable.core_ui_ic_cloud_off),
            retrying = retrying,
        )
        else -> ErrorState(message = apiErrorMessage(error), onRetry = onRetry, modifier = modifier, retrying = retrying)
    }
}
