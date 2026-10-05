package com.mhrgl.aipbx.data

import android.content.ContentResolver
import android.content.Context
import android.graphics.Bitmap
import android.graphics.BitmapFactory
import android.graphics.ImageDecoder
import android.graphics.Matrix
import android.media.ExifInterface
import android.net.Uri
import android.os.Build
import android.provider.OpenableColumns
import android.util.Log
import android.webkit.MimeTypeMap
import androidx.core.content.FileProvider
import java.io.File
import java.io.FileOutputStream
import kotlin.math.max

/**
 * Turns content picked in the chat into a temporary file ready to upload.
 *
 * Content used to be copied as is, and every photo was sent as ".jpg",
 * every document as ".bin":
 *  - the server does not accept the ".bin" extension, so document uploads always failed,
 *  - HEIC photos (named ".jpg") failed the server's image content check,
 *  - camera photos went out at 5-10 MB without keeping the rotation info.
 * Photos are now converted to JPEG and scaled down to MAX_DIM; documents go
 * with their real file name and extension.
 */
object ChatUploadPrep {

    private const val TAG = "ChatUploadPrep"
    private const val MAX_DIM = 1920
    private const val JPEG_QUALITY = 85

    data class Prepared(val file: File, val mimeType: String) {
        /** Deletes the temporary file and its folder after the upload. */
        fun cleanup() {
            file.delete()
            file.parentFile?.delete()
        }
    }

    /**
     * Where the camera app writes the photo. The name is fixed: Android may
     * close our activity while the camera app is open; thanks to the fixed
     * address the file is found on return without needing saved state. Every
     * new shot overwrites the previous (already uploaded) file.
     */
    fun cameraUri(context: Context): Uri {
        val dir = File(context.cacheDir, "camera").apply { mkdirs() }
        return FileProvider.getUriForFile(context, "${context.packageName}.fileprovider", File(dir, "capture.jpg"))
    }

    fun prepare(context: Context, uri: Uri, type: String): Prepared? {
        val cr = context.contentResolver
        val mime = cr.getType(uri)
        // Re-encoding a GIF loses its animation — it is sent as is.
        if (type == "image" && mime != "image/gif") {
            prepareImage(context, cr, uri)?.let { return it }
        }
        return copyRaw(context, cr, uri, mime)
    }

    private fun prepareImage(context: Context, cr: ContentResolver, uri: Uri): Prepared? {
        return try {
            val bitmap = decodeScaled(cr, uri) ?: return null
            val file = File(newTempDir(context), "photo_${System.currentTimeMillis()}.jpg")
            FileOutputStream(file).use { out ->
                bitmap.compress(Bitmap.CompressFormat.JPEG, JPEG_QUALITY, out)
            }
            bitmap.recycle()
            Prepared(file, "image/jpeg")
        } catch (e: Exception) {
            Log.w(TAG, "Image re-encode failed, falling back to raw copy", e)
            null
        }
    }

    private fun decodeScaled(cr: ContentResolver, uri: Uri): Bitmap? {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.P) {
            // ImageDecoder decodes HEIC and applies the EXIF orientation itself.
            val source = ImageDecoder.createSource(cr, uri)
            return ImageDecoder.decodeBitmap(source) { decoder, info, _ ->
                val w = info.size.width
                val h = info.size.height
                val longest = max(w, h)
                if (longest > MAX_DIM) {
                    val scale = MAX_DIM.toFloat() / longest
                    decoder.setTargetSize((w * scale).toInt(), (h * scale).toInt())
                }
                decoder.allocator = ImageDecoder.ALLOCATOR_SOFTWARE
            }
        }

        val bounds = BitmapFactory.Options().apply { inJustDecodeBounds = true }
        cr.openInputStream(uri)?.use { BitmapFactory.decodeStream(it, null, bounds) }
        if (bounds.outWidth <= 0 || bounds.outHeight <= 0) return null

        var sample = 1
        while (max(bounds.outWidth, bounds.outHeight) / (sample * 2) >= MAX_DIM) sample *= 2
        val decoded = cr.openInputStream(uri)?.use {
            BitmapFactory.decodeStream(it, null, BitmapFactory.Options().apply { inSampleSize = sample })
        } ?: return null

        val orientation = cr.openInputStream(uri)?.use {
            ExifInterface(it).getAttributeInt(ExifInterface.TAG_ORIENTATION, ExifInterface.ORIENTATION_NORMAL)
        } ?: ExifInterface.ORIENTATION_NORMAL
        val degrees = when (orientation) {
            ExifInterface.ORIENTATION_ROTATE_90 -> 90f
            ExifInterface.ORIENTATION_ROTATE_180 -> 180f
            ExifInterface.ORIENTATION_ROTATE_270 -> 270f
            else -> 0f
        }

        val longest = max(decoded.width, decoded.height)
        val scale = if (longest > MAX_DIM) MAX_DIM.toFloat() / longest else 1f
        if (degrees == 0f && scale == 1f) return decoded

        val matrix = Matrix().apply {
            postScale(scale, scale)
            postRotate(degrees)
        }
        val result = Bitmap.createBitmap(decoded, 0, 0, decoded.width, decoded.height, matrix, true)
        if (result !== decoded) decoded.recycle()
        return result
    }

    private fun copyRaw(context: Context, cr: ContentResolver, uri: Uri, mime: String?): Prepared? {
        return try {
            val file = File(newTempDir(context), displayName(cr, uri, mime))
            val copied = cr.openInputStream(uri)?.use { input ->
                FileOutputStream(file).use { output -> input.copyTo(output) }
            }
            if (copied == null) return null
            Prepared(file, mime ?: "application/octet-stream")
        } catch (e: Exception) {
            Log.w(TAG, "Copying picked content failed", e)
            null
        }
    }

    /** The server picks the file type from the extension — keep the real name and extension. */
    private fun displayName(cr: ContentResolver, uri: Uri, mime: String?): String {
        var name: String? = null
        cr.query(uri, arrayOf(OpenableColumns.DISPLAY_NAME), null, null, null)?.use { c ->
            if (c.moveToFirst()) name = c.getString(0)
        }
        var clean = (name ?: "dosya").replace(Regex("[\\\\/:*?\"<>|\\p{Cntrl}]"), "_").take(120)
        if (!clean.contains('.')) {
            val ext = mime?.let { MimeTypeMap.getSingleton().getExtensionFromMimeType(it) }
            if (ext != null) clean += ".$ext"
        }
        return clean
    }

    /** Every upload gets its own folder so two files with the same name do not collide. */
    private fun newTempDir(context: Context): File =
        File(context.cacheDir, "chat_upload/${System.nanoTime()}").apply { mkdirs() }
}
