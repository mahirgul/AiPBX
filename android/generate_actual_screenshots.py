import os
from PIL import Image, ImageDraw, ImageFont

W, H = 1080, 1920

FONT_BOLD = "C:/Windows/Fonts/segoeuib.ttf"
FONT_REG = "C:/Windows/Fonts/segoeui.ttf"
FONT_EMOJI = "C:/Windows/Fonts/seguiemj.ttf"
if not os.path.exists(FONT_BOLD):
    FONT_BOLD = "C:/Windows/Fonts/arialbd.ttf"
    FONT_REG = "C:/Windows/Fonts/arial.ttf"

def draw_status_bar(draw):
    # Standard Android Status Bar
    f_time = ImageFont.truetype(FONT_BOLD, 26)
    f_icons = ImageFont.truetype(FONT_REG, 22)
    # Time on left
    draw.text((45, 22), "12:45", font=f_time, fill=(255, 255, 255, 240))
    # Icons on right: VoLTE / WiFi / Signal / Battery
    draw.text((W - 220, 24), "VoLTE", font=f_icons, fill=(255, 255, 255, 200))
    draw.text((W - 135, 24), "📶", font=ImageFont.truetype(FONT_EMOJI, 22), fill=(255, 255, 255, 220))
    draw.text((W - 95, 24), "📶", font=ImageFont.truetype(FONT_EMOJI, 22), fill=(255, 255, 255, 220))
    # Battery outline
    bx = W - 55
    draw.rounded_rectangle([bx, 26, bx + 28, 46], radius=4, outline=(255, 255, 255, 220), width=2)
    draw.rectangle([bx + 4, 30, bx + 22, 42], fill=(255, 255, 255, 220))
    draw.rectangle([bx + 28, 33, bx + 31, 39], fill=(255, 255, 255, 220))

