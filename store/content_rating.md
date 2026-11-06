# Google Play / IARC Content Rating Questionnaire Answers

**Application**: `Inventory` (`com.yenesuq.inventory`)  
**Category**: Business / Productivity / Point of Sale  
**Target Audience**: Retail Merchants, Shopkeepers, Store Managers (Ages 18+)  
**Recommended IARC Rating**: **PEGI 3 / Everyone / 3+ (General Audience)**

---

## IARC Questionnaire Response Sheet

| Category / Question | Answer | Rationale & Codebase Verification |
|---|---|---|
| **Category Selection** | **Utility, Productivity, Communication, or Other** | The app is an offline-first inventory, POS, and store ledger utility. |
| **Violence** | **No** | Contains zero depictions of violence, weapons, or gore. |
| **Fear / Horror** | **No** | Contains no scary or disturbing content. |
| **Sexuality** | **No** | Contains zero sexual themes, nudity, or provocative imagery. |
| **Simulated Gambling** | **No** | POS and store ledger app. No gambling, lotteries, or betting mechanics. |
| **Language / Profanity** | **No** | UI contains only standard retail business and commerce terminology in English and Amharic. |
| **Controlled Substances** | **No** | Does not reference, sell, or encourage tobacco, alcohol, or illicit substances. |
| **Crude Humor** | **No** | Contains no crude humor. |
| **Online Interactions / Social Features** | **No** | Merchants communicate solely with their own registered Telegram bot for personal sync. No public forums, no chatrooms, no messaging between strangers. |
| **Shares User Physical Location** | **No** | Zero location permissions (`ACCESS_FINE_LOCATION`, `ACCESS_COARSE_LOCATION` are omitted from `AndroidManifest.xml`). |
| **Digital Purchases / In-App Billing** | **No** | No Google Play In-App Billing SDK is included. Sideloadable and Play Store releases are free software utilities. |
| **Web Browser / Unrestricted Internet** | **No** | The app does not embed a web browser (`WebView` is not used). URL launching is strictly restricted to opening the official download and legal pages via external browser intent. |

---

## Resulting Global Age Ratings
- **Google Play (North America)**: Everyone (E)
- **PEGI (Europe)**: PEGI 3
- **USK (Germany)**: USK 0 (Approved for all ages)
- **ClassInd (Brazil)**: Livre (General audience)
- **ESRB**: Everyone
