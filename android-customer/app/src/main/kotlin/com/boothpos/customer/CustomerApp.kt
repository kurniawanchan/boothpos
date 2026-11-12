package com.boothpos.customer

import android.app.Application
import coil3.ImageLoader
import coil3.PlatformContext
import coil3.SingletonImageLoader
import dagger.Lazy
import dagger.hilt.android.HiltAndroidApp
import javax.inject.Inject

/**
 * Tidak ada pekerjaan saat start: tanpa analitik, tanpa SDK iklan, tanpa panggilan jaringan
 * sebelum frame pertama. ImageLoader berbatas dibuat malas saat gambar pertama diminta.
 */
@HiltAndroidApp
class CustomerApp : Application(), SingletonImageLoader.Factory {

    @Inject
    lateinit var imageLoader: Lazy<ImageLoader>

    override fun newImageLoader(context: PlatformContext): ImageLoader = imageLoader.get()
}
