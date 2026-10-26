package com.warkop.pos.ui

/** 077 / US3 — bentuk layar PIN: dua kolom berdampingan (info pengguna | PIN) atau satu kolom bertumpuk. */
enum class PinLayout { TWO_COLUMN, STACKED }

/** Lebar minimum (dp) untuk dua kolom: di bawah ini keypad 340 dp dan daftar staf tidak muat berdampingan tanpa terpotong. */
const val PIN_TWO_COLUMN_MIN_DP = 720f

/** 077 — layar sempit (ponsel, tablet potret kecil) menumpuk kolom dalam urutan sama: info pengguna dulu, lalu PIN. */
fun pinLayoutFor(widthDp: Float): PinLayout = if (widthDp < PIN_TWO_COLUMN_MIN_DP) PinLayout.STACKED else PinLayout.TWO_COLUMN
