package com.boothpos.customer.feature.account.presentation

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.semantics
import androidx.hilt.lifecycle.viewmodel.compose.hiltViewModel
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import com.boothpos.customer.core.ui.component.InlineMessage
import com.boothpos.customer.core.ui.component.InlineMessageTone
import com.boothpos.customer.core.ui.component.LoadingState
import com.boothpos.customer.core.ui.component.PrimaryButton
import com.boothpos.customer.core.ui.component.SecondaryButton
import com.boothpos.customer.core.ui.component.apiErrorMessage
import com.boothpos.customer.core.ui.theme.Dimens
import com.boothpos.customer.core.ui.theme.Spacing
import com.boothpos.customer.feature.account.R
import com.boothpos.customer.core.ui.R as CoreUiR

/** Bagian stateful layar Akun: mengumpulkan state, lalu menyerahkannya ke layar stateless. */
@Composable
internal fun AccountScreenRoute(
    onSignIn: () -> Unit,
    onRegister: () -> Unit,
    onEditProfile: () -> Unit,
    onManageAddresses: () -> Unit,
    viewModel: AccountViewModel = hiltViewModel(),
) {
    val state by viewModel.uiState.collectAsStateWithLifecycle()
    AccountScreen(
        state = state,
        onSignIn = onSignIn,
        onRegister = onRegister,
        onEditProfile = onEditProfile,
        onManageAddresses = onManageAddresses,
        onRetryRefresh = viewModel::refresh,
        onSignOutClick = viewModel::requestSignOut,
        onConfirmSignOut = viewModel::confirmSignOut,
        onDismissSignOut = viewModel::dismissSignOut,
    )
}

@Composable
internal fun AccountScreen(
    state: AccountUiState,
    onSignIn: () -> Unit,
    onRegister: () -> Unit,
    onEditProfile: () -> Unit,
    onManageAddresses: () -> Unit,
    onRetryRefresh: () -> Unit,
    onSignOutClick: () -> Unit,
    onConfirmSignOut: () -> Unit,
    onDismissSignOut: () -> Unit,
    modifier: Modifier = Modifier,
) {
    when (state) {
        AccountUiState.Loading -> LoadingState(modifier = modifier)
        is AccountUiState.SignedOut -> SignInPrompt(
            sessionExpired = state.sessionExpired,
            onSignIn = onSignIn,
            onRegister = onRegister,
            modifier = modifier,
        )
        is AccountUiState.Profile -> ProfileContent(
            state = state,
            onEditProfile = onEditProfile,
            onManageAddresses = onManageAddresses,
            onRetryRefresh = onRetryRefresh,
            onSignOutClick = onSignOutClick,
            onConfirmSignOut = onConfirmSignOut,
            onDismissSignOut = onDismissSignOut,
            modifier = modifier,
        )
    }
}

/** Belum masuk: satu aksi utama (Masuk) dan satu pendamping (Daftar). */
@Composable
private fun SignInPrompt(
    sessionExpired: Boolean,
    onSignIn: () -> Unit,
    onRegister: () -> Unit,
    modifier: Modifier = Modifier,
) {
    Column(
        modifier = modifier
            .fillMaxSize()
            .verticalScroll(rememberScrollState())
            .padding(Spacing.md),
        verticalArrangement = Arrangement.spacedBy(Spacing.sm),
    ) {
        Spacer(Modifier.height(Spacing.lg))
        if (sessionExpired) {
            InlineMessage(text = stringResource(R.string.account_session_expired))
        }
        Text(
            text = stringResource(R.string.account_signed_out_title),
            style = MaterialTheme.typography.headlineSmall,
            modifier = Modifier.semantics { heading() },
        )
        Text(
            text = stringResource(R.string.account_signed_out_message),
            style = MaterialTheme.typography.bodyLarge,
            color = MaterialTheme.colorScheme.onSurfaceVariant,
        )
        Spacer(Modifier.height(Spacing.md))
        PrimaryButton(
            text = stringResource(R.string.account_action_sign_in),
            onClick = onSignIn,
            modifier = Modifier.fillMaxWidth(),
        )
        SecondaryButton(
            text = stringResource(R.string.account_action_register),
            onClick = onRegister,
            modifier = Modifier.fillMaxWidth(),
        )
    }
}

