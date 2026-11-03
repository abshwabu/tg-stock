# Privacy Notice / የግላዊነት ፖሊሲ ማስታወሻ

**Last Updated:** November 2026 / **የመጨረሻ ማሻሻያ:** ኅዳር 2019 ዓ.ም.

---

## 1. English: Privacy & Data Protection

### What We Store Online (When Cloud Sync is Enabled)
Inventory is built **offline-first**. If you use the app without connecting to the Telegram bot or enabling online mode, **zero data leaves your device**.

If you optionally connect your shop online for Telegram bot access and multi-device synchronization, the server stores:
1. **Shop Profile:** Shop name, preferred language (`am`/`en`), currency symbol (`ETB`), and low-stock threshold defaults.
2. **Product Catalog:** Product names, units (e.g. `kg`, `pcs`), SKUs, cost prices, selling prices, and active/deleted status.
3. **Inventory Movements Ledger:** Client-generated UUIDv7 identifiers, product IDs, movement types (`in`, `sale`, `adjust`), signed quantities, transaction prices, and UTC timestamps.
4. **Authorized Telegram User:** Your Telegram numeric user ID and assigned role (`owner` or `staff`).
5. **Paired Devices:** Cryptographic SHA-256 hashes of revocable device tokens and last seen timestamps.

### What We NEVER Store Online
- **No Customer Personal Details:** No customer names, phone numbers, or addresses.
- **No Payment Data:** No credit/debit card numbers, mobile money pins, or bank account credentials.
- **No Plaintext Passwords or Tokens:** Device tokens are never stored in plaintext on the server; only one-way SHA-256 hashes are retained.
- **No Biometric Data:** Fingerprints or face scans used for app lock never leave your phone's Android KeyStore / iOS Secure Enclave.

### How to Permanently Delete Your Online Data
You retain complete ownership of your data at all times. You can delete your entire online presence in two simple ways:
1. **From the Mobile App:**
   - Go to **Settings** (`⚙️`) -> Scroll to **Online Sync & Telegram**.
   - Tap **Delete Online Shop on Server**.
   - Confirm the two-step verification. The server will immediately and permanently delete your shop, user mappings, device authorizations, catalog, and cloud ledger.
2. **From the Telegram Bot:**
   - Send the `/delete_shop` command to the bot.
   - Confirm the prompt.
3. **Local Data Remains Safe:** Deleting your online cloud account does **not** erase your local inventory records stored on your phone.

---

## 2. አማርኛ: የግላዊነት እና የዳታ ጥበቃ ማስታወሻ

### በኦንላይን ሰርቨር ላይ ምን ይቀመጣል? (የደመና ግንኙነት ሲበራ ብቻ)
የኢንቬንቶሪ መተግበሪያ ሙሉ በሙሉ **ያለ ኢንተርኔት (ኦፍላይን)** እንዲሰራ ተደርጎ የተዘጋጀ ነው። መተግበሪያውን ያለ ቴሌግራም ቦት ወይም ያለ ኦንላይን ግንኙነት ከተጠቀሙ **ምንም ዓይነት ዳታ ከስልክዎ አይወጣም**።

መረጃዎን ከቴሌግራም ቦት ጋር ሲያገናኙ ወይም በሌሎች ስልኮች ለመጠቀም የኦንላይን ግንኙነቱን ሲያበሩ የሚከተሉት መረጃዎች በሰርቨሩ ላይ ይቀመጣሉ፦
1. **የሱቅ መረጃ፦** የሱቅዎ ስም፣ የመረጡት ቋንቋ (አማርኛ/እንግሊዝኛ)፣ የገንዘብ ምልክት (ብር) እና ዝቅተኛ የስቶክ ገደብ።
2. **የዕቃዎች ዝርዝር፦** የዕቃዎች ስም፣ መለኪያ (ለምሳሌ ኪሎ፣ ፍሬ)፣ መግዣ ዋጋ፣ መሸጫ ዋጋ እና ያለበት ሁኔታ።
3. **የእንቅስቃሴዎች መዝገብ፦** በስልክዎ የተፈጠሩ የግብይት መለያዎች፣ የተሸጠ ወይም የገባ መጠን፣ የተሸጠበት ዋጋ እና የተከናወነበት ሰዓት።
4. **የቴሌግራም መለያ፦** የባለቤቱ ወይም የሰራተኛው የቴሌግራም ቁጥር (ID) እና የስራ ድርሻ።
5. **የተገናኙ ስልኮች፦** በኮድ የተቀየሩ (Hashed) የተፈቀደላቸው ስልኮች መለያ።

### በኦንላይን ሰርቨር ላይ በፍጹም የማይቀመጡ መረጃዎች
- **የደንበኞች የግል መረጃ፦** የደንበኞች ስም፣ ስልክ ቁጥር ወይም አድራሻ በጭራሽ አይመዘገብም።
- **የክፍያ እና የባንክ መረጃ፦** የባንክ ሂሳብ፣ የሞባይል ባንኪንግ ወይም የካርድ ምስጢራዊ ቁጥሮች አይጠየቁም፤ አይቀመጡም።
- **የስልክ የጣት አሻራ መረጃ፦** መተግበሪያውን ለመቆለፍ የሚጠቀሙበት የጣት አሻራ ወይም የፊት ገጽታ መረጃ በስልክዎ ውስጥ ብቻ የሚቆይ ሲሆን ወደ ሰርቨር አይላክም።

### የኦንላይን መረጃዎን እንዴት ሙሉ በሙሉ ማጥፋት ይችላሉ?
የመረጃዎ ሙሉ ባለቤት እርስዎ ነዎት። በማንኛውም ሰዓት የኦንላይን መረጃዎን በሁለት መንገዶች በቋሚነት ማጥፋት ይችላሉ፦
1. **ከስልክ መተግበሪያው ላይ፦**
   - ወደ **ቅንብሮች** (`⚙️`) ይግቡ -> ወደ **የኦንላይን ግንኙነት እና ቴሌግራም** ይሂዱ።
   - **Delete Online Shop on Server (የኦንላይን ዳታ አጥፋ)** የሚለውን ይጫኑ።
   - የማረጋገጫ ጥያቄውን ያረጋግጡ። ሰርቨሩ ወዲያውኑ የሱቅዎን መረጃ፣ የተገናኙ ስልኮችን እና የኦንላይን መዝገቡን ሙሉ በሙሉ ያጠፋል።
2. **ከቴሌግራም ቦቱ ላይ፦**
   - ለቦቱ `/delete_shop` የሚለውን ትዕዛዝ ይላኩና ያረጋግጡ።
3. **የስልክዎ መረጃ አይጠፋም፦** የኦንላይን ዳታዎን ማጥፋት በስልክዎ ላይ ያለውን የዕቃዎች እና የሽያጭ መዝገብ አይነካም።
