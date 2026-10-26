package com.warkop.pos.ui

import com.warkop.pos.model.StaffUser

/** 077/078 / US2 — bentuk layar PIN: tiga kolom (toko | staf | PIN), dua kolom (toko + staf | PIN), atau satu kolom bertumpuk. */
enum class PinLayout { THREE_COLUMN, TWO_COLUMN, STACKED }

/** Lebar minimum (dp) tiga kolom: panel toko ≥ 270, daftar staf ≥ 300, dan keypad 340 + ruang tepi berdampingan tanpa terpotong. */
const val PIN_THREE_COLUMN_MIN_DP = 960f

/** Lebar minimum (dp) dua kolom: di bawah ini keypad 340 dp dan daftar staf tidak muat berdampingan. */
const val PIN_TWO_COLUMN_MIN_DP = 720f

/** Layar sempit (ponsel, tablet potret kecil) menumpuk kolom dalam urutan sama: identitas toko, staf, lalu PIN. */
fun pinLayoutFor(widthDp: Float): PinLayout = when {
    widthDp >= PIN_THREE_COLUMN_MIN_DP -> PinLayout.THREE_COLUMN
    widthDp >= PIN_TWO_COLUMN_MIN_DP -> PinLayout.TWO_COLUMN
    else -> PinLayout.STACKED
}

/** Alasan yang tampil pada staf tanpa PIN (redup, tidak bisa dipilih) — dulu hilang tanpa penjelasan. */
const val NO_PIN_REASON = "Belum ada PIN"

/**
 * 078 — satu baris layar PIN. [expanded] = baris terpilih yang DIPERBESAR di tempatnya (foto besar, nama, peran); tidak ada blok
 * "pengguna terpilih" terpisah, jadi tidak ada nama yang muncul dua kali. [selectable] false = tanpa PIN: redup dan tidak bisa diketuk.
 */
data class StaffRow(val user: StaffUser, val expanded: Boolean, val selectable: Boolean)

/** Satu baris per ORANG (kunci = id): dua orang bernama sama tetap dua baris, dibedakan peran dan foto. */
fun staffRows(everyone: List<StaffUser>, selectedId: String?): List<StaffRow> =
    everyone.map { StaffRow(it, expanded = it.hasPin && it.id == selectedId, selectable = it.hasPin) }

/**
 * Pilihan awal/terjaga bila daftar dimuat ulang: pilihan lama dipertahankan (dengan data terbaru, mis. foto yang berubah), bila hilang
 * jatuh ke staf pertama yang bisa masuk, dan null bila tidak ada satu pun.
 */
fun selectedAfterRosterChange(selected: StaffUser?, usable: List<StaffUser>): StaffUser? =
    usable.firstOrNull { it.id == selected?.id } ?: usable.firstOrNull()
