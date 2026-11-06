#!/usr/bin/env python3
"""
Generate Google Play 1024x500 Feature Graphic
Outputs:
- store/feature_graphic.png (1024x500 PNG)
- store/feature_graphic.svg (1024x500 SVG)
"""

import os
from PIL import Image, ImageDraw, ImageFont

WIDTH = 1024
HEIGHT = 500

def create_svg():
    svg_content = f'''<svg xmlns="http://www.w3.org/2000/svg" width="{WIDTH}" height="{HEIGHT}" viewBox="0 0 {WIDTH} {HEIGHT}">
  <defs>
    <linearGradient id="bgGrad" x1="0%" y1="0%" x2="100%" y2="100%">
      <stop offset="0%" stop-color="#0F766E" />
      <stop offset="60%" stop-color="#115E59" />
      <stop offset="100%" stop-color="#042F2E" />
    </linearGradient>
    <linearGradient id="cardGrad" x1="0%" y1="0%" x2="0%" y2="100%">
      <stop offset="0%" stop-color="#FFFFFF" stop-opacity="0.12" />
      <stop offset="100%" stop-color="#FFFFFF" stop-opacity="0.04" />
    </linearGradient>
    <linearGradient id="goldGrad" x1="0%" y1="0%" x2="100%" y2="100%">
      <stop offset="0%" stop-color="#F59E0B" />
      <stop offset="100%" stop-color="#D97706" />
    </linearGradient>
  </defs>

  <!-- Background -->
  <rect width="{WIDTH}" height="{HEIGHT}" fill="url(#bgGrad)" />

  <!-- Subtle geometric circles in background -->
  <circle cx="950" cy="80" r="260" fill="#FFFFFF" fill-opacity="0.03" />
  <circle cx="850" cy="400" r="320" fill="#F59E0B" fill-opacity="0.04" />
  <circle cx="80" cy="450" r="200" fill="#FFFFFF" fill-opacity="0.02" />

  <!-- Brand Card on Left / Center -->
  <g transform="translate(70, 75)">
    <!-- Category Badge -->
    <rect x="0" y="0" width="165" height="32" rx="16" fill="url(#goldGrad)" />
    <text x="82" y="21" font-family="sans-serif" font-weight="bold" font-size="13" fill="#FFFFFF" text-anchor="middle" letter-spacing="1">RETAIL POS &amp; STOCK</text>

    <!-- Main Title -->
    <text x="0" y="95" font-family="sans-serif" font-weight="900" font-size="62" fill="#FFFFFF" letter-spacing="-1">Inventory</text>
    <text x="310" y="95" font-family="sans-serif" font-weight="700" font-size="44" fill="#38BDF8">/ የኔ ሱቅ</text>

    <!-- Subtitle -->
    <text x="0" y="145" font-family="sans-serif" font-weight="500" font-size="22" fill="#E2E8F0">
      Simple, Offline Inventory, POS &amp; Credit Ledger
    </text>
    <text x="0" y="178" font-family="sans-serif" font-weight="400" font-size="18" fill="#94A3B8">
      ለኢትዮጵያ ቸርቻሪ ሱቆች የተዘጋጀ ከመስመር ውጭ የሚሰራ
    </text>

    <!-- Feature Pills -->
    <g transform="translate(0, 225)">
      <!-- Pill 1 -->
      <rect x="0" y="0" width="155" height="38" rx="19" fill="#FFFFFF" fill-opacity="0.15" stroke="#FFFFFF" stroke-opacity="0.2" />
      <text x="77" y="24" font-family="sans-serif" font-size="14" font-weight="bold" fill="#FFFFFF" text-anchor="middle">⚡ 100% OFFLINE</text>

      <!-- Pill 2 -->
      <rect x="170" y="0" width="165" height="38" rx="19" fill="#FFFFFF" fill-opacity="0.15" stroke="#FFFFFF" stroke-opacity="0.2" />
      <text x="252" y="24" font-family="sans-serif" font-size="14" font-weight="bold" fill="#FFFFFF" text-anchor="middle">📒 DUBE / ዱቤ LEDGER</text>

      <!-- Pill 3 -->
      <rect x="350" y="0" width="190" height="38" rx="19" fill="#FFFFFF" fill-opacity="0.15" stroke="#FFFFFF" stroke-opacity="0.2" />
      <text x="445" y="24" font-family="sans-serif" font-size="14" font-weight="bold" fill="#FFFFFF" text-anchor="middle">📱 TELEBIRR &amp; CASH</text>
    </g>
  </g>

  <!-- Visual Card Graphic on Right -->
  <g transform="translate(710, 80)">
    <!-- App Mockup Floating Container -->
    <rect x="0" y="0" width="240" height="340" rx="24" fill="url(#cardGrad)" stroke="#FFFFFF" stroke-opacity="0.25" stroke-width="1.5" />
    
    <!-- Mini App Icon in Card -->
    <rect x="75" y="45" width="90" height="90" rx="20" fill="#0F766E" stroke="#38BDF8" stroke-width="2" />
    <!-- Shopping bag / box icon -->
    <path d="M102 78 L120 62 L138 78 L138 108 L102 108 Z" fill="none" stroke="#FFFFFF" stroke-width="3.5" stroke-linejoin="round" />
    <line x1="120" y1="62" x2="120" y2="108" stroke="#FFFFFF" stroke-width="3" />
    <path d="M107 78 L133 78" stroke="#F59E0B" stroke-width="3" />

    <text x="120" y="175" font-family="sans-serif" font-weight="bold" font-size="18" fill="#FFFFFF" text-anchor="middle">Inventory POS</text>
    <text x="120" y="200" font-family="sans-serif" font-size="12" fill="#38BDF8" text-anchor="middle">Fast • Private • Safe</text>

    <!-- Simulated Mini POS Ticker -->
    <rect x="25" y="235" width="190" height="42" rx="10" fill="#0F172A" fill-opacity="0.6" />
    <text x="38" y="261" font-family="monospace" font-size="13" font-weight="bold" fill="#22C55E">ETB 3,450.00</text>
    <text x="195" y="261" font-family="sans-serif" font-size="11" fill="#F59E0B" text-anchor="end">PAID ✓</text>

    <rect x="25" y="285" width="190" height="28" rx="8" fill="#F59E0B" />
    <text x="120" y="303" font-family="sans-serif" font-size="12" font-weight="bold" fill="#FFFFFF" text-anchor="middle">NEW SALE (ሽያጭ)</text>
  </g>
</svg>'''
    with open('store/feature_graphic.svg', 'w', encoding='utf-8') as f:
        f.write(svg_content)
    print("Created store/feature_graphic.svg")

