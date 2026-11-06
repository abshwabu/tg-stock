# Google Play Phone Screenshots Specification (1080 × 1920 Portrait)

This document specifies the exact 8 screenshot compositions, visual layouts, demo seed data states, and bilingual headline text (English and Amharic) required for the Google Play Store listing.

All screenshots must be captured at **1080 × 1920 px (9:16 aspect ratio)** on an Android device running the pre-seeded demo dataset (`DemoDataService.loadDemoProducts()`).

---

## Technical Specifications
- **Resolution**: 1080 × 1920 pixels (Portrait)
- **Format**: 24-bit PNG (no alpha)
- **Framing**: Clean Android device bezel or full bleed with 120px colored top marketing banner.
- **Color Palette**:
  - Top Banner Background: `#0F766E` (Deep Teal)
  - Banner Accent: `#F59E0B` (Amber 500)
  - Banner Text: `#FFFFFF` (Bold Noto Sans Ethiopic / Noto Sans)
- **Language Sets**:
  - Set A: English (`/store/screenshots/en/`)
  - Set B: Amharic (`/store/screenshots/am/`) — *[FLAGGED FOR NATIVE REVIEW]*

---

## Demo Seeder Dataset Reference
The screenshots utilize products pre-loaded by `DemoDataService`:
1. `ጤፍ (ማኛ) / White Teff` (SKU: `TEFF-01`, Stock: 50 kg, Cost: 90 ETB, Sell: 110 ETB)
2. `ስኳር / Sugar (1kg)` (SKU: `SUGAR-01`, Stock: 100 pcs, Cost: 115 ETB, Sell: 130 ETB)
3. `የሱፍ ዘይት / Sunflower Oil 3L` (SKU: `OIL-3L`, Stock: 30 pcs, Cost: 580 ETB, Sell: 650 ETB)
4. `የቡና ቁርስ ማዘጋጀት / Coffee Service` (SKU: `SRV-COFFEE`, Cost: 15 ETB, Sell: 35 ETB)
5. `የእንጀራ ማሸጊያ / Injera Pack (10 pcs)` (SKU: `CNS-INJERA`, Recipe: 2kg Teff, Sell: 150 ETB)

---

## Screenshot Matrix (8 Screens)

### 📸 Screenshot 1: Home Dashboard & Daily Quick Glance
- **Target Screen**: `HomeScreen`
- **Visual State**:
  - Shop Title: `"አበበ ሱቅ / Abebe Retail"`
  - Today's Summary card: `Sales: 3,450.00 ETB`, `Cash in Drawer: 2,100.00 ETB`, `Dube: 1,350.00 ETB`.
  - Quick action grid visible: `New Sale`, `Products`, `Customer Credit (ዱቤ)`, `Reports`.
  - Top backup status indicator: `"መረጃዎ ተጠብቋል / Data Safe"`.
- **Top Marketing Banner**:
  - **English**: `100% Offline Inventory & POS • Works Everywhere`
  - **Amharic**: `100% ከመስመር ውጭ የሚሰራ የሱቅና የሽያጭ መቆጣጠሪያ`

---

### 📸 Screenshot 2: Express Point of Sale (POS) & Ethiopian Payments
- **Target Screen**: `SaleScreen`
- **Visual State**:
  - Active cart showing items:
    - 2 × ጤፍ (ማኛ) (220.00 ETB)
    - 1 × የሱፍ ዘይት 3L (650.00 ETB)
    - 1 × የቡና ቁርስ (35.00 ETB)
    - **Total: 905.00 ETB**
  - Payment sheet open showing tender chips: `ጥሬ ገንዘብ (Cash)`, `ቴሌብር (Telebirr)`, `ሲቢኢ ብር (CBE Birr)`, `ዱቤ (Dube)`.
- **Top Marketing Banner**:
  - **English**: `Lightning-Fast Sales • Cash, Telebirr & CBE Birr`
  - **Amharic**: `ፈጣን ሽያጭ • በጥሬ ገንዘብ፣ በቴሌብር እና በሲቢኢ ብር`

---

