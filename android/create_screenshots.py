import os
from PIL import Image, ImageDraw, ImageFont, ImageFilter

W, H = 1080, 1920

FONT_BOLD = "C:/Windows/Fonts/segoeuib.ttf"
FONT_REG = "C:/Windows/Fonts/segoeui.ttf"
FONT_EMOJI = "C:/Windows/Fonts/seguiemj.ttf"
if not os.path.exists(FONT_BOLD):
    FONT_BOLD = "C:/Windows/Fonts/arialbd.ttf"
    FONT_REG = "C:/Windows/Fonts/arial.ttf"

def draw_header_marketing(draw, title, subtitle):
    # Top marketing text
    f_title = ImageFont.truetype(FONT_BOLD, 48)
    f_sub = ImageFont.truetype(FONT_REG, 28)

    bbox_t = draw.textbbox((0, 0), title, font=f_title)
    tw = bbox_t[2] - bbox_t[0]
    draw.text(((W - tw) // 2, 80), title, font=f_title, fill=(255, 255, 255, 255))

    bbox_s = draw.textbbox((0, 0), subtitle, font=f_sub)
    sw = bbox_s[2] - bbox_s[0]
    draw.text(((W - sw) // 2, 145), subtitle, font=f_sub, fill=(56, 189, 248, 255))

def create_base_canvas():
    # Gradient from #080D1A to #0F172A to #131E35
    base = Image.new("RGBA", (W, H), (8, 13, 26, 255))
    draw = ImageDraw.Draw(base)
    for y in range(H):
        ratio = y / float(H)
        r = int(8 + (19 - 8) * ratio)
        g = int(13 + (30 - 13) * ratio)
        b = int(26 + (53 - 26) * ratio)
        draw.line([(0, y), (W, y)], fill=(r, g, b, 255))
    return base

def draw_phone_frame(base):
    # Draw a modern curved smartphone bezel starting at y=240 down to y=1840
    # Phone size: 920w x 1600h, centered
    px1, py1 = 80, 240
    px2, py2 = W - 80, 1840

    # Soft drop shadow behind phone
    shadow = Image.new("RGBA", (W, H), (0, 0, 0, 0))
    sdraw = ImageDraw.Draw(shadow)
    sdraw.rounded_rectangle([px1 - 10, py1 - 10, px2 + 10, py2 + 25], radius=54, fill=(0, 0, 0, 140))
    shadow = shadow.filter(ImageFilter.GaussianBlur(18))
    base = Image.alpha_composite(base, shadow)

    # Phone outer frame (slate border)
    pdraw = ImageDraw.Draw(base)
    pdraw.rounded_rectangle([px1, py1, px2, py2], radius=48, fill=(15, 23, 42, 255), outline=(51, 65, 85, 255), width=4)

    # Phone screen content area
    sx1, sy1 = px1 + 12, py1 + 12
    sx2, sy2 = px2 - 12, py2 - 12
    screen_rect = [sx1, sy1, sx2, sy2]
    pdraw.rounded_rectangle(screen_rect, radius=40, fill=(15, 23, 42, 255))

    # Top camera notch / speaker pill
    notch_w, notch_h = 160, 26
    nx = (W - notch_w) // 2
    pdraw.rounded_rectangle([nx, sy1 + 10, nx + notch_w, sy1 + 10 + notch_h], radius=13, fill=(30, 41, 59, 255))

    return base, screen_rect

# ==================== SCREENSHOT 1: DIALER ====================
def make_screenshot_1():
    base = create_base_canvas()
    draw = ImageDraw.Draw(base)
    draw_header_marketing(draw, "AKILLI KURUMSAL SANTRAL", "WebRTC ile Kesintisiz Dahili & Dış Hat Görüşmesi")

    base, (sx1, sy1, sx2, sy2) = draw_phone_frame(base)
    pdraw = ImageDraw.Draw(base)

    # App Header inside screen
    hy = sy1 + 60
    # Small circular logo
    logo_path = "emblem_clean.png"
    if os.path.exists(logo_path):
        logo_img = Image.open(logo_path).convert("RGBA").resize((56, 56), Image.Resampling.LANCZOS)
        # White circle back
        pdraw.ellipse([sx1 + 30, hy, sx1 + 90, hy + 60], fill=(255, 255, 255, 255))
        base.paste(logo_img, (sx1 + 32, hy + 2), logo_img)

    f_app = ImageFont.truetype(FONT_BOLD, 28)
    f_sub = ImageFont.truetype(FONT_REG, 18)
    pdraw.text((sx1 + 105, hy + 5), "AiPBX Mobile", font=f_app, fill=(255, 255, 255, 255))
    pdraw.text((sx1 + 105, hy + 35), "Dahili: 19000 (Mahir)", font=f_sub, fill=(148, 163, 184, 255))

    # Online status badge
    f_badge = ImageFont.truetype(FONT_BOLD, 15)
    badge_rect = [sx2 - 190, hy + 12, sx2 - 30, hy + 48]
    pdraw.rounded_rectangle(badge_rect, radius=18, fill=(6, 78, 59, 220), outline=(16, 185, 129, 255), width=1)
    pdraw.ellipse([sx2 - 175, hy + 26, sx2 - 165, hy + 36], fill=(52, 211, 153, 255))
    pdraw.text((sx2 - 155, hy + 20), "ÇEVRİMİÇİ", font=f_badge, fill=(52, 211, 153, 255))

    # Digits display
    dy = hy + 120
    pdraw.rounded_rectangle([sx1 + 30, dy, sx2 - 30, dy + 110], radius=24, fill=(30, 41, 59, 180), outline=(51, 65, 85, 255), width=1)
    f_digits = ImageFont.truetype(FONT_BOLD, 46)
    pdraw.text((sx1 + 60, dy + 28), "9998", font=f_digits, fill=(255, 255, 255, 255))

    # Dialpad Table
    f_num = ImageFont.truetype(FONT_BOLD, 36)
    f_let = ImageFont.truetype(FONT_REG, 14)
    btn_w, btn_h = 170, 110
    start_y = dy + 150
    gap_x = 45
    gap_y = 25
    center_sx = (sx1 + sx2) // 2

    digits_data = [
        [("1", ""), ("2", "ABC"), ("3", "DEF")],
        [("4", "GHI"), ("5", "JKL"), ("6", "MNO")],
        [("7", "PQRS"), ("8", "TUV"), ("9", "WXYZ")],
        [("*", ""), ("0", "+"), ("#", "")]
    ]

    for row_idx, row in enumerate(digits_data):
        row_y = start_y + row_idx * (btn_h + gap_y)
        for col_idx, (num, letters) in enumerate(row):
            bx = center_sx - (btn_w * 1.5 + gap_x) + col_idx * (btn_w + gap_x)
            pdraw.rounded_rectangle([bx, row_y, bx + btn_w, row_y + btn_h], radius=32, fill=(30, 41, 59, 255), outline=(71, 85, 105, 180), width=1)
            # Center text
            pdraw.text((bx + btn_w // 2 - 12, row_y + (22 if letters else 32)), num, font=f_num, fill=(255, 255, 255, 255))
            if letters:
                bbox_l = pdraw.textbbox((0, 0), letters, font=f_let)
                lw = bbox_l[2] - bbox_l[0]
                pdraw.text((bx + (btn_w - lw) // 2, row_y + 68), letters, font=f_let, fill=(148, 163, 184, 255))

    # Green Call Button
    call_y = start_y + 4 * (btn_h + gap_y) + 20
    call_r = 54
    pdraw.ellipse([center_sx - call_r, call_y, center_sx + call_r, call_y + 2 * call_r], fill=(34, 197, 94, 255))
    f_icon = ImageFont.truetype(FONT_EMOJI, 42)
    pdraw.text((center_sx - 24, call_y + 24), "📞", font=f_icon, fill=(255, 255, 255, 255))

    # Bottom Tab Bar
    tab_y = sy2 - 110
    pdraw.line([(sx1, tab_y), (sx2, tab_y)], fill=(51, 65, 85, 255), width=2)
    tabs = ["Tuşlar", "Geçmiş", "Rehber", "Ayarlar"]
    f_tab = ImageFont.truetype(FONT_BOLD, 18)
    for i, t in enumerate(tabs):
        tx = sx1 + i * (sx2 - sx1) // 4 + (sx2 - sx1) // 8
        bbox_t = pdraw.textbbox((0, 0), t, font=f_tab)
        tw = bbox_t[2] - bbox_t[0]
        col = (56, 189, 248, 255) if i == 0 else (148, 163, 184, 255)
        pdraw.text((tx - tw // 2, tab_y + 40), t, font=f_tab, fill=col)

    base.convert("RGB").save("screenshot_1_dialer.png", "PNG", quality=95)
    print("Screenshot 1 generated.")

# ==================== SCREENSHOT 2: ACTIVE CALL (TRANSFER & HOLD) ====================
def make_screenshot_2():
    base = create_base_canvas()
    draw = ImageDraw.Draw(base)
    draw_header_marketing(draw, "GELİŞMİŞ ÇAĞRI YÖNETİMİ", "Tek Dokunuşla Bekletme & Dahiliye Aktarma")

    base, (sx1, sy1, sx2, sy2) = draw_phone_frame(base)
    pdraw = ImageDraw.Draw(base)
    center_sx = (sx1 + sx2) // 2

    # Avatar
    ay = sy1 + 180
    ar = 75
    pdraw.ellipse([center_sx - ar, ay, center_sx + ar, ay + 2 * ar], fill=(255, 255, 255, 255), outline=(56, 189, 248, 255), width=4)
    logo_path = "emblem_clean.png"
    if os.path.exists(logo_path):
        logo_img = Image.open(logo_path).convert("RGBA").resize((100, 100), Image.Resampling.LANCZOS)
        base.paste(logo_img, (center_sx - 50, ay + 25), logo_img)

    # Caller Info
    f_cname = ImageFont.truetype(FONT_BOLD, 42)
    f_cstate = ImageFont.truetype(FONT_REG, 24)
    f_dur = ImageFont.truetype(FONT_BOLD, 30)

    draw_text_center(pdraw, "9998 (Dış Hat / Santral)", center_sx, ay + 180, f_cname, (255, 255, 255, 255))
    draw_text_center(pdraw, "Görüşülüyor", center_sx, ay + 240, f_cstate, (148, 163, 184, 255))
    draw_text_center(pdraw, "02:45", center_sx, ay + 285, f_dur, (56, 189, 248, 255))

    # Controls Grid
    # Row 1: Sessiz, Tuşlar, Hoparlör
    # Row 2: Beklet (Hold), Aktar (Transfer)
    btn_r = 44
    row1_y = ay + 420
    draw_call_action(pdraw, center_sx - 240, row1_y, btn_r, "🎙️", "Sessiz", False)
    draw_call_action(pdraw, center_sx, row1_y, btn_r, "🔢", "Tuşlar", False)
    draw_call_action(pdraw, center_sx + 240, row1_y, btn_r, "🔊", "Hoparlör", True)

    row2_y = row1_y + 160
    draw_call_action(pdraw, center_sx - 130, row2_y, btn_r, "⏸️", "Beklet", True)
    draw_call_action(pdraw, center_sx + 130, row2_y, btn_r, "↪️", "Aktar", False)

    # Red Hangup Button
    hangup_y = row2_y + 190
    hr = 58
    pdraw.ellipse([center_sx - hr, hangup_y, center_sx + hr, hangup_y + 2 * hr], fill=(239, 68, 68, 255))
    f_hicon = ImageFont.truetype(FONT_EMOJI, 44)
    draw_text_center(pdraw, "☎️", center_sx, hangup_y + 26, f_hicon, (255, 255, 255, 255))

    base.convert("RGB").save("screenshot_2_active_call.png", "PNG", quality=95)
    print("Screenshot 2 generated.")

# ==================== SCREENSHOT 3: IN-CALL DTMF KEYPAD ====================
def make_screenshot_3():
    base = create_base_canvas()
    draw = ImageDraw.Draw(base)
    draw_header_marketing(draw, "ARAMA ESNASINDA DTMF", "IVR & Robot Operatörleri Kolayca Tuşlayın")

    base, (sx1, sy1, sx2, sy2) = draw_phone_frame(base)
    pdraw = ImageDraw.Draw(base)
    center_sx = (sx1 + sx2) // 2

    # Compact caller info at top
    cy = sy1 + 50
    f_mini_name = ImageFont.truetype(FONT_BOLD, 28)
    f_mini_sub = ImageFont.truetype(FONT_REG, 18)
    draw_text_center(pdraw, "9998 - Görüşmede (03:12)", center_sx, cy, f_mini_name, (255, 255, 255, 255))

    # DTMF Header box (Display + Gizle button)
    dty = cy + 60
    pdraw.rounded_rectangle([sx1 + 40, dty, sx2 - 40, dty + 80], radius=20, fill=(30, 41, 59, 255), outline=(56, 189, 248, 140), width=1)
    f_dtmf_dig = ImageFont.truetype(FONT_BOLD, 34)
    pdraw.text((sx1 + 65, dty + 18), "DTMF: 1402#", font=f_dtmf_dig, fill=(56, 189, 248, 255))

    # Gizle button
    f_gizle = ImageFont.truetype(FONT_BOLD, 18)
    pdraw.rounded_rectangle([sx2 - 160, dty + 15, sx2 - 50, dty + 65], radius=14, fill=(51, 65, 85, 255))
    pdraw.text((sx2 - 130, dty + 23), "Gizle", font=f_gizle, fill=(241, 245, 249, 255))

    # DTMF Dialpad Table (clean, compact)
    f_dnum = ImageFont.truetype(FONT_BOLD, 38)
    btn_w, btn_h = 180, 100
    start_y = dty + 120
    gap_x = 40
    gap_y = 20

    table = [
        ["1", "2", "3"],
        ["4", "5", "6"],
        ["7", "8", "9"],
        ["*", "0", "#"]
    ]

    for r_idx, row in enumerate(table):
        ry = start_y + r_idx * (btn_h + gap_y)
        for c_idx, val in enumerate(row):
            bx = center_sx - (btn_w * 1.5 + gap_x) + c_idx * (btn_w + gap_x)
            pdraw.rounded_rectangle([bx, ry, bx + btn_w, ry + btn_h], radius=28, fill=(30, 41, 59, 255), outline=(71, 85, 105, 180), width=1)
            draw_text_center(pdraw, val, bx + btn_w // 2, ry + 25, f_dnum, (255, 255, 255, 255))

    # Red Hangup button at bottom of keypad
    hy = start_y + 4 * (btn_h + gap_y) + 30
    hr = 58
    pdraw.ellipse([center_sx - hr, hy, center_sx + hr, hy + 2 * hr], fill=(239, 68, 68, 255))
    f_hicon = ImageFont.truetype(FONT_EMOJI, 44)
    draw_text_center(pdraw, "☎️", center_sx, hy + 26, f_hicon, (255, 255, 255, 255))

    base.convert("RGB").save("screenshot_3_dtmf.png", "PNG", quality=95)
    print("Screenshot 3 generated.")

# ==================== SCREENSHOT 4: CONTACTS & REHBER ====================
def make_screenshot_4():
    base = create_base_canvas()
    draw = ImageDraw.Draw(base)
    draw_header_marketing(draw, "KURUMSAL REHBER & GEÇMİŞ", "Anlık Dahili Durumları ve Kolay Arama")

    base, (sx1, sy1, sx2, sy2) = draw_phone_frame(base)
    pdraw = ImageDraw.Draw(base)

    # Header
    hy = sy1 + 50
    f_head = ImageFont.truetype(FONT_BOLD, 30)
    pdraw.text((sx1 + 40, hy), "Kurumsal Rehber", font=f_head, fill=(255, 255, 255, 255))

    # Filter tabs
    fy = hy + 60
    f_tab = ImageFont.truetype(FONT_BOLD, 18)
    pdraw.rounded_rectangle([sx1 + 40, fy, sx1 + 220, fy + 48], radius=24, fill=(14, 165, 233, 255))
    pdraw.text((sx1 + 75, fy + 12), "Tüm Dahililer", font=f_tab, fill=(255, 255, 255, 255))

    pdraw.rounded_rectangle([sx1 + 235, fy, sx1 + 400, fy + 48], radius=24, fill=(30, 41, 59, 255), outline=(51, 65, 85, 255), width=1)
    pdraw.text((sx1 + 265, fy + 12), "Çevrimiçi (12)", font=f_tab, fill=(148, 163, 184, 255))

    # Contact Cards
    contacts = [
        ("Prof. Dr. Ahmet Yılmaz", "1001", "Rektörlük", "online"),
        ("Doç. Dr. Selin Demir", "1002", "Mühendislik Fakültesi", "online"),
        ("Santral Operatörü", "9998", "Dış Hat Trunk (Neco)", "busy"),
        ("Bilgi İşlem Destek", "1100", "BİDB Çağrı Merkezi", "online"),
        ("Sekreterlik", "1005", "Genel Sekreterlik", "offline"),
        ("Öğrenci İşleri", "1200", "Öğrenci Daire Bşk.", "online"),
        ("Mali Hizmetler", "1300", "Strateji Geliştirme", "offline"),
    ]

    card_y = fy + 80
    card_h = 120
    gap = 20

    f_cname = ImageFont.truetype(FONT_BOLD, 24)
    f_csub = ImageFont.truetype(FONT_REG, 17)
    f_ext = ImageFont.truetype(FONT_BOLD, 22)

    for name, ext, dept, status in contacts:
        # Card container
        pdraw.rounded_rectangle([sx1 + 30, card_y, sx2 - 30, card_y + card_h], radius=20, fill=(30, 41, 59, 200), outline=(51, 65, 85, 200), width=1)

        # Status indicator circle
        st_color = (34, 197, 94, 255) if status == "online" else ((234, 179, 8, 255) if status == "busy" else (148, 163, 184, 255))
        pdraw.ellipse([sx1 + 55, card_y + 45, sx1 + 85, card_y + 75], fill=st_color)

        # Name and Department
        pdraw.text((sx1 + 105, card_y + 26), name, font=f_cname, fill=(255, 255, 255, 255))
        pdraw.text((sx1 + 105, card_y + 64), f"{dept} • Dahili {ext}", font=f_csub, fill=(148, 163, 184, 255))

        # Quick Call Button on Right
        call_btn_r = 28
        cb_x = sx2 - 80
        cb_y = card_y + card_h // 2
        pdraw.ellipse([cb_x - call_btn_r, cb_y - call_btn_r, cb_x + call_btn_r, cb_y + call_btn_r], fill=(14, 165, 233, 255))
        f_phone_icon = ImageFont.truetype(FONT_EMOJI, 24)
        pdraw.text((cb_x - 12, cb_y - 14), "📞", font=f_phone_icon, fill=(255, 255, 255, 255))

        card_y += card_h + gap
        if card_y > sy2 - 130:
            break

    # Bottom Tab Bar
    tab_y = sy2 - 110
    pdraw.line([(sx1, tab_y), (sx2, tab_y)], fill=(51, 65, 85, 255), width=2)
    tabs = ["Tuşlar", "Geçmiş", "Rehber", "Ayarlar"]
    f_tab_nav = ImageFont.truetype(FONT_BOLD, 18)
    for i, t in enumerate(tabs):
        tx = sx1 + i * (sx2 - sx1) // 4 + (sx2 - sx1) // 8
        bbox_t = pdraw.textbbox((0, 0), t, font=f_tab_nav)
        tw = bbox_t[2] - bbox_t[0]
        col = (56, 189, 248, 255) if i == 2 else (148, 163, 184, 255)
        pdraw.text((tx - tw // 2, tab_y + 40), t, font=f_tab_nav, fill=col)

    base.convert("RGB").save("screenshot_4_contacts.png", "PNG", quality=95)
    print("Screenshot 4 generated.")

def draw_text_center(draw, text, cx, y, font, color):
    bbox = draw.textbbox((0, 0), text, font=font)
    tw = bbox[2] - bbox[0]
    draw.text((cx - tw // 2, y), text, font=font, fill=color)

def draw_call_action(draw, cx, cy, r, icon, label, active):
    bg_col = (14, 165, 233, 255) if active else (30, 41, 59, 255)
    border_col = (56, 189, 248, 255) if active else (71, 85, 105, 180)
    draw.ellipse([cx - r, cy - r, cx + r, cy + r], fill=bg_col, outline=border_col, width=2)
    f_icon = ImageFont.truetype(FONT_EMOJI, 32)
    draw_text_center(draw, icon, cx, cy - 22, f_icon, (255, 255, 255, 255))
    f_lbl = ImageFont.truetype(FONT_REG, 17)
    draw_text_center(draw, label, cx, cy + r + 12, f_lbl, (148, 163, 184, 255))

if __name__ == "__main__":
    make_screenshot_1()
    make_screenshot_2()
    make_screenshot_3()
    make_screenshot_4()
