#!/usr/bin/env python3
import os
import subprocess
from PIL import Image, ImageDraw

REPO_ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), ".."))
DESIGN_ICON = os.path.join(REPO_ROOT, "design", "icon")
ANDROID_RES = os.path.join(REPO_ROOT, "app", "android", "app", "src", "main", "res")
IOS_APPICON = os.path.join(REPO_ROOT, "app", "ios", "Runner", "Assets.xcassets", "AppIcon.appiconset")

def rsvg(svg_path, out_png, width, height):
    os.makedirs(os.path.dirname(out_png), exist_ok=True)
    subprocess.run(["rsvg-convert", "-w", str(width), "-h", str(height), svg_path, "-o", out_png], check=True)

def make_round(src_png, out_png):
    im = Image.open(src_png).convert("RGBA")
    mask = Image.new("L", im.size, 0)
    draw = ImageDraw.Draw(mask)
    draw.ellipse((0, 0, im.size[0], im.size[1]), fill=255)
    im.putalpha(mask)
    im.save(out_png)

def main():
    master_svg = os.path.join(DESIGN_ICON, "icon_master.svg")
    fg_svg = os.path.join(DESIGN_ICON, "icon_foreground.svg")
    mono_svg = os.path.join(DESIGN_ICON, "icon_monochrome.svg")
    notif_svg = os.path.join(DESIGN_ICON, "icon_notification.svg")

    assert os.path.exists(master_svg), f"Missing {master_svg}"
    assert os.path.exists(fg_svg), f"Missing {fg_svg}"
    assert os.path.exists(mono_svg), f"Missing {mono_svg}"
    assert os.path.exists(notif_svg), f"Missing {notif_svg}"

    print("1. Generating Android launcher mipmaps & adaptive icon layers...")
    densities = {
        "mdpi": {"legacy": 48, "adaptive": 108, "notif": 24},
        "hdpi": {"legacy": 72, "adaptive": 162, "notif": 36},
        "xhdpi": {"legacy": 96, "adaptive": 216, "notif": 48},
        "xxhdpi": {"legacy": 144, "adaptive": 324, "notif": 72},
        "xxxhdpi": {"legacy": 192, "adaptive": 432, "notif": 96},
    }

    for density, sizes in densities.items():
        # Legacy square / launcher
        mip_dir = os.path.join(ANDROID_RES, f"mipmap-{density}")
        legacy_png = os.path.join(mip_dir, "ic_launcher.png")
        round_png = os.path.join(mip_dir, "ic_launcher_round.png")
        fg_png = os.path.join(mip_dir, "ic_launcher_foreground.png")
        mono_png = os.path.join(mip_dir, "ic_launcher_monochrome.png")

        rsvg(master_svg, legacy_png, sizes["legacy"], sizes["legacy"])
        make_round(legacy_png, round_png)
        rsvg(fg_svg, fg_png, sizes["adaptive"], sizes["adaptive"])
        rsvg(mono_svg, mono_png, sizes["adaptive"], sizes["adaptive"])

        # Notification icon
        draw_dir = os.path.join(ANDROID_RES, f"drawable-{density}")
        notif_png = os.path.join(draw_dir, "ic_notification.png")
        rsvg(notif_svg, notif_png, sizes["notif"], sizes["notif"])

    # Adaptive Icon XML definitions in mipmap-anydpi-v26
    v26_dir = os.path.join(ANDROID_RES, "mipmap-anydpi-v26")
    os.makedirs(v26_dir, exist_ok=True)
    xml_content = """<?xml version="1.0" encoding="utf-8"?>
<adaptive-icon xmlns:android="http://schemas.android.com/apk/res/android">
    <background android:drawable="@color/ic_launcher_background"/>
    <foreground android:drawable="@mipmap/ic_launcher_foreground"/>
    <monochrome android:drawable="@mipmap/ic_launcher_monochrome"/>
</adaptive-icon>
"""
    with open(os.path.join(v26_dir, "ic_launcher.xml"), "w") as f:
        f.write(xml_content)
    with open(os.path.join(v26_dir, "ic_launcher_round.xml"), "w") as f:
        f.write(xml_content)

    print("2. Generating Splash Screen centered icon...")
    splash_icon = os.path.join(ANDROID_RES, "drawable", "ic_splash_icon.png")
    rsvg(fg_svg, splash_icon, 288, 288)

    print("3. Generating Play Store 512x512 icon...")
    playstore_design = os.path.join(DESIGN_ICON, "playstore.png")
    playstore_android = os.path.join(REPO_ROOT, "app", "android", "app", "src", "main", "playstore-icon.png")
    rsvg(master_svg, playstore_design, 512, 512)
    rsvg(master_svg, playstore_android, 512, 512)

    if os.path.exists(IOS_APPICON):
        print("4. Generating iOS App Icons in Assets.xcassets...")
        ios_specs = [
            ("Icon-App-20x20@1x.png", 20),
            ("Icon-App-20x20@2x.png", 40),
            ("Icon-App-20x20@3x.png", 60),
            ("Icon-App-29x29@1x.png", 29),
            ("Icon-App-29x29@2x.png", 58),
            ("Icon-App-29x29@3x.png", 87),
            ("Icon-App-40x40@1x.png", 40),
            ("Icon-App-40x40@2x.png", 80),
            ("Icon-App-40x40@3x.png", 120),
            ("Icon-App-60x60@2x.png", 120),
            ("Icon-App-60x60@3x.png", 180),
            ("Icon-App-76x76@1x.png", 76),
            ("Icon-App-76x76@2x.png", 152),
            ("Icon-App-83.5x83.5@2x.png", 167),
            ("Icon-App-1024x1024@1x.png", 1024),
        ]
        for filename, size in ios_specs:
            out_path = os.path.join(IOS_APPICON, filename)
            rsvg(master_svg, out_path, size, size)
            # Ensure iOS marketing 1024 has no alpha channel (RGB)
            if size == 1024:
                im = Image.open(out_path).convert("RGB")
                im.save(out_path)

    print("5. Generating Contact Sheet at 48/72/96/192 px...")
    sheet_sizes = [192, 96, 72, 48]
    cards = []
    for s in sheet_sizes:
        tmp_png = os.path.join(DESIGN_ICON, f"temp_{s}.png")
        rsvg(master_svg, tmp_png, s, s)
        icon_img = Image.open(tmp_png).convert("RGBA")
        
        # Test on 3 backgrounds: Light (#F8FAFC), Dark (#0A0E17), Amber tint (#FEF3C7)
        card_w, card_h = 240, 240
        card = Image.new("RGBA", (card_w, card_h * 3), (255, 255, 255, 255))
        draw = ImageDraw.Draw(card)
        
        # Light row
        draw.rectangle((0, 0, card_w, card_h), fill=(248, 250, 252, 255))
        card.paste(icon_img, ((card_w - s) // 2, (card_h - s) // 2), icon_img)
        
        # Dark row
        draw.rectangle((0, card_h, card_w, card_h * 2), fill=(10, 14, 23, 255))
        card.paste(icon_img, ((card_w - s) // 2, card_h + (card_h - s) // 2), icon_img)

        # Amber wallpaper row
        draw.rectangle((0, card_h * 2, card_w, card_h * 3), fill=(254, 243, 199, 255))
        card.paste(icon_img, ((card_w - s) // 2, card_h * 2 + (card_h - s) // 2), icon_img)

        cards.append(card)
        if os.path.exists(tmp_png):
            os.remove(tmp_png)

    # Combine cards horizontally
    total_w = sum(c.width for c in cards)
    total_h = cards[0].height
    contact_sheet = Image.new("RGBA", (total_w, total_h), (255, 255, 255, 255))
    x_offset = 0
    for c in cards:
        contact_sheet.paste(c, (x_offset, 0))
        x_offset += c.width
    
    contact_sheet.save(os.path.join(DESIGN_ICON, "contact_sheet.png"))
    print("Contact sheet created: design/icon/contact_sheet.png")

if __name__ == "__main__":
    main()