@Composable
private fun ProfileContent(
    state: AccountUiState.Profile,
    onEditProfile: () -> Unit,
    onManageAddresses: () -> Unit,
    onRetryRefresh: () -> Unit,
    onSignOutClick: () -> Unit,
    onConfirmSignOut: () -> Unit,
    onDismissSignOut: () -> Unit,
    modifier: Modifier = Modifier,
) {
    Column(
        modifier = modifier
            .fillMaxSize()
            .verticalScroll(rememberScrollState()),
    ) {
        if (state.refreshing) {
            val refreshingLabel = stringResource(R.string.account_refreshing)
            LinearProgressIndicator(
                modifier = Modifier
                    .fillMaxWidth()
                    .semantics { contentDescription = refreshingLabel },
            )
        }
        Column(
            modifier = Modifier.padding(Spacing.md),
            verticalArrangement = Arrangement.spacedBy(Spacing.md),
        ) {
            state.refreshError?.let { error ->
                // Profil dari sesi tetap tampil; pesan ini hanya memberi tahu bahwa ia mungkin belum terbaru.
                InlineMessage(
                    text = stringResource(R.string.account_refresh_failed, apiErrorMessage(error)),
                    tone = InlineMessageTone.Error,
                    actionLabel = stringResource(CoreUiR.string.core_ui_retry),
                    onAction = onRetryRefresh,
                )
            }
            Column {
                ProfileField(label = stringResource(R.string.account_field_name), value = state.name)
                HorizontalDivider()
                ProfileField(label = stringResource(R.string.account_field_email), value = state.email)
                HorizontalDivider()
                ProfileField(
                    label = stringResource(R.string.account_field_phone),
                    value = state.phone ?: stringResource(R.string.account_field_empty),
                )
            }
            PrimaryButton(
                text = stringResource(R.string.account_action_edit_profile),
                onClick = onEditProfile,
                enabled = !state.signingOut,
                modifier = Modifier.fillMaxWidth(),
            )
            // Kelola alamat tinggal di modul checkout; :app yang membukanya.
            SecondaryButton(
                text = stringResource(R.string.account_action_addresses),
                onClick = onManageAddresses,
                enabled = !state.signingOut,
                modifier = Modifier.fillMaxWidth(),
            )
            SecondaryButton(
                text = stringResource(R.string.account_action_sign_out),
                onClick = onSignOutClick,
                enabled = !state.signingOut,
                modifier = Modifier.fillMaxWidth(),
            )
        }
    }

    if (state.confirmingSignOut) {
        AlertDialog(
            onDismissRequest = onDismissSignOut,
            title = { Text(stringResource(R.string.account_sign_out_confirm_title)) },
            text = { Text(stringResource(R.string.account_sign_out_confirm_message)) },
            confirmButton = {
                TextButton(onClick = onConfirmSignOut, modifier = Modifier.heightIn(min = Dimens.MinTouchTarget)) {
                    Text(stringResource(R.string.account_action_sign_out))
                }
            },
            dismissButton = {
                TextButton(onClick = onDismissSignOut, modifier = Modifier.heightIn(min = Dimens.MinTouchTarget)) {
                    Text(stringResource(R.string.account_action_cancel))
                }
            },
        )
    }
}

/** Label + nilai dibaca pembaca layar sebagai satu kalimat ("Nama, Pelanggan Uji"). */
@Composable
internal fun ProfileField(label: String, value: String, modifier: Modifier = Modifier, helper: String? = null) {
    Column(
        modifier = modifier
            .fillMaxWidth()
            .padding(vertical = Spacing.sm)
            .semantics(mergeDescendants = true) {},
    ) {
        Text(label, style = MaterialTheme.typography.labelLarge, color = MaterialTheme.colorScheme.onSurfaceVariant)
        Text(value, style = MaterialTheme.typography.bodyLarge)
        if (helper != null) {
            Text(helper, style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
        }
    }
}
