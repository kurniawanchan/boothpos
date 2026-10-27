package com.warkop.pos.ui

import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.sp
import com.warkop.pos.model.StaffUser
import com.warkop.pos.model.Tone

/** 077 / US7 — URL foto yang layak dimuat: kosong atau spasi berarti tidak ada foto (tampilkan inisial). */
fun avatarPhotoUrl(url: String?): String? = url?.trim()?.takeIf { it.isNotEmpty() }

/**
 * 077 / US7 — avatar pengguna: foto dari server bila ada, selain itu (tidak ada, sedang dimuat, atau gagal dimuat) inisial seperti
 * sebelumnya. Memakai slot gambar yang sama dengan foto produk, jadi cache dan aturan "tidak pernah menghalangi" ikut berlaku.
 */
@Composable
fun UserAvatar(user: StaffUser?, size: Dp, modifier: Modifier = Modifier, tone: Tone = Tone.TINT, textSize: Float = size.value * 0.36f) {
    ProductPhoto(
        avatarPhotoUrl(user?.photoUrl), user?.name.orEmpty(), tone, modifier.clip(CircleShape).then(Modifier.size(size)),
        initialsSize = textSize.sp, fallbackText = user?.initials.orEmpty(),
    )
}
