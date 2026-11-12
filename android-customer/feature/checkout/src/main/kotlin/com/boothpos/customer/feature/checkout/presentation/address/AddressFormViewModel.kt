package com.boothpos.customer.feature.checkout.presentation.address

import androidx.lifecycle.SavedStateHandle
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.boothpos.customer.core.data.domain.address.AddressRepository
import com.boothpos.customer.core.data.domain.session.SessionRepository
import com.boothpos.customer.core.data.domain.session.SessionState
import com.boothpos.customer.core.data.domain.validation.FieldIssue
import com.boothpos.customer.core.network.error.ApiError
import com.boothpos.customer.core.network.error.ApiErrorCodes
import com.boothpos.customer.core.network.error.ApiResult
import com.boothpos.customer.core.network.error.isUnauthenticated
import com.boothpos.customer.feature.checkout.domain.AddressDraft
import com.boothpos.customer.feature.checkout.domain.AddressField
import com.boothpos.customer.feature.checkout.domain.AddressRules
import com.boothpos.customer.feature.checkout.presentation.AddressFormRoute
import dagger.hilt.android.lifecycle.HiltViewModel
import javax.inject.Inject
import kotlinx.coroutines.channels.Channel
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.flow.receiveAsFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

/** Galat satu kolom: dari pemeriksaan di perangkat, atau pesan server (422, sudah dilokalkan). */
sealed interface AddressFieldError {
    data class Local(val issue: FieldIssue) : AddressFieldError

    data class Server(val message: String) : AddressFieldError
}

sealed interface AddressFormUiState {
    data object Loading : AddressFormUiState

    data class LoadFailed(val error: ApiError, val retrying: Boolean = false) : AddressFormUiState

    /** Alamat yang hendak diubah sudah tidak ada (404 / dihapus dari perangkat lain). */
    data object NotFound : AddressFormUiState

    data class Form(
        val isNew: Boolean,
        val draft: AddressDraft,
        val errors: Map<AddressField, AddressFieldError> = emptyMap(),
        /** Alamat ini sudah alamat utama: gantinya dengan menjadikan alamat lain utama, bukan mencabutnya. */
        val defaultLocked: Boolean = false,
        val submitting: Boolean = false,
        /** Galat tingkat formulir (batas jumlah alamat, jaringan, server). */
        val formError: ApiError? = null,
    ) : AddressFormUiState {
        // Alamat adalah data pribadi: tidak boleh muncul bila state ini tercetak di log/laporan crash.
        override fun toString(): String = "Form(isNew=$isNew, submitting=$submitting, formError=$formError)"
    }
}

sealed interface AddressFormEvent {
    /** Alamat tersimpan; tutup formulir. Layar asal memuat ulang daftarnya sendiri. */
    data object Saved : AddressFormEvent
}

