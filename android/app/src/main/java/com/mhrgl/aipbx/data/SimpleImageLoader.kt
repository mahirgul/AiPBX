package com.mhrgl.aipbx.data

import android.graphics.Bitmap
import android.graphics.BitmapFactory
import android.util.LruCache
import android.widget.ImageView
import kotlinx.coroutines.*
import java.io.InputStream
import java.net.HttpURLConnection
import java.net.URL

object SimpleImageLoader {

    private val maxMemory = (Runtime.getRuntime().maxMemory() / 1024).toInt()
    private val cacheSize = maxMemory / 8

    private val memoryCache = object : LruCache<String, Bitmap>(cacheSize) {
        override fun sizeOf(key: String, bitmap: Bitmap): Int {
            return bitmap.byteCount / 1024
        }
    }

    private val scope = CoroutineScope(Dispatchers.Main + SupervisorJob())

    fun load(urlStr: String, imageView: ImageView) {
        imageView.tag = urlStr

        val cached = memoryCache.get(urlStr)
        if (cached != null) {
            imageView.setImageBitmap(cached)
            return
        }

        imageView.setImageDrawable(null)

        scope.launch {
            val bitmap = withContext(Dispatchers.IO) {
                downloadBitmap(urlStr)
            }
            if (bitmap != null && imageView.tag == urlStr) {
                memoryCache.put(urlStr, bitmap)
                imageView.setImageBitmap(bitmap)
            }
        }
    }

    private fun downloadBitmap(urlStr: String): Bitmap? {
        return try {
            val url = URL(urlStr)
            val conn = url.openConnection() as HttpURLConnection
            conn.connectTimeout = 10000
            conn.readTimeout = 15000
            conn.instanceFollowRedirects = true
            conn.connect()

            val input: InputStream = conn.inputStream
            val bytes = input.readBytes()
            input.close()
            conn.disconnect()

            val options = BitmapFactory.Options().apply {
                inJustDecodeBounds = true
            }
            BitmapFactory.decodeByteArray(bytes, 0, bytes.size, options)

            // Scale to max 800x800
            var inSampleSize = 1
            if (options.outHeight > 800 || options.outWidth > 800) {
                val halfHeight = options.outHeight / 2
                val halfWidth = options.outWidth / 2
                while ((halfHeight / inSampleSize) >= 800 && (halfWidth / inSampleSize) >= 800) {
                    inSampleSize *= 2
                }
            }

            val decodeOptions = BitmapFactory.Options().apply {
                this.inSampleSize = inSampleSize
            }
            BitmapFactory.decodeByteArray(bytes, 0, bytes.size, decodeOptions)
        } catch (e: Exception) {
            null
        }
    }
}
