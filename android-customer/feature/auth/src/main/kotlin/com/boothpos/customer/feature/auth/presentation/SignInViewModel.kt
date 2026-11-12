package com.boothpos.customer.feature.auth.presentation

import androidx.lifecycle.SavedStateHandle
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.boothpos.customer.core.data.domain.session.SessionRepository
import com.boothpos.customer.core.data.domain.session.SessionState
import com.boothpos.customer.core.data.domain.session.SignOutReason
import com.boothpos.customer.core.network.error.ApiResult
import com.boothpos.customer.feature.auth.domain.AuthRepository
import com.boothpos.customer.feature.auth.domain.CredentialRules
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

data class SignInUiState(
    val email: String = "",
    val password: String = "",
    val emailError: FieldErrorUi? = null,
    val passwordError: FieldErrorUi? = null,
    val submitting: Boolean = false,
    val formMessage: AuthFormMessage? = null,
    /** Sesi sebelumnya berakhir (401/kedaluwarsa): tampilkan "Sesi berakhir, silakan masuk lagi". */
    val sessionExpired: Boolean = false,
) {
    // E-mail dan kata sandi tidak boleh muncul bila state ini tercetak di log atau laporan crash.
    override fun toString(): String =
        "SignInUiState(submitting=$submitting, formMessage=$formMessage, sessionExpired=$sessionExpired)"
}

@HiltViewModel
class SignInViewModel @Inject constructor(
    private val auth: AuthRepository,
    session: SessionRepository,
    private val savedState: SavedStateHandle,
) : ViewModel() {

    // Hanya e-mail yang ikut saved state (bertahan saat proses dimatikan sistem). Kata sandi
    // hanya hidup di memori ViewModel dan tidak pernah ditulis ke mana pun.
    private val _uiState = MutableStateFlow(SignInUiState(email = savedState[KEY_EMAIL] ?: ""))
    val uiState: StateFlow<SignInUiState> = _uiState.asStateFlow()

    private val _events = Channel<AuthEvent>(Channel.BUFFERED)
    val events: Flow<AuthEvent> = _events.receiveAsFlow()

    init {
        viewModelScope.launch {
            session.state.collect { state ->
                val expired = state is SessionState.SignedOut && state.reason == SignOutReason.SessionExpired
                _uiState.update { it.copy(sessionExpired = expired) }
            }
        }
    }

    fun onEmailChange(value: String) {
        savedState[KEY_EMAIL] = value
        _uiState.update { it.copy(email = value, emailError = null, formMessage = null) }
    }

    fun onPasswordChange(value: String) {
        _uiState.update { it.copy(password = value, passwordError = null, formMessage = null) }
    }

    fun submit() {
        val current = _uiState.value
        // Ketukan ganda / tombol IME saat permintaan masih berjalan tidak mengirim dua kali.
        if (current.submitting) return
        val emailIssue = CredentialRules.email(current.email)
        val passwordIssue = CredentialRules.currentPassword(current.password)
        if (emailIssue != null || passwordIssue != null) {
            _uiState.update {
                it.copy(
                    emailError = emailIssue?.let(FieldErrorUi::Local),
                    passwordError = passwordIssue?.let(FieldErrorUi::Local),
                    formMessage = null,
                )
            }
            return
        }
        _uiState.update { it.copy(submitting = true, emailError = null, passwordError = null, formMessage = null) }
        viewModelScope.launch {
            when (val result = auth.signIn(current.email, current.password)) {
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
                            emailError = failure.fieldErrors[FIELD_EMAIL],
                            passwordError = failure.fieldErrors[FIELD_PASSWORD],
                            formMessage = failure.message,
                        )
                    }
                }
            }
        }
    }

    private companion object {
        const val KEY_EMAIL = "sign_in_email"
        const val FIELD_EMAIL = "email"
        const val FIELD_PASSWORD = "password"
        val FIELDS = setOf(FIELD_EMAIL, FIELD_PASSWORD)
    }
}
