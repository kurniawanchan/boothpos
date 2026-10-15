package com.warkop.ui.approval

import androidx.compose.runtime.Composable

/**
 * Placeholder dialog for supervisor approval.
 * In a real app this would show a Dialog with input for supervisor credentials.
 */
@Composable
fun ApprovalDialog(onApproved: () -> Unit, onDismiss: () -> Unit) {
    // Placeholder: Simulated approval without full UI dialog.
    // For now, immediately invoke onApproved to simulate approval.
    onApproved()
}