def create_png():
    img = Image.new('RGB', (WIDTH, HEIGHT), color='#0F766E')
    draw = ImageDraw.Draw(img)

    # Gradient background
    for y in range(HEIGHT):
        ratio = y / HEIGHT
        r = int(15 * (1 - ratio) + 4 * ratio)
        g = int(118 * (1 - ratio) + 47 * ratio)
        b = int(110 * (1 - ratio) + 46 * ratio)
        draw.line([(0, y), (WIDTH, y)], fill=(r, g, b))

    # Font handling
    font_bold_path = "app/assets/fonts/NotoSans-Bold.ttf"
    font_reg_path = "app/assets/fonts/NotoSans-Regular.ttf"

    font_title = ImageFont.truetype(font_bold_path, 58) if os.path.exists(font_bold_path) else ImageFont.load_default()
    font_badge = ImageFont.truetype(font_bold_path, 13) if os.path.exists(font_bold_path) else ImageFont.load_default()
    font_sub = ImageFont.truetype(font_reg_path, 21) if os.path.exists(font_reg_path) else ImageFont.load_default()
    font_pills = ImageFont.truetype(font_bold_path, 14) if os.path.exists(font_bold_path) else ImageFont.load_default()

    # Draw Badge
    draw.rounded_rectangle([70, 75, 235, 107], radius=16, fill='#F59E0B')
    draw.text((82, 83), "RETAIL POS & STOCK", fill='#FFFFFF', font=font_badge)

    # Title
    draw.text((70, 125), "Inventory / የኔ ሱቅ", fill='#FFFFFF', font=font_title)

    # Subtitle
    draw.text((70, 200), "Simple, Offline Inventory, POS & Credit Ledger", fill='#E2E8F0', font=font_sub)
    draw.text((70, 235), "ለኢትዮጵያ ቸርቻሪ ሱቆች የተዘጋጀ ከመስመር ውጭ የሚሰራ", fill='#94A3B8', font=font_sub)

    # Pills
    draw.rounded_rectangle([70, 310, 220, 350], radius=20, fill=(255, 255, 255, 40), outline='#FFFFFF', width=1)
    draw.text((85, 320), "⚡ 100% OFFLINE", fill='#FFFFFF', font=font_pills)

    draw.rounded_rectangle([235, 310, 410, 350], radius=20, fill=(255, 255, 255, 40), outline='#FFFFFF', width=1)
    draw.text((250, 320), "📒 DUBE / ዱቤ LEDGER", fill='#FFFFFF', font=font_pills)

    draw.rounded_rectangle([425, 310, 615, 350], radius=20, fill=(255, 255, 255, 40), outline='#FFFFFF', width=1)
    draw.text((440, 320), "📱 TELEBIRR & CASH", fill='#FFFFFF', font=font_pills)

    # Right preview card
    card_box = [700, 75, 950, 425]
    draw.rounded_rectangle(card_box, radius=24, fill=(15, 23, 42), outline='#38BDF8', width=2)

    # Mini App Icon
    draw.rounded_rectangle([780, 115, 870, 205], radius=18, fill='#0F766E', outline='#F59E0B', width=2)
    # Simple icon
    draw.rectangle([810, 140, 840, 180], fill='#FFFFFF')
    draw.line([(810, 155), (840, 155)], fill='#F59E0B', width=3)

    font_card_h = ImageFont.truetype(font_bold_path, 18) if os.path.exists(font_bold_path) else ImageFont.load_default()
    font_card_sub = ImageFont.truetype(font_reg_path, 12) if os.path.exists(font_reg_path) else ImageFont.load_default()

    draw.text((765, 225), "Inventory POS", fill='#FFFFFF', font=font_card_h)
    draw.text((770, 252), "Fast • Private • Safe", fill='#38BDF8', font=font_card_sub)

    # Simulated receipt strip
    draw.rounded_rectangle([730, 285, 920, 335], radius=10, fill=(30, 41, 59))
    draw.text((745, 298), "ETB 3,450.00", fill='#22C55E', font=font_pills)
    draw.text((860, 300), "PAID ✓", fill='#F59E0B', font=font_card_sub)

    draw.rounded_rectangle([730, 350, 920, 390], radius=8, fill='#F59E0B')
    draw.text((760, 360), "NEW SALE (ሽያጭ)", fill='#FFFFFF', font=font_pills)

    img.save('store/feature_graphic.png', 'PNG')
    print("Created store/feature_graphic.png (1024x500)")

if __name__ == '__main__':
    os.makedirs('store', exist_ok=True)
    create_svg()
    create_png()
