package com.boothpos.customer.feature.account.presentation

import androidx.lifecycle.SavedStateHandle
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.boothpos.customer.core.data.domain.session.SessionRepository
import com.boothpos.customer.core.data.domain.session.SessionState
import com.boothpos.customer.core.data.domain.validation.ContactRules
import com.boothpos.customer.core.data.domain.validation.FieldIssue
import com.boothpos.customer.core.network.error.ApiError
import com.boothpos.customer.core.network.error.ApiErrorCodes
import com.boothpos.customer.core.network.error.ApiResult
import com.boothpos.customer.feature.account.domain.ProfileRepository
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
sealed interface ProfileFieldError {
    data class Local(val issue: FieldIssue) : ProfileFieldError

    data class Server(val message: String) : ProfileFieldError
}

sealed interface EditProfileUiState {
    /** Sesi tersimpan belum selesai dibaca (hanya setelah proses dipulihkan sistem). */
    data object Loading : EditProfileUiState

    data class Form(
        val name: String,
        val phone: String,
        /** Hanya ditampilkan: e-mail tidak dapat diubah di v1. */
        val email: String,
        val nameError: ProfileFieldError? = null,
        val phoneError: ProfileFieldError? = null,
        val submitting: Boolean = false,
        /** Galat tingkat formulir (jaringan, server, sesi berakhir). */
        val formError: ApiError? = null,
    ) : EditProfileUiState {
        // Data pribadi tidak boleh muncul bila state ini tercetak di log atau laporan crash.
        override fun toString(): String = "Form(submitting=$submitting, formError=$formError)"
    }
}

sealed interface EditProfileEvent {
    /** Profil tersimpan; kembali ke layar Akun. */
    data object Saved : EditProfileEvent

    /** Layar dibuka tanpa sesi (mis. sesi berakhir): tidak ada yang bisa diubah, tutup layar. */
    data object SessionMissing : EditProfileEvent
}

@HiltViewModel
class EditProfileViewModel @Inject constructor(
    private val session: SessionRepository,
    private val profile: ProfileRepository,
    private val savedState: SavedStateHandle,
) : ViewModel() {

    private val _uiState = MutableStateFlow<EditProfileUiState>(EditProfileUiState.Loading)
    val uiState: StateFlow<EditProfileUiState> = _uiState.asStateFlow()

    private val _events = Channel<EditProfileEvent>(Channel.BUFFERED)
    val events: Flow<EditProfileEvent> = _events.receiveAsFlow()

    init {
        viewModelScope.launch {
            val account = (session.state.first { it !is SessionState.Loading } as? SessionState.SignedIn)?.account
            if (account == null) {
                _events.send(EditProfileEvent.SessionMissing)
            } else {
                // Suntingan yang belum disimpan (saved state) didahulukan atas data akun.
                _uiState.value = EditProfileUiState.Form(
                    name = savedState[KEY_NAME] ?: account.name,
                    phone = savedState[KEY_PHONE] ?: account.phone.orEmpty(),
                    email = account.email,
                )
            }
        }
    }

    fun onNameChange(value: String) {
        savedState[KEY_NAME] = value
        updateForm { it.copy(name = value, nameError = null, formError = null) }
    }

    fun onPhoneChange(value: String) {
        savedState[KEY_PHONE] = value
        updateForm { it.copy(phone = value, phoneError = null, formError = null) }
    }

    fun submit() {
        val form = _uiState.value as? EditProfileUiState.Form ?: return
        // Ketukan ganda / tombol IME saat permintaan masih berjalan tidak mengirim dua kali.
        if (form.submitting) return
        val nameIssue = ContactRules.name(form.name)
        val phoneIssue = ContactRules.phone(form.phone)
        if (nameIssue != null || phoneIssue != null) {
            updateForm {
                it.copy(
                    nameError = nameIssue?.let(ProfileFieldError::Local),
                    phoneError = phoneIssue?.let(ProfileFieldError::Local),
                    formError = null,
                )
            }
            return
        }
        updateForm { it.copy(submitting = true, nameError = null, phoneError = null, formError = null) }
        viewModelScope.launch {
            when (val result = profile.update(form.name, form.phone.takeIf { it.isNotBlank() })) {
                is ApiResult.Success -> {
                    updateForm { it.copy(submitting = false) }
                    _events.send(EditProfileEvent.Saved)
                }
                is ApiResult.Failure -> applyFailure(result.error)
            }
        }
    }

    private fun applyFailure(error: ApiError) {
        val serverFieldErrors = (error as? ApiError.Http)
            ?.takeIf { it.code == ApiErrorCodes.VALIDATION_FAILED }
            ?.fieldErrors
            .orEmpty()
        val nameError = serverFieldErrors.firstMessage(FIELD_NAME)
        val phoneError = serverFieldErrors.firstMessage(FIELD_PHONE)
        updateForm {
            it.copy(
                submitting = false,
                nameError = nameError,
                phoneError = phoneError,
                // Galat untuk kolom yang tidak ada di formulir ini tetap harus terlihat.
                formError = error.takeIf { nameError == null && phoneError == null },
            )
        }
    }

    private fun Map<String, List<String>>.firstMessage(field: String): ProfileFieldError? =
        this[field]?.firstOrNull { it.isNotBlank() }?.let(ProfileFieldError::Server)

    private inline fun updateForm(transform: (EditProfileUiState.Form) -> EditProfileUiState.Form) {
        _uiState.update { state -> (state as? EditProfileUiState.Form)?.let(transform) ?: state }
    }

    private companion object {
        const val KEY_NAME = "edit_profile_name"
        const val KEY_PHONE = "edit_profile_phone"
        const val FIELD_NAME = "name"
        const val FIELD_PHONE = "phone"
    }
}