/** Tambah/ubah satu alamat (kontrak §7) dengan galat langsung di kolomnya. */
@HiltViewModel
class AddressFormViewModel @Inject constructor(
    private val savedState: SavedStateHandle,
    private val session: SessionRepository,
    private val addresses: AddressRepository,
) : ViewModel() {

    private val addressId: Long = savedState.get<Long>(AddressFormRoute.ADDRESS_ID_ARG) ?: AddressFormRoute.NEW_ADDRESS_ID
    private val isNew: Boolean = addressId == AddressFormRoute.NEW_ADDRESS_ID

    private val _uiState = MutableStateFlow<AddressFormUiState>(AddressFormUiState.Loading)
    val uiState: StateFlow<AddressFormUiState> = _uiState.asStateFlow()

    private val _events = Channel<AddressFormEvent>(Channel.BUFFERED)
    val events: Flow<AddressFormEvent> = _events.receiveAsFlow()

    init {
        viewModelScope.launch { open() }
    }

    fun retryLoad() {
        val failed = _uiState.value as? AddressFormUiState.LoadFailed ?: return
        if (failed.retrying) return
        _uiState.value = failed.copy(retrying = true)
        viewModelScope.launch { open() }
    }

    fun onFieldChange(field: AddressField, value: String) {
        savedState[field.savedKey] = value
        updateForm { it.copy(draft = it.draft.with(field, value), errors = it.errors - field, formError = null) }
    }

    fun onDefaultChange(isDefault: Boolean) {
        updateForm { form ->
            if (form.defaultLocked) return@updateForm form
            savedState[KEY_DEFAULT] = isDefault
            form.copy(draft = form.draft.copy(isDefault = isDefault), formError = null)
        }
    }

    fun submit() {
        val form = _uiState.value as? AddressFormUiState.Form ?: return
        // Ketukan ganda saat permintaan masih berjalan tidak membuat dua alamat.
        if (form.submitting) return
        val issues = AddressRules.validate(form.draft)
        if (issues.isNotEmpty()) {
            updateForm { it.copy(errors = issues.mapValues { (_, issue) -> AddressFieldError.Local(issue) }, formError = null) }
            return
        }
        updateForm { it.copy(submitting = true, errors = emptyMap(), formError = null) }
        viewModelScope.launch {
            val input = form.draft.toInput()
            val result = if (isNew) addresses.create(input) else addresses.update(addressId, input)
            when (result) {
                is ApiResult.Success -> {
                    updateForm { it.copy(submitting = false) }
                    _events.send(AddressFormEvent.Saved)
                }
                is ApiResult.Failure -> applyFailure(result.error)
            }
        }
    }

    private suspend fun open() {
        if (isNew) {
            // Nama dan telepon penerima diisi dari profil sebagai titik awal; pelanggan bebas mengubahnya.
            val account = (session.state.first { it !is SessionState.Loading } as? SessionState.SignedIn)?.account
            _uiState.value = AddressFormUiState.Form(
                isNew = true,
                draft = restored(AddressDraft(recipientName = account?.name.orEmpty(), phone = account?.phone.orEmpty())),
            )
            return
        }
        // Kontrak tidak punya "ambil satu alamat": alamat dicari di daftar milik akun.
        when (val result = addresses.list()) {
            is ApiResult.Success -> {
                val address = result.value.firstOrNull { it.id == addressId }
                _uiState.value = if (address == null) {
                    AddressFormUiState.NotFound
                } else {
                    AddressFormUiState.Form(
                        isNew = false,
                        draft = restored(AddressDraft.from(address)),
                        defaultLocked = address.isDefault,
                    )
                }
            }
            is ApiResult.Failure -> _uiState.value = AddressFormUiState.LoadFailed(result.error)
        }
    }

    /** Suntingan yang belum disimpan (saved state) didahulukan atas nilai awal. */
    private fun restored(initial: AddressDraft): AddressDraft {
        val withFields = AddressField.entries.fold(initial) { draft, field ->
            savedState.get<String>(field.savedKey)?.let { draft.with(field, it) } ?: draft
        }
        return if (initial.isDefault) withFields else withFields.copy(isDefault = savedState.get<Boolean>(KEY_DEFAULT) ?: false)
    }

    private fun applyFailure(error: ApiError) {
        val http = error as? ApiError.Http
        if (http?.code == ApiErrorCodes.NOT_FOUND && !isNew) {
            _uiState.value = AddressFormUiState.NotFound
            return
        }
        val fieldErrors = http?.takeIf { it.code == ApiErrorCodes.VALIDATION_FAILED }?.fieldErrors.orEmpty()
        val mapped = AddressField.entries.mapNotNull { field ->
            fieldErrors[field.wireName]?.firstOrNull { it.isNotBlank() }?.let { field to AddressFieldError.Server(it) }
        }.toMap()
        updateForm {
            it.copy(
                submitting = false,
                errors = mapped,
                // Sesi berakhir ditangani alur sesi; galat untuk kolom yang tidak ada di formulir tetap harus terlihat.
                formError = error.takeIf { mapped.isEmpty() && !error.isUnauthenticated },
            )
        }
    }

    private inline fun updateForm(transform: (AddressFormUiState.Form) -> AddressFormUiState.Form) {
        _uiState.update { state -> (state as? AddressFormUiState.Form)?.let(transform) ?: state }
    }

    private val AddressField.savedKey: String get() = "address_form_$wireName"

    private companion object {
        const val KEY_DEFAULT = "address_form_is_default"
    }
}
