package com.warkop.pos.ui

import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.produceState
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.alpha
import androidx.compose.ui.graphics.ImageBitmap
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.TextUnit
import androidx.compose.ui.unit.sp
import com.warkop.pos.data.MenuImageLoader
import com.warkop.pos.model.Tone

/**
 * 075 — slot foto produk/varian. Dipakai kartu grid dan baris daftar varian supaya keduanya jatuh ke tampilan yang
 * sama (inisial) saat produk tidak punya foto, foto sedang dimuat, atau gagal dimuat; foto tidak pernah menghalangi
 * pemesanan dan tidak memunculkan galat.
 */
@Composable
fun ProductPhoto(imageUrl: String?, name: String, tone: Tone, modifier: Modifier = Modifier, dim: Float = 1f, initialsSize: TextUnit = 22.sp) {
    val (bg, fg) = toneColors(tone)
    // Nilai awal dari cache sinkron: foto yang sudah pernah dimuat tidak berkedip jadi inisial saat kartu digulir masuk lagi.
    val photo: ImageBitmap? by produceState(initialValue = imageUrl?.let(MenuImageLoader::peek), imageUrl, MenuImageLoader.generation) {
        value = if (imageUrl == null) null else MenuImageLoader.load(imageUrl)
    }
    Box(modifier.background(bg).alpha(dim), contentAlignment = Alignment.Center) {
        val loaded = photo
        if (loaded != null) Image(loaded, name, Modifier.fillMaxSize(), contentScale = ContentScale.Crop)
        else Text(initialsOf(name), color = fg, fontSize = initialsSize, fontWeight = FontWeight.SemiBold)
    }
}