### 📸 Screenshot 3: Smart Product Catalog & Barcode Search
- **Target Screen**: `ProductsScreen`
- **Visual State**:
  - List of 5 pre-loaded items with stock badges and color chips.
  - Search bar with camera barcode scanner icon active.
  - Item kinds visibly distinguished: `Stockable`, `Service` (No stock needed), `Consumable` (Bake / Recipe).
  - Low stock warning banner visible on items near threshold.
- **Top Marketing Banner**:
  - **English**: `Track Goods, Services & Recipes with Barcode Scan`
  - **Amharic**: `ዕቃዎች፣ አገልግሎቶች እና ባርኮድ ስካነር በአንድ ቦታ`

---

### 📸 Screenshot 4: Customer Credit & Debt Ledger (Dube / ዱቤ)
- **Target Screen**: `DubeScreen`
- **Visual State**:
  - Active customer debt cards:
    - `ዳዊት ከበደ / Dawit Kebede`: `2,400.00 ETB` (Due in 3 days)
    - `ፋጢማ አህመድ / Fatima Ahmed`: `850.00 ETB`
  - Record partial settlement button: `ክፍያ መዝግብ (Record Payment)`.
  - Total outstanding receivables KPI banner at top.
- **Top Marketing Banner**:
  - **English**: `Customer Credit Ledger • Never Forget Who Owes You`
  - **Amharic**: `የደንበኞች የዱቤ መዝገብ • ማን ምን ያህል እንዳለበት በቀላሉ ይወቁ`

---

### 📸 Screenshot 5: Stock In, Purchases & Waste Adjustments
- **Target Screen**: `StockInScreen` / `AdjustScreen`
- **Visual State**:
  - Stock adjustment modal showing reasons: `ግዢ / Stock In`, `ብልሽት / Spoilage`, `እርማት / Inventory Correction`.
  - Quantity picker with metric unit support (`kg`, `pcs`, `liters`).
  - Cost price per unit and instant updated stock level preview.
- **Top Marketing Banner**:
  - **English**: `Track Purchases, Spoilage & Damaged Goods`
  - **Amharic**: `አዳዲስ ግዢዎችን እና የብልሽት ቅነሳዎችን በትክክል ይመዝግቡ`

---

### 📸 Screenshot 6: Daily Profits, Sales Analytics & Cash Drawer
- **Target Screen**: `ReportsScreen`
- **Visual State**:
  - Selected tab: `Today / ዛሬ`.
  - Metrics cards:
    - Gross Sales: `14,250.00 ETB`
    - Estimated Profit Margin: `3,180.00 ETB (22.3%)`
    - Transactions: `28 sales`
  - Breakdown by payment method chart: Telebirr 60%, Cash 30%, CBE Birr 10%.
  - Calendar selector displaying Ethiopian dates (`ጥቅምት / Tikimt`).
- **Top Marketing Banner**:
  - **English**: `Instant Profit & Loss • Ethiopian Calendar Support`
  - **Amharic**: `የቀን ትርፍ እና የሽያጭ ትንታኔ በኢትዮጵያ ካላንደር`

---

### 📸 Screenshot 7: Multi-Location Warehouse & Store Transfers
- **Target Screen**: `TransferScreen`
- **Visual State**:
  - Source: `መጋዘን / Central Warehouse`
  - Destination: `ዋና ሱቅ / Main Suq`
  - Item being transferred: `ጤፍ (ማኛ) — 20 kg`.
  - Instant discrepancy check and transit tracking card.
- **Top Marketing Banner**:
  - **English**: `Multi-Store & Warehouse Stock Transfers`
  - **Amharic**: `የዕቃ ዝውውር ከዋና መጋዘን ወደ መደብር ያለምንም ጉድለት`

---

### 📸 Screenshot 8: Offline Backup & Optional Telegram Sync
- **Target Screen**: `SettingsScreen` (Data & Backup section)
- **Visual State**:
  - Prominent `"Back Up & Share Now"` button.
  - Success badge: `"Backup File (.sqlite) Ready"`.
  - Share dialog showing Telegram "Saved Messages", WhatsApp, and local file storage.
  - Optional Telegram Bot pairing switch (Cloud Sync).
- **Top Marketing Banner**:
  - **English**: `100% Private Offline Data • One-Tap Telegram Backup`
  - **Amharic**: `የግል መረጃዎ በስልክዎ ብቻ • በአንድ ቁልፍ ወደ ቴሌግራም ኮፒ ያድርጉ`
