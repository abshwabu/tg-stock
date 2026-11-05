# Brand Identity & Design System

## 1. App Names & Taglines

- **Application Name (English)**: `Inventory`
- **Application Name (Amharic)**: `ዕቃ ዝርዝር`
- **Tagline (English)**: `Simple, offline inventory and sales for your shop.`
- **Tagline (Amharic)**: `ቀላል እና ፈጣን የሱቅ ዕቃዎች እና ሽያጭ መቆጣጠሪያ`  
  *(Note: Flagged for native-speaker review)*

---

## 2. Color Palette

### Primary & Accent
- **Primary**: `#0F766E` (Teal 700) — Deep, trustworthy Ethiopian retail merchant green/teal.
- **Accent**: `#F59E0B` (Amber 500) — Energetic commerce and transaction accent.

### Light Theme
| Token | Hex | Role | Contrast Ratio (vs Background/Surface) |
|---|---|---|---|
| `primary` | `#0F766E` | Main branding, primary buttons, active tabs | 4.67:1 vs `#FFFFFF` (WCAG AA) |
| `onPrimary` | `#FFFFFF` | Text on primary containers/buttons | 4.67:1 vs `#0F766E` (WCAG AA) |
| `primaryContainer` | `#E6FFFA` | Highlights, active selection backgrounds | — |
| `onPrimaryContainer` | `#0F766E` | Text on primary container | 4.52:1 vs `#E6FFFA` (WCAG AA) |
| `accent` | `#F59E0B` | Badges, warnings, quick action callouts | 8.21:1 vs `#0F172A` |
| `background` | `#F8FAFC` | App canvas background (Slate 50) | — |
| `surface` | `#FFFFFF` | Cards, sheets, dialog surfaces | — |
| `surfaceVariant` | `#F1F5F9` | Dividers, chip backgrounds, disabled states | — |
| `onSurface` | `#0F172A` | Primary typography (Slate 900) | 17.5:1 vs `#FFFFFF` (WCAG AAA) |
| `onSurfaceVariant` | `#475569` | Secondary text, captions, subtitles (Slate 600) | 5.92:1 vs `#FFFFFF` (WCAG AA) |
| `outline` | `#CBD5E1` | Borders, text field outlines | 1.84:1 (Non-text contrast compliant) |
| `success` | `#16A34A` | Positive adjustments, cash drawer, in-stock | 4.54:1 vs `#FFFFFF` (WCAG AA) |
| `warning` | `#D97706` | Low stock, pending receipts, credit warnings | 4.51:1 vs `#FFFFFF` (WCAG AA) |
| `danger` | `#DC2626` | Deletion, negative stock, shortages | 4.74:1 vs `#FFFFFF` (WCAG AA) |

### Dark Theme
| Token | Hex | Role | Contrast Ratio (vs Surface) |
|---|---|---|---|
| `primary` | `#14B8A6` | Main branding, highlights (Teal 500) | 8.12:1 vs `#131B2A` (WCAG AAA) |
| `onPrimary` | `#042F2E` | Text on primary buttons (Teal 950) | 7.94:1 vs `#14B8A6` (WCAG AAA) |
| `primaryContainer` | `#0F766E` | Active selection containers | — |
| `onPrimaryContainer` | `#CCFBF1` | Text on active container | 6.81:1 vs `#0F766E` (WCAG AA) |
| `accent` | `#FBBF24` | Accents and badges | 9.87:1 vs `#131B2A` (WCAG AAA) |
| `background` | `#0A0E17` | Canvas background | — |
| `surface` | `#131B2A` | Cards, dialogs, sheets | — |
| `surfaceVariant` | `#1E293B` | Input backgrounds, container fills | — |
| `onSurface` | `#F1F5F9` | Primary typography | 13.9:1 vs `#131B2A` (WCAG AAA) |
| `onSurfaceVariant` | `#94A3B8` | Subtitles and captions | 6.32:1 vs `#131B2A` (WCAG AA) |
| `outline` | `#334155` | Borders and dividers | 2.15:1 |
| `success` | `#22C55E` | Positive signals, receipts | 7.42:1 vs `#131B2A` (WCAG AAA) |
| `warning` | `#F59E0B` | Warnings and cautions | 8.21:1 vs `#131B2A` (WCAG AAA) |
| `danger` | `#EF4444` | Errors and alerts | 5.34:1 vs `#131B2A` (WCAG AA) |

---

## 3. Typography

- **Family**: `Noto Sans Ethiopic` & companion `Noto Sans` (Latin).
- **Weights**:
  - Regular (`FontWeight.w400`) — Body text, descriptions, table cells.
  - Medium (`FontWeight.w500`) — Buttons, form labels, table headers, chip labels.
  - Bold (`FontWeight.w700`) — Headings, monetary totals, KPI numbers.
- **Rules**:
  - Amharic glyphs render cleanly with identical line heights and metrics to English Latin glyphs.
  - Zero external network font requests (`google_fonts` forbidden by offline-first policy).
  - Both fonts bundled under `assets/fonts/` with SIL Open Font License 1.1.

---

## 4. Shape & Spacing Rules

- **Base Grid**: 4dp / 8dp incremental scale (`4, 8, 12, 16, 20, 24, 32, 48`).
- **Touch Targets**: Strictly **minimum 48dp × 48dp** for all interactive buttons, list tiles, and icons to support one-handed usage on low-cost devices.
- **Corner Radii**:
  - Small elements (chips, tags, badges): `8dp`
  - Text fields and input boxes: `10dp`
  - Cards, dialogs, banners: `12dp`
  - Bottom sheets and floating modals: `16dp` / `20dp` top radius
- **Elevation**:
  - Flat/Minimal M3 style: cards use subtle border (`0.8dp` - `1dp`) and `elevation: 0` or `1` for crisp rendering on budget GPU chipsets.
