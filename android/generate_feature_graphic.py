import os
import math
from PIL import Image, ImageDraw, ImageFont, ImageFilter

def create_feature_graphic(output_path="playstore_feature_graphic_1024x500.png"):
    width = 1024
    height = 500

    # 1. Base gradient image
    # Deep midnight slate gradient: #080D1A to #0F172A to #1E293B
    base = Image.new("RGBA", (width, height), (8, 13, 26, 255))
    draw = ImageDraw.Draw(base)

    for y in range(height):
        ratio = y / float(height)
        # Gradient from (8, 13, 26) to (15, 23, 42)
        r = int(8 + (15 - 8) * ratio)
        g = int(13 + (23 - 13) * ratio)
        b = int(26 + (42 - 26) * ratio)
        draw.line([(0, y), (width, y)], fill=(r, g, b, 255))

    # 2. Add subtle radial background glow
    glow_layer = Image.new("RGBA", (width, height), (0, 0, 0, 0))
    glow_draw = ImageDraw.Draw(glow_layer)

    # Cyan/Sky glow behind the left badge
    center_x, center_y = 260, 250
    for rad in range(320, 20, -10):
        alpha = int(45 * (1.0 - (rad / 320.0)))
        glow_draw.ellipse(
            [center_x - rad, center_y - rad, center_x + rad, center_y + rad],
            fill=(14, 165, 233, alpha)
        )

    # Secondary softer blue glow on bottom-right
    br_x, br_y = 850, 380
    for rad in range(250, 20, -10):
        alpha = int(25 * (1.0 - (rad / 250.0)))
        glow_draw.ellipse(
            [br_x - rad, br_y - rad, br_x + rad, br_y + rad],
            fill=(37, 99, 235, alpha)
        )

    base = Image.alpha_composite(base, glow_layer)

    # 3. Add decorative subtle grid or tech circles in background
    decor_layer = Image.new("RGBA", (width, height), (0, 0, 0, 0))
    decor_draw = ImageDraw.Draw(decor_layer)
    for r in [170, 210, 250]:
        decor_draw.ellipse(
            [center_x - r, center_y - r, center_x + r, center_y + r],
            outline=(56, 189, 248, 25),
            width=1
        )
    base = Image.alpha_composite(base, decor_layer)

    # 4. White circular badge for the logo
    badge_r = 125
    badge_center = (center_x, center_y)
    
    # Drop shadow for badge
    shadow_layer = Image.new("RGBA", (width, height), (0, 0, 0, 0))
    shadow_draw = ImageDraw.Draw(shadow_layer)
    shadow_draw.ellipse(
        [badge_center[0] - badge_r - 8, badge_center[1] - badge_r + 8,
         badge_center[0] + badge_r + 8, badge_center[1] + badge_r + 24],
        fill=(0, 0, 0, 90)
    )
    shadow_layer = shadow_layer.filter(ImageFilter.GaussianBlur(14))
    base = Image.alpha_composite(base, shadow_layer)

    # Crisp white circle badge
    badge_layer = Image.new("RGBA", (width, height), (0, 0, 0, 0))
    badge_draw = ImageDraw.Draw(badge_layer)
    badge_draw.ellipse(
        [badge_center[0] - badge_r, badge_center[1] - badge_r,
         badge_center[0] + badge_r, badge_center[1] + badge_r],
        fill=(255, 255, 255, 255),
        outline=(226, 232, 240, 255),
        width=3
    )
    base = Image.alpha_composite(base, badge_layer)

    # 5. Place the official logo inside the badge
    logo_path = "emblem_clean.png"
    if os.path.exists(logo_path):
        logo_img = Image.open(logo_path).convert("RGBA")
        # Target diameter inside badge: ~175px
        target_size = 175
        orig_w, orig_h = logo_img.size
        scale = min(target_size / orig_w, target_size / orig_h)
        new_w = int(orig_w * scale)
        new_h = int(orig_h * scale)
        resized_logo = logo_img.resize((new_w, new_h), Image.Resampling.LANCZOS)
        logo_pos = (badge_center[0] - new_w // 2, badge_center[1] - new_h // 2)
        base.paste(resized_logo, logo_pos, resized_logo)

    # 6. Typography
    draw_final = ImageDraw.Draw(base)

    font_title_path = "C:/Windows/Fonts/segoeuib.ttf"
    font_sub_path = "C:/Windows/Fonts/segoeui.ttf"
    if not os.path.exists(font_title_path):
        font_title_path = "C:/Windows/Fonts/arialbd.ttf"
        font_sub_path = "C:/Windows/Fonts/arial.ttf"

    font_brand = ImageFont.truetype(font_title_path, 66)
    font_sub = ImageFont.truetype(font_title_path, 23)
    font_tag = ImageFont.truetype(font_sub_path, 16)
    font_badge = ImageFont.truetype(font_title_path, 13)

    text_x = 445
    text_y = 125

    # Brand Title: "AiPBX"
    draw_final.text((text_x, text_y), "AiPBX", font=font_brand, fill=(255, 255, 255, 255))

    # Version / Tag pill next to title
    pill_x = text_x + 225
    pill_y = text_y + 22
    pill_w = 88
    pill_h = 28
    pill_shape = [pill_x, pill_y, pill_x + pill_w, pill_y + pill_h]
    draw_final.rounded_rectangle(pill_shape, radius=14, fill=(14, 165, 233, 40), outline=(56, 189, 248, 180), width=1)
    draw_final.text((pill_x + 18, pill_y + 5), "MOBILE", font=font_badge, fill=(56, 189, 248, 255))

    # Subtitle: "Kurumsal Akıllı Santral & İletişim"
    sub_y = text_y + 88
    draw_final.text((text_x, sub_y), "Kurumsal Akıllı Santral & Softphone", font=font_sub, fill=(56, 189, 248, 255))

    # Description text
    desc_y = sub_y + 40
    draw_final.text(
        (text_x, desc_y),
        "Dahili görüşmeler, dış hat aramaları, transfer ve DTMF desteğiyle\nkesintisiz kurumsal haberleşme.",
        font=font_tag,
        fill=(148, 163, 184, 255)
    )

    # Feature badges / pills
    badges = [
        ("HD Ses (WebRTC)", (14, 165, 233)),
        ("Çağrı Aktarma & Bekletme", (99, 102, 241)),
        ("Gelişmiş DTMF", (16, 185, 129))
    ]

    badge_y = desc_y + 75
    cur_x = text_x
    for label, col in badges:
        # Measure text
        bbox = draw_final.textbbox((0, 0), label, font=font_badge)
        tw = bbox[2] - bbox[0]
        th = bbox[3] - bbox[1]
        pw = tw + 24
        ph = 32
        
        # Draw pill
        draw_final.rounded_rectangle(
            [cur_x, badge_y, cur_x + pw, badge_y + ph],
            radius=16,
            fill=(30, 41, 59, 220),
            outline=(col[0], col[1], col[2], 140),
            width=1
        )
        # Draw small colored dot
        dot_r = 3
        draw_final.ellipse(
            [cur_x + 10 - dot_r, badge_y + ph // 2 - dot_r,
             cur_x + 10 + dot_r, badge_y + ph // 2 + dot_r],
            fill=(col[0], col[1], col[2], 255)
        )
        draw_final.text((cur_x + 18, badge_y + 7), label, font=font_badge, fill=(241, 245, 249, 255))
        cur_x += pw + 12

    # Save as PNG
    final_rgb = base.convert("RGB")
    final_rgb.save(output_path, "PNG", quality=100, optimize=True)
    print(f"Feature graphic saved to {output_path} (Size: {final_rgb.size})")

if __name__ == "__main__":
    create_feature_graphic()