def draw_text_center(draw, text, cx, y, font, color):
    bbox = draw.textbbox((0, 0), text, font=font)
    tw = bbox[2] - bbox[0]
    draw.text((cx - tw // 2, y), text, font=font, fill=color)

# ==================== 1. REAL DIALER SCREEN ====================
def make_real_dialer():
    base = Image.new("RGBA", (W, H), (15, 23, 42, 255)) # #0F172A
    draw = ImageDraw.Draw(base)
    draw_status_bar(draw)

    # Top Header Bar (y: 80 to 200)
    hy = 95
    logo_path = "emblem_clean.png"
    if os.path.exists(logo_path):
        logo_img = Image.open(logo_path).convert("RGBA").resize((64, 64), Image.Resampling.LANCZOS)
        draw.ellipse([45, hy, 115, hy + 70], fill=(255, 255, 255, 255))
        base.paste(logo_img, (48, hy + 3), logo_img)

    f_title = ImageFont.truetype(FONT_BOLD, 32)
    f_sub = ImageFont.truetype(FONT_REG, 20)
    draw.text((135, hy + 6), "AiPBX Mobile", font=f_title, fill=(255, 255, 255, 255))
    draw.text((135, hy + 42), "Dahili: 19000 (Mahir)", font=f_sub, fill=(148, 163, 184, 255))

    # Online status badge on top right
    f_badge = ImageFont.truetype(FONT_BOLD, 17)
    badge_rect = [W - 230, hy + 14, W - 45, hy + 56]
    draw.rounded_rectangle(badge_rect, radius=21, fill=(6, 78, 59, 220), outline=(16, 185, 129, 255), width=1)
    draw.ellipse([W - 210, hy + 29, W - 198, hy + 41], fill=(52, 211, 153, 255))
    draw.text((W - 188, hy + 23), "ÇEVRİMİÇİ", font=f_badge, fill=(52, 211, 153, 255))

    # Divider
    draw.line([(0, hy + 85), (W, hy + 85)], fill=(30, 41, 59, 255), width=2)

    # Dialed digits display box (y: 230 to 380)
    dy = 240
    draw.rounded_rectangle([45, dy, W - 45, dy + 150], radius=28, fill=(30, 41, 59, 200), outline=(51, 65, 85, 255), width=1)
    f_digits = ImageFont.truetype(FONT_BOLD, 64)
    draw.text((80, dy + 38), "9998", font=f_digits, fill=(255, 255, 255, 255))

    # Backspace button on right of display
    draw.text((W - 140, dy + 48), "⌫", font=ImageFont.truetype(FONT_BOLD, 42), fill=(148, 163, 184, 255))

    # 4x3 Dialpad Buttons (y: 440 to 1420)
    btn_w, btn_h = 240, 150
    start_y = 440
    gap_x = 75
    gap_y = 35
    center_x = W // 2

    digits_data = [
        [("1", ""), ("2", "ABC"), ("3", "DEF")],
        [("4", "GHI"), ("5", "JKL"), ("6", "MNO")],
        [("7", "PQRS"), ("8", "TUV"), ("9", "WXYZ")],
        [("*", ""), ("0", "+"), ("#", "")]
    ]

    f_dnum = ImageFont.truetype(FONT_BOLD, 52)
    f_dlet = ImageFont.truetype(FONT_BOLD, 18)

    for r_idx, row in enumerate(digits_data):
        ry = start_y + r_idx * (btn_h + gap_y)
        for c_idx, (num, letters) in enumerate(row):
            bx = center_x - (btn_w * 1.5 + gap_x) + c_idx * (btn_w + gap_x)
            draw.rounded_rectangle([bx, ry, bx + btn_w, ry + btn_h], radius=44, fill=(30, 41, 59, 255), outline=(51, 65, 85, 255), width=2)
            draw_text_center(draw, num, bx + btn_w // 2, ry + (28 if letters else 42), f_dnum, (255, 255, 255, 255))
            if letters:
                draw_text_center(draw, letters, bx + btn_w // 2, ry + 94, f_dlet, (148, 163, 184, 255))

    # Call Button (Big Green Round Button)
    call_y = start_y + 4 * (btn_h + gap_y) + 30
    cr = 75
    draw.ellipse([center_x - cr, call_y, center_x + cr, call_y + 2 * cr], fill=(34, 197, 94, 255))
    f_phone = ImageFont.truetype(FONT_EMOJI, 56)
    draw.text((center_x - 30, call_y + 42), "📞", font=f_phone, fill=(255, 255, 255, 255))

    # Bottom Tab Navigation (y: 1770 to 1920)
    tab_y = 1770
    draw.line([(0, tab_y), (W, tab_y)], fill=(30, 41, 59, 255), width=2)
    draw.rectangle([0, tab_y, W, H], fill=(15, 23, 42, 255))

    tabs = [
        ("Tuşlar", "🔢", True),
        ("Geçmiş", "🕒", False),
        ("Rehber", "👥", False),
        ("Özellikler", "⚙️", False)
    ]
    f_tab = ImageFont.truetype(FONT_BOLD, 22)
    f_tab_ic = ImageFont.truetype(FONT_EMOJI, 32)
    tab_width = W // 4

    for i, (label, icon, active) in enumerate(tabs):
        tx = i * tab_width + tab_width // 2
        col = (56, 189, 248, 255) if active else (148, 163, 184, 255)
        draw_text_center(draw, icon, tx, tab_y + 22, f_tab_ic, col)
        draw_text_center(draw, label, tx, tab_y + 76, f_tab, col)

    base.convert("RGB").save("real_screenshot_1_dialer.png", "PNG", quality=95)
    print("Real Screenshot 1 generated.")

# ==================== 2. REAL CALL SCREEN ====================
def make_real_call():
    base = Image.new("RGBA", (W, H), (15, 23, 42, 255))
    draw = ImageDraw.Draw(base)
    draw_status_bar(draw)

    center_x = W // 2

    # Avatar area
    ay = 220
    ar = 95
    draw.ellipse([center_x - ar, ay, center_x + ar, ay + 2 * ar], fill=(255, 255, 255, 255), outline=(56, 189, 248, 255), width=5)
    logo_path = "emblem_clean.png"
    if os.path.exists(logo_path):
        logo_img = Image.open(logo_path).convert("RGBA").resize((130, 130), Image.Resampling.LANCZOS)
        base.paste(logo_img, (center_x - 65, ay + 30), logo_img)

    # Caller Details
    f_name = ImageFont.truetype(FONT_BOLD, 54)
    f_state = ImageFont.truetype(FONT_REG, 30)
    f_dur = ImageFont.truetype(FONT_BOLD, 38)

    draw_text_center(draw, "9998", center_x, ay + 230, f_name, (255, 255, 255, 255))
    draw_text_center(draw, "Görüşülüyor", center_x, ay + 305, f_state, (148, 163, 184, 255))
    draw_text_center(draw, "02:45", center_x, ay + 360, f_dur, (56, 189, 248, 255))

    # Controls - Row 1 (Mute, Keypad, Speaker)
    row1_y = ay + 560
    btn_r = 58
    draw_action_circle(draw, center_x - 300, row1_y, btn_r, "🎙️", "Sessiz", False)
    draw_action_circle(draw, center_x, row1_y, btn_r, "🔢", "Tuşlar", False)
    draw_action_circle(draw, center_x + 300, row1_y, btn_r, "🔊", "Hoparlör", True)

    # Controls - Row 2 (Hold, Transfer)
    row2_y = row1_y + 220
    draw_action_circle(draw, center_x - 170, row2_y, btn_r, "⏸️", "Beklet", False)
    draw_action_circle(draw, center_x + 170, row2_y, btn_r, "↪️", "Aktar", False)

    # Red Hangup Button
    hang_y = row2_y + 260
    hr = 80
    draw.ellipse([center_x - hr, hang_y, center_x + hr, hang_y + 2 * hr], fill=(239, 68, 68, 255))
    f_hang = ImageFont.truetype(FONT_EMOJI, 58)
    draw_text_center(draw, "☎️", center_x, hang_y + 44, f_hang, (255, 255, 255, 255))

    base.convert("RGB").save("real_screenshot_2_call.png", "PNG", quality=95)
    print("Real Screenshot 2 generated.")

# ==================== 3. REAL CALL WITH DTMF OPEN ====================
def make_real_dtmf():
    base = Image.new("RGBA", (W, H), (15, 23, 42, 255))
    draw = ImageDraw.Draw(base)
    draw_status_bar(draw)
    center_x = W // 2

    # Top Caller info
    cy = 130
    f_cname = ImageFont.truetype(FONT_BOLD, 40)
    f_cstate = ImageFont.truetype(FONT_REG, 24)
    draw_text_center(draw, "9998 (Görüşmede - 03:12)", center_x, cy, f_cname, (255, 255, 255, 255))

    # DTMF Header Box
    dty = cy + 90
    draw.rounded_rectangle([50, dty, W - 50, dty + 110], radius=24, fill=(30, 41, 59, 255), outline=(56, 189, 248, 160), width=2)
    f_dig = ImageFont.truetype(FONT_BOLD, 46)
    draw.text((85, dty + 26), "DTMF: 1402#", font=f_dig, fill=(56, 189, 248, 255))

    # Gizle Button
    draw.rounded_rectangle([W - 230, dty + 20, W - 80, dty + 90], radius=18, fill=(51, 65, 85, 255))
    f_giz = ImageFont.truetype(FONT_BOLD, 24)
    draw_text_center(draw, "Gizle ✖", W - 155, dty + 34, f_giz, (241, 245, 249, 255))

    # 4x3 DTMF Grid
    btn_w, btn_h = 240, 150
    start_y = dty + 160
    gap_x = 75
    gap_y = 35

    table = [
        ["1", "2", "3"],
        ["4", "5", "6"],
        ["7", "8", "9"],
        ["*", "0", "#"]
    ]
    f_dnum = ImageFont.truetype(FONT_BOLD, 54)

    for r_idx, row in enumerate(table):
        ry = start_y + r_idx * (btn_h + gap_y)
        for c_idx, val in enumerate(row):
            bx = center_x - (btn_w * 1.5 + gap_x) + c_idx * (btn_w + gap_x)
            draw.rounded_rectangle([bx, ry, bx + btn_w, ry + btn_h], radius=44, fill=(30, 41, 59, 255), outline=(51, 65, 85, 255), width=2)
            draw_text_center(draw, val, bx + btn_w // 2, ry + 36, f_dnum, (255, 255, 255, 255))

    # Red Hangup Button at bottom
    hy = start_y + 4 * (btn_h + gap_y) + 50
    hr = 80
    draw.ellipse([center_x - hr, hy, center_x + hr, hy + 2 * hr], fill=(239, 68, 68, 255))
    f_hang = ImageFont.truetype(FONT_EMOJI, 58)
    draw_text_center(draw, "☎️", center_x, hy + 44, f_hang, (255, 255, 255, 255))

    base.convert("RGB").save("real_screenshot_3_dtmf.png", "PNG", quality=95)
    print("Real Screenshot 3 generated.")

# ==================== 4. REAL CONTACTS / REHBER SCREEN ====================
def make_real_contacts():
    base = Image.new("RGBA", (W, H), (15, 23, 42, 255))
    draw = ImageDraw.Draw(base)
    draw_status_bar(draw)

    # Header
    hy = 90
    f_head = ImageFont.truetype(FONT_BOLD, 40)
    draw.text((50, hy), "Kurumsal Rehber", font=f_head, fill=(255, 255, 255, 255))

    # Search Bar
    sy = hy + 80
    draw.rounded_rectangle([45, sy, W - 45, sy + 90], radius=24, fill=(30, 41, 59, 255), outline=(51, 65, 85, 255), width=1)
    f_search = ImageFont.truetype(FONT_REG, 26)
    draw.text((80, sy + 26), "🔍  Dahili veya isim ara...", font=f_search, fill=(148, 163, 184, 255))

    # Filter Tabs
    fy = sy + 115
    f_tab_btn = ImageFont.truetype(FONT_BOLD, 22)
    draw.rounded_rectangle([45, fy, 320, fy + 65], radius=32, fill=(14, 165, 233, 255))
    draw_text_center(draw, "Kurumsal (38)", 182, fy + 16, f_tab_btn, (255, 255, 255, 255))

    draw.rounded_rectangle([340, fy, 600, fy + 65], radius=32, fill=(30, 41, 59, 255), outline=(51, 65, 85, 255), width=1)
    draw_text_center(draw, "Cihaz Rehberi", 470, fy + 16, f_tab_btn, (148, 163, 184, 255))

    # Contact Cards
    contacts = [
        ("Prof. Dr. Ahmet Yılmaz", "1001", "Rektörlük", "online"),
        ("Doç. Dr. Selin Demir", "1002", "Mühendislik Fakültesi", "online"),
        ("Santral Operatörü", "9998", "Dış Hat Trunk", "busy"),
        ("Bilgi İşlem Daire Bşk.", "1100", "BİDB Çağrı Merkezi", "online"),
        ("Genel Sekreterlik", "1005", "Yönetim Kurulu", "offline"),
        ("Öğrenci İşleri D.B.", "1200", "Öğrenci Hizmetleri", "online"),
        ("Strateji Geliştirme", "1300", "Mali Hizmetler", "offline"),
    ]

    card_y = fy + 100
    card_h = 145
    gap = 22

    f_cname = ImageFont.truetype(FONT_BOLD, 28)
    f_csub = ImageFont.truetype(FONT_REG, 20)

    for name, ext, dept, status in contacts:
        draw.rounded_rectangle([45, card_y, W - 45, card_y + card_h], radius=24, fill=(30, 41, 59, 220), outline=(51, 65, 85, 200), width=1)

        # Status indicator
        st_color = (34, 197, 94, 255) if status == "online" else ((234, 179, 8, 255) if status == "busy" else (148, 163, 184, 255))
        draw.ellipse([80, card_y + 55, 115, card_y + 90], fill=st_color)

        draw.text((140, card_y + 32), name, font=f_cname, fill=(255, 255, 255, 255))
        draw.text((140, card_y + 78), f"{dept} • Dahili: {ext}", font=f_csub, fill=(148, 163, 184, 255))

        # Quick Call Button
        cb_x = W - 115
        cb_y = card_y + card_h // 2
        cr = 38
        draw.ellipse([cb_x - cr, cb_y - cr, cb_x + cr, cb_y + cr], fill=(14, 165, 233, 255))
        f_ph = ImageFont.truetype(FONT_EMOJI, 32)
        draw.text((cb_x - 17, cb_y - 20), "📞", font=f_ph, fill=(255, 255, 255, 255))

        card_y += card_h + gap
        if card_y > 1700:
            break

    # Bottom Tab Navigation
    tab_y = 1770
    draw.line([(0, tab_y), (W, tab_y)], fill=(30, 41, 59, 255), width=2)
    draw.rectangle([0, tab_y, W, H], fill=(15, 23, 42, 255))

    tabs = [
        ("Tuşlar", "🔢", False),
        ("Geçmiş", "🕒", False),
        ("Rehber", "👥", True),
        ("Özellikler", "⚙️", False)
    ]
    f_tab = ImageFont.truetype(FONT_BOLD, 22)
    f_tab_ic = ImageFont.truetype(FONT_EMOJI, 32)
    tab_width = W // 4

    for i, (label, icon, active) in enumerate(tabs):
        tx = i * tab_width + tab_width // 2
        col = (56, 189, 248, 255) if active else (148, 163, 184, 255)
        draw_text_center(draw, icon, tx, tab_y + 22, f_tab_ic, col)
        draw_text_center(draw, label, tx, tab_y + 76, f_tab, col)

    base.convert("RGB").save("real_screenshot_4_contacts.png", "PNG", quality=95)
    print("Real Screenshot 4 generated.")

# ==================== 5. REAL LOGIN SCREEN ====================
def make_real_login():
    base = Image.new("RGBA", (W, H), (15, 23, 42, 255))
    draw = ImageDraw.Draw(base)
    draw_status_bar(draw)
    center_x = W // 2

    # Top Brand Logo
    ly = 240
    lr = 95
    draw.ellipse([center_x - lr, ly, center_x + lr, ly + 2 * lr], fill=(255, 255, 255, 255), outline=(56, 189, 248, 255), width=4)
    logo_path = "emblem_clean.png"
    if os.path.exists(logo_path):
        logo_img = Image.open(logo_path).convert("RGBA").resize((130, 130), Image.Resampling.LANCZOS)
        base.paste(logo_img, (center_x - 65, ly + 30), logo_img)

    f_title = ImageFont.truetype(FONT_BOLD, 52)
    f_sub = ImageFont.truetype(FONT_REG, 26)
    draw_text_center(draw, "AiPBX Mobile", center_x, ly + 225, f_title, (255, 255, 255, 255))
    draw_text_center(draw, "Kurumsal Akıllı IP Santral İletişim Sistemi", center_x, ly + 295, f_sub, (148, 163, 184, 255))

    # Form Box
    form_y = ly + 400
    f_lbl = ImageFont.truetype(FONT_BOLD, 22)
    f_inp = ImageFont.truetype(FONT_REG, 28)

    # Username Field
    draw.text((60, form_y), "Kullanıcı Adı veya Dahili No", font=f_lbl, fill=(203, 213, 225, 255))
    draw.rounded_rectangle([50, form_y + 35, W - 50, form_y + 130], radius=20, fill=(30, 41, 59, 255), outline=(51, 65, 85, 255), width=2)
    draw.text((80, form_y + 60), "19000", font=f_inp, fill=(255, 255, 255, 255))

    # Password Field
    pass_y = form_y + 170
    draw.text((60, pass_y), "Şifre", font=f_lbl, fill=(203, 213, 225, 255))
    draw.rounded_rectangle([50, pass_y + 35, W - 50, pass_y + 130], radius=20, fill=(30, 41, 59, 255), outline=(51, 65, 85, 255), width=2)
    draw.text((80, pass_y + 64), "● ● ● ● ● ● ● ●", font=f_inp, fill=(255, 255, 255, 255))

    # Login Button
    btn_y = pass_y + 200
    draw.rounded_rectangle([50, btn_y, W - 50, btn_y + 115], radius=24, fill=(14, 165, 233, 255))
    f_btn = ImageFont.truetype(FONT_BOLD, 32)
    draw_text_center(draw, "Giriş Yap", center_x, btn_y + 35, f_btn, (255, 255, 255, 255))

    # Server Info at bottom
    f_srv = ImageFont.truetype(FONT_REG, 20)
    draw_text_center(draw, "Bağlı Sunucu: https://santral.aipbx.org", center_x, H - 120, f_srv, (100, 116, 139, 255))

    base.convert("RGB").save("real_screenshot_5_login.png", "PNG", quality=95)
    print("Real Screenshot 5 generated.")

def draw_action_circle(draw, cx, cy, r, icon, label, active):
    bg_col = (14, 165, 233, 255) if active else (30, 41, 59, 255)
    border_col = (56, 189, 248, 255) if active else (51, 65, 85, 255)
    draw.ellipse([cx - r, cy - r, cx + r, cy + r], fill=bg_col, outline=border_col, width=3)
    f_icon = ImageFont.truetype(FONT_EMOJI, 46)
    draw_text_center(draw, icon, cx, cy - 28, f_icon, (255, 255, 255, 255))
    f_lbl = ImageFont.truetype(FONT_REG, 22)
    draw_text_center(draw, label, cx, cy + r + 18, f_lbl, (148, 163, 184, 255))

if __name__ == "__main__":
    make_real_dialer()
    make_real_call()
    make_real_dtmf()
    make_real_contacts()
    make_real_login()
