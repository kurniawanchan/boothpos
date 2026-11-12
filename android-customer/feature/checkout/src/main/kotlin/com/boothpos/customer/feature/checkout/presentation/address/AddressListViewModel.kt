package com.boothpos.customer.feature.checkout.presentation.address

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.boothpos.customer.core.data.domain.address.AddressRepository
import com.boothpos.customer.core.data.domain.model.Address
import com.boothpos.customer.core.network.error.ApiError
import com.boothpos.customer.core.network.error.ApiErrorCodes
import com.boothpos.customer.core.network.error.ApiResult
import com.boothpos.customer.feature.checkout.domain.toInput
import dagger.hilt.android.lifecycle.HiltViewModel
import javax.inject.Inject
import kotlinx.coroutines.Job
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

sealed interface AddressListUiState {
    data object Loading : AddressListUiState

    data class LoadFailed(val error: ApiError, val retrying: Boolean = false) : AddressListUiState

    data class Content(
        val addresses: List<Address>,
        /** Hapus / jadikan utama sedang berjalan: aksi lain dinonaktifkan supaya tidak terkirim dua kali. */
        val busy: Boolean = false,
        val pendingDelete: Address? = null,
        val actionError: ApiError? = null,
    ) : AddressListUiState {
        // Alamat adalah data pribadi: tidak boleh muncul bila state ini tercetak di log/laporan crash.
        override fun toString(): String = "Content(count=${addresses.size}, busy=$busy)"
    }
}

/** Kelola alamat kirim (kontrak §7). Server yang menegakkan kepemilikan, batas jumlah, dan satu alamat utama. */
@HiltViewModel
class AddressListViewModel @Inject constructor(private val addresses: AddressRepository) : ViewModel() {

    private val _uiState = MutableStateFlow<AddressListUiState>(AddressListUiState.Loading)
    val uiState: StateFlow<AddressListUiState> = _uiState.asStateFlow()

    private var loadJob: Job? = null

    /** Dipanggil setiap kali layar tampil (termasuk kembali dari formulir): daftar selalu dari server. */
    fun refresh() {
        if (loadJob?.isActive == true) return
        when (val state = _uiState.value) {
            is AddressListUiState.LoadFailed -> _uiState.value = state.copy(retrying = true)
            is AddressListUiState.Content -> if (state.busy) return
            AddressListUiState.Loading -> Unit
        }
        loadJob = viewModelScope.launch { load() }
    }

    fun requestDelete(addressId: Long) {
        updateContent { content ->
            if (content.busy) content else content.copy(pendingDelete = content.addresses.firstOrNull { it.id == addressId })
        }
    }

    fun dismissDelete() = updateContent { it.copy(pendingDelete = null) }

    fun confirmDelete() {
        val content = _uiState.value as? AddressListUiState.Content ?: return
        val target = content.pendingDelete ?: return
        if (content.busy) return
        mutate { addresses.delete(target.id) }
    }

    fun setDefault(addressId: Long) {
        val content = _uiState.value as? AddressListUiState.Content ?: return
        val target = content.addresses.firstOrNull { it.id == addressId } ?: return
        if (content.busy || target.isDefault) return
        mutate { addresses.update(target.id, target.toInput(isDefault = true)) }
    }

    private fun mutate(call: suspend () -> ApiResult<*>) {
        updateContent { it.copy(busy = true, pendingDelete = null, actionError = null) }
        viewModelScope.launch {
            val failure = (call() as? ApiResult.Failure)?.error
            // 404: alamat sudah tidak ada (dihapus dari perangkat lain); daftar baru yang menjelaskannya.
            val gone = (failure as? ApiError.Http)?.code == ApiErrorCodes.NOT_FOUND
            if (failure != null && !gone) {
                updateContent { it.copy(busy = false, actionError = failure) }
            } else {
                load()
            }
        }
    }

    private suspend fun load() {
        when (val result = addresses.list()) {
            is ApiResult.Success -> _uiState.value = AddressListUiState.Content(result.value)
            is ApiResult.Failure -> _uiState.update { state ->
                // Daftar yang sudah tampil tidak diganti layar galat hanya karena penyegaran gagal.
                if (state is AddressListUiState.Content) {
                    state.copy(busy = false, actionError = result.error)
                } else {
                    AddressListUiState.LoadFailed(result.error)
                }
            }
        }
    }

    private inline fun updateContent(transform: (AddressListUiState.Content) -> AddressListUiState.Content) {
        _uiState.update { state -> (state as? AddressListUiState.Content)?.let(transform) ?: state }
    }
}
