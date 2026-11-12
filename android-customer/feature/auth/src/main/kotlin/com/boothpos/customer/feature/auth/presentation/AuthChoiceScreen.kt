package com.boothpos.customer.feature.auth.presentation

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.semantics
import com.boothpos.customer.core.ui.component.PrimaryButton
import com.boothpos.customer.core.ui.component.SecondaryButton
import com.boothpos.customer.core.ui.theme.Spacing
import com.boothpos.customer.feature.auth.R

/**
 * Pilihan awal: Masuk (aksi utama), Daftar, atau melihat toko tanpa akun. Tanpa state: tidak ada
 * data yang dimuat dan tidak ada yang bisa gagal di layar ini.
 */
@Composable
internal fun AuthChoiceScreen(
    onSignIn: () -> Unit,
    onRegister: () -> Unit,
    onContinueAsGuest: () -> Unit,
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
        Text(
            text = stringResource(R.string.auth_choice_title),
            style = MaterialTheme.typography.headlineSmall,
            modifier = Modifier.semantics { heading() },
        )
        Text(
            text = stringResource(R.string.auth_choice_message),
            style = MaterialTheme.typography.bodyLarge,
            color = MaterialTheme.colorScheme.onSurfaceVariant,
        )
        Spacer(Modifier.height(Spacing.md))
        PrimaryButton(
            text = stringResource(R.string.auth_action_sign_in),
            onClick = onSignIn,
            modifier = Modifier.fillMaxWidth(),
        )
        SecondaryButton(
            text = stringResource(R.string.auth_action_register),
            onClick = onRegister,
            modifier = Modifier.fillMaxWidth(),
        )
        AuthTextLink(text = stringResource(R.string.auth_choice_guest), onClick = onContinueAsGuest)
    }
}
