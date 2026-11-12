package com.boothpos.customer.core.ui.di

import android.content.Context
import coil3.ImageLoader
import com.boothpos.customer.core.ui.image.BoundedImageLoader
import dagger.Module
import dagger.Provides
import dagger.hilt.InstallIn
import dagger.hilt.android.qualifiers.ApplicationContext
import dagger.hilt.components.SingletonComponent
import javax.inject.Singleton

@Module
@InstallIn(SingletonComponent::class)
object ImageModule {

    @Provides
    @Singleton
    fun provideImageLoader(@ApplicationContext context: Context): ImageLoader =
        BoundedImageLoader.create(context)
}
