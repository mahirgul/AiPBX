package com.mhrgl.aipbx.ui

import android.content.Context
import android.util.AttributeSet
import android.widget.LinearLayout

/**
 * A LinearLayout whose width and height are always 1:1 square.
 * Even when a TableLayout or other parent tries to stretch the width or
 * height, the view always stays square.
 * That way an oval drawable background is drawn as a perfect circle.
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
