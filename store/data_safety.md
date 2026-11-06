# Google Play Data Safety Declaration

> [!WARNING]
> **DRAFT DATA SAFETY DECLARATION — VERIFY AGAINST GOOGLE PLAY CONSOLE'S CURRENT FORM BEFORE FINAL SUBMISSION.**
> Google Play Console periodically updates questionnaire fields, definitions, and disclosures. Confirm the selections below against the live console UI before publishing.

---

## 1. Architectural Summary & Data Philosophy

`Inventory` (`com.yenesuq.inventory`) is designed with an **offline-first architecture**:
1. **Offline Mode (Default)**:
   - Merchant data is stored locally in the app's SQLite database. Do not claim app-level database encryption: the current implementation does not encrypt the SQLite database itself.
   - **Zero data is collected, transmitted, or shared.**
   - No advertising SDKs, analytics trackers, or social tracking frameworks are bundled in the binary.
2. **Online Sync Mode (Optional, opt-in)**:
   - When the user explicitly enables sync, the app sends shop records (including customer names, phone numbers, and notes when entered), sales/payment records, Telegram account identity, and a device token to the configured Laravel server over HTTPS. The app does not force TLS 1.3 specifically; supported TLS versions depend on the server and Android platform configuration.

---

## 2. Google Play Data Safety Form Responses

### Data Collection & Sharing Overview
- **Does your app collect or share any user data?**
  - **Yes** (Applicable only when Online Sync is enabled). If the user remains strictly offline, answer "No". Since the app supports optional online sync, declare the synced data types below.
- **Is all of the user data collected by your app encrypted in transit?**
  - **Draft: Yes, if production servers accept HTTPS only.** Cleartext traffic is disabled (`android:usesCleartextTraffic="false"`); confirm the production endpoint and every network path use HTTPS before submission.
- **Do you provide a way for users to request that their data be deleted?**
  - **Yes**. In-app: `Settings > Online & Sync > Delete Online Data`. Bot: `/delete_shop`. Both trigger immediate, permanent deletion of cloud records.
- **Can users choose whether this data is collected?**
  - **Yes**. Online mode is optional. Users can run 100% offline indefinitely without entering credentials or connecting to the internet.

---

## 3. Detailed Data Types Breakdown

### A. Personal Info
| Data Type | Collected? | Shared? | Required / Optional | Purpose |
|---|---|---|---|---|
| **Name** | Yes (Online only) | No | Optional | Telegram first name and customer names entered into synced shop records; app functionality/account management. |
| **Email Address** | No | No | N/A | Not collected. |
| **User IDs (Telegram ID)** | Yes (Online only) | No | Optional (Only for sync) | Account Management & Authentication for linking device to Telegram bot. |
| **Phone Number** | Yes (Online only when entered and synced) | No | Optional | Customer phone numbers in synced customer records; app functionality. |
| **Other personal info (customer notes)** | Yes (Online only when entered and synced) | No | Optional | Customer notes synced as part of the shop ledger; app functionality. Review note fields and restrict sensitive content. |

### B. Financial Info
| Data Type | Collected? | Shared? | Required / Optional | Purpose |
|---|---|---|---|---|
| **Purchase history / Sales** | Yes (Online only) | No | Optional (Only for sync) | App functionality (synchronizing sales and payment records between authorized devices and the bot). |
| **Credit cards / Banking info** | No | No | N/A | Never collected. POS handles cash tender, manual Telebirr references, or manual customer ledger records. |

### C. App Activity & Performance
| Data Type | Collected? | Shared? | Required / Optional | Purpose |
|---|---|---|---|---|
| **App interactions** | No | No | N/A | No behavior analytics, heatmaps, or marketing tracking SDKs. |
| **Crash logs / Diagnostics** | No | No | N/A | Crash reports are kept in a local in-memory/file ring buffer for user export only; never automatically phoned home. |

### D. Device or Other Identifiers
| Data Type | Collected? | Shared? | Required / Optional | Purpose |
|---|---|---|---|---|
| **Device or other IDs (Device Token / UUID)** | Yes (Online only) | No | Optional (Only for sync) | App functionality and security: generated token authenticates sync requests for the paired device. |

---

## 4. Third-Party Data Sharing
- **Draft answer: verify in Play Console.** The Laravel endpoint stores synced records for the service. Telegram processes the Telegram account and bot messages used for pairing and bot features. Do not claim "no sharing" until the exact service-provider relationship and Google's current definition are checked in the form. No advertising or analytics partners receive data.

---

## 5. Security Practices & Data Deletion
- **Encryption in Transit**: HTTPS required by Android cleartext policy; verify TLS settings on every production server before submission.
- **Data Deletion URL**: `https://stock.yenesuq.com/privacy/#deletion`
- **In-App Deletion Step**:
  1. Open App > Settings (`ቅንብሮች`).
  2. Tap `Online & Sync` (`የመስመር ላይ ማመሳሰል`).
  3. Tap `Delete Online Data` (`የመስመር ላይ መረጃን አጥፋ`).
  4. Confirm prompt. All server data for the shop is deleted immediately with cascade removal.

## 6. Play Console Verification Notes
- Google defines collection to include user data transmitted off the device by the app or its SDKs. This app must answer **Yes** to collection overall because optional sync sends records off-device; offline-only use transmits no shop data.
- Review the live Data safety form's data categories, sharing, service-provider, retention, and deletion questions before submission. Customer phone numbers and notes are included in customer sync payloads.
- Google's current form guidance: https://support.google.com/googleplay/android-developer/answer/10787469?hl=en
