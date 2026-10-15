package com.warkop.util

/**
 * Simple utility to generate a textual receipt for a given Tab.
 * Returns a formatted string; in a real app this would create PDF/printable data.
 */
object ReceiptGenerator {
    fun generateReceipt(tabId: String, items: List<String>, total: Double): String {
        val builder = StringBuilder()
        builder.append("Receipt for Tab: $tabId\n")
        builder.append("Items:\n")
        items.forEach { builder.append("- $it\n") }
        builder.append("Total: $$total\n")
        builder.append("Thank you!\n")
        return builder.toString()
    }
}
