package com.boothpos.customer.feature.auth.presentation

import androidx.lifecycle.SavedStateHandle
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.boothpos.customer.core.data.domain.validation.ContactRules
import com.boothpos.customer.core.network.error.ApiResult
import com.boothpos.customer.feature.auth.domain.AuthRepository
import com.boothpos.customer.feature.auth.domain.CredentialRules
import com.boothpos.customer.feature.auth.domain.RegisterInput
import dagger.hilt.android.lifecycle.HiltViewModel
import javax.inject.Inject
import kotlinx.coroutines.channels.Channel
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.receiveAsFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

data class RegisterUiState(
    val name: String = "",
    val email: String = "",
    val phone: String = "",
    val password: String = "",
    val nameError: FieldErrorUi? = null,
    val emailError: FieldErrorUi? = null,
    val phoneError: FieldErrorUi? = null,
    val passwordError: FieldErrorUi? = null,
    val submitting: Boolean = false,
    val formMessage: AuthFormMessage? = null,
) {
    // Data pribadi dan kata sandi tidak boleh muncul bila state ini tercetak di log atau laporan crash.
    override fun toString(): String = "RegisterUiState(submitting=$submitting, formMessage=$formMessage)"
}

@HiltViewModel
class RegisterViewModel @Inject constructor(
    private val auth: AuthRepository,
    private val savedState: SavedStateHandle,
) : ViewModel() {

    // Nama, e-mail, dan telepon ikut saved state supaya formulir tidak hilang bila proses dimatikan
    // sistem. Kata sandi TIDAK PERNAH: ia hanya hidup di memori ViewModel.
    private val _uiState = MutableStateFlow(
        RegisterUiState(
            name = savedState[KEY_NAME] ?: "",
            email = savedState[KEY_EMAIL] ?: "",
            phone = savedState[KEY_PHONE] ?: "",
        ),
    )
    val uiState: StateFlow<RegisterUiState> = _uiState.asStateFlow()

    private val _events = Channel<AuthEvent>(Channel.BUFFERED)
    val events: Flow<AuthEvent> = _events.receiveAsFlow()

    fun onNameChange(value: String) {
        savedState[KEY_NAME] = value
        _uiState.update { it.copy(name = value, nameError = null, formMessage = null) }
    }

    fun onEmailChange(value: String) {
        savedState[KEY_EMAIL] = value
        _uiState.update { it.copy(email = value, emailError = null, formMessage = null) }
    }

    fun onPhoneChange(value: String) {
        savedState[KEY_PHONE] = value
        _uiState.update { it.copy(phone = value, phoneError = null, formMessage = null) }
    }

    fun onPasswordChange(value: String) {
        _uiState.update { it.copy(password = value, passwordError = null, formMessage = null) }
    }

    fun submit() {
        val current = _uiState.value
        // Ketukan ganda / tombol IME saat permintaan masih berjalan tidak membuat dua akun.
        if (current.submitting) return
        val nameIssue = ContactRules.name(current.name)
        val emailIssue = CredentialRules.email(current.email)
        val phoneIssue = ContactRules.phone(current.phone)
        val passwordIssue = CredentialRules.newPassword(current.password)
        if (nameIssue != null || emailIssue != null || phoneIssue != null || passwordIssue != null) {
            _uiState.update {
                it.copy(
                    nameError = nameIssue?.let(FieldErrorUi::Local),
                    emailError = emailIssue?.let(FieldErrorUi::Local),
                    phoneError = phoneIssue?.let(FieldErrorUi::Local),
                    passwordError = passwordIssue?.let(FieldErrorUi::Local),
                    formMessage = null,
                )
            }
            return
        }
        _uiState.update {
            it.copy(
                submitting = true,
                nameError = null,
                emailError = null,
                phoneError = null,
                passwordError = null,
                formMessage = null,
            )
        }
        viewModelScope.launch {
            val input = RegisterInput(
                name = current.name,
                email = current.email,
                password = current.password,
                phone = current.phone.takeIf { it.isNotBlank() },
            )
            when (val result = auth.register(input)) {
                is ApiResult.Success -> {
                    // Kata sandi dibuang dari memori begitu tidak diperlukan lagi.
                    _uiState.update { it.copy(submitting = false, password = "") }
                    _events.send(AuthEvent.Authenticated)
                }
                is ApiResult.Failure -> {
                    val failure = result.error.toFormFailure(FIELDS)
                    _uiState.update {
                        it.copy(
                            submitting = false,
                            nameError = failure.fieldErrors[FIELD_NAME],
                            emailError = failure.fieldErrors[FIELD_EMAIL],
                            phoneError = failure.fieldErrors[FIELD_PHONE],
                            passwordError = failure.fieldErrors[FIELD_PASSWORD],
                            formMessage = failure.message,
                        )
                    }
                }
            }
        }
    }

    private companion object {
        const val KEY_NAME = "register_name"
        const val KEY_EMAIL = "register_email"
        const val KEY_PHONE = "register_phone"
        const val FIELD_NAME = "name"
        const val FIELD_EMAIL = "email"
        const val FIELD_PHONE = "phone"
        const val FIELD_PASSWORD = "password"
        val FIELDS = setOf(FIELD_NAME, FIELD_EMAIL, FIELD_PHONE, FIELD_PASSWORD)
    }
}
