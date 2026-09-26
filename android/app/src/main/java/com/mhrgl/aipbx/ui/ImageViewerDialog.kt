package com.mhrgl.aipbx.ui

import android.annotation.SuppressLint
import android.app.Dialog
import android.content.Context
import android.graphics.Color
import android.graphics.Matrix
import android.graphics.RectF
import android.view.GestureDetector
import android.view.Gravity
import android.view.MotionEvent
import android.view.ScaleGestureDetector
import android.view.ViewGroup
import android.widget.FrameLayout
import android.widget.ImageView
import android.widget.ProgressBar
import android.widget.TextView
import com.mhrgl.aipbx.data.SimpleImageLoader

/**
 * Sohbet fotoğrafını uygulama içinde tam ekran gösterir. Önceden fotoğraf
 * harici tarayıcıda (token URL'de) açılıyordu. Yakınlaştırma: iki parmak,
 * çift dokunuş; büyütülmemişken tek dokunuş veya geri tuşu kapatır.
 */
object ImageViewerDialog {

    private const val MAX_DIM = 2048

    fun show(context: Context, imageUrl: String, authToken: String?) {
        val dialog = Dialog(context, android.R.style.Theme_Black_NoTitleBar_Fullscreen)
        val root = FrameLayout(context).apply { setBackgroundColor(Color.BLACK) }

        val image = ZoomImageView(context) { dialog.dismiss() }
        root.addView(image, FrameLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.MATCH_PARENT))

        val progress = ProgressBar(context)
        root.addView(progress, FrameLayout.LayoutParams(ViewGroup.LayoutParams.WRAP_CONTENT, ViewGroup.LayoutParams.WRAP_CONTENT, Gravity.CENTER))

        val error = TextView(context).apply {
            text = "Fotoğraf yüklenemedi"
            setTextColor(Color.WHITE)
            visibility = android.view.View.GONE
        }
        root.addView(error, FrameLayout.LayoutParams(ViewGroup.LayoutParams.WRAP_CONTENT, ViewGroup.LayoutParams.WRAP_CONTENT, Gravity.CENTER))

        dialog.setContentView(root)
        dialog.show()

        SimpleImageLoader.load(imageUrl, image, authToken, maxDim = MAX_DIM) { ok ->
            progress.visibility = android.view.View.GONE
            if (ok) image.resetZoom() else error.visibility = android.view.View.VISIBLE
        }
    }

    @SuppressLint("ViewConstructor", "ClickableViewAccessibility")
    private class ZoomImageView(context: Context, private val onDismiss: () -> Unit) : ImageView(context) {

        private val baseMatrix = Matrix()
        private val zoomMatrix = Matrix()
        private val drawMatrix = Matrix()
        private var zoom = 1f

        private val scaleDetector = ScaleGestureDetector(context, object : ScaleGestureDetector.SimpleOnScaleGestureListener() {
            override fun onScale(d: ScaleGestureDetector): Boolean {
                val target = (zoom * d.scaleFactor).coerceIn(1f, 5f)
                val factor = target / zoom
                zoom = target
                zoomMatrix.postScale(factor, factor, d.focusX, d.focusY)
                apply()
                return true
            }
        })

        private val gestureDetector = GestureDetector(context, object : GestureDetector.SimpleOnGestureListener() {
            override fun onSingleTapConfirmed(e: MotionEvent): Boolean {
                if (zoom <= 1.01f) onDismiss()
                return true
            }

            override fun onDoubleTap(e: MotionEvent): Boolean {
                if (zoom > 1.01f) {
                    resetZoom()
                } else {
                    zoom = 2.5f
                    zoomMatrix.setScale(zoom, zoom, e.x, e.y)
                    apply()
                }
                return true
            }

            override fun onScroll(e1: MotionEvent?, e2: MotionEvent, dx: Float, dy: Float): Boolean {
                if (zoom <= 1.01f) return false
                zoomMatrix.postTranslate(-dx, -dy)
                apply()
                return true
            }
        })

        init {
            scaleType = ScaleType.MATRIX
            setOnTouchListener { _, event ->
                scaleDetector.onTouchEvent(event)
                gestureDetector.onTouchEvent(event)
                true
            }
        }

        override fun onSizeChanged(w: Int, h: Int, oldw: Int, oldh: Int) {
            super.onSizeChanged(w, h, oldw, oldh)
            resetZoom()
        }

        /** Görseli ekrana sığdırıp ortalar, yakınlaştırmayı sıfırlar. */
        fun resetZoom() {
            val d = drawable ?: return
            if (width == 0 || height == 0) return
            val src = RectF(0f, 0f, d.intrinsicWidth.toFloat(), d.intrinsicHeight.toFloat())
            val dst = RectF(0f, 0f, width.toFloat(), height.toFloat())
            baseMatrix.setRectToRect(src, dst, Matrix.ScaleToFit.CENTER)
            zoomMatrix.reset()
            zoom = 1f
            apply()
        }

        /** Sürüklerken görselin kenarları ekranın içine kaçmasın. */
        private fun apply() {
            val d = drawable ?: return
            drawMatrix.set(baseMatrix)
            drawMatrix.postConcat(zoomMatrix)
            val r = RectF(0f, 0f, d.intrinsicWidth.toFloat(), d.intrinsicHeight.toFloat())
            drawMatrix.mapRect(r)
            var dx = 0f
            var dy = 0f
            if (r.width() <= width) dx = (width - r.width()) / 2 - r.left
            else if (r.left > 0) dx = -r.left
            else if (r.right < width) dx = width - r.right
            if (r.height() <= height) dy = (height - r.height()) / 2 - r.top
            else if (r.top > 0) dy = -r.top
            else if (r.bottom < height) dy = height - r.bottom
            if (dx != 0f || dy != 0f) {
                zoomMatrix.postTranslate(dx, dy)
                drawMatrix.postTranslate(dx, dy)
            }
            imageMatrix = drawMatrix
        }
    }
}
