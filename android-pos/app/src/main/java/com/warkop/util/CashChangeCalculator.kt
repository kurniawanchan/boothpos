package com.warkop.util

/**
 * Simple utility to calculate cash change.
 * Returns the amount of change (cashGiven - total) or 0.0 if cashGiven < total.
 */
object CashChangeCalculator {
    fun calculateChange(cashGiven: Double, total: Double): Double {
        val change = cashGiven - total
        return if (change >= 0) change else 0.0
    }
}
