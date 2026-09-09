package com.mhrgl.aipbx.ui

import android.content.Context
import android.util.AttributeSet
import android.widget.LinearLayout

/**
 * En ve boy ölçüsünü her zaman 1:1 kare yapan LinearLayout.
 * TableLayout veya diğer üst kaplar genişliği ya da yüksekliği esnetmeye
 * çalışsa bile View ölçüsünü her zaman kare tutar.
 * Bu sayede oval drawable arka planı %100 kusursuz bir daire olarak çizilir.
 */
class SquareLinearLayout @JvmOverloads constructor(
    context: Context, attrs: AttributeSet? = null, defStyle: Int = 0
) : LinearLayout(context, attrs, defStyle) {

    override fun onMeasure(widthMeasureSpec: Int, heightMeasureSpec: Int) {
        super.onMeasure(widthMeasureSpec, heightMeasureSpec)
        val size = if (measuredHeight > 0) measuredHeight else measuredWidth
        val finalSpec = MeasureSpec.makeMeasureSpec(size, MeasureSpec.EXACTLY)
        super.onMeasure(finalSpec, finalSpec)
    }
}
