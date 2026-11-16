# TGStock Offline Licensing Specification & Architecture

| Version | Status | Date | Author / Context |
| :--- | :--- | :--- | :--- |
| **1.0.0** | **APPROVED** | 2026-11-16 | TGStock Engineering (Issue #27) |

---

## 1. Overview & Context

TGStock is an offline-first inventory management and point-of-sale (POS) application tailored for small retail shops in Ethiopia. The deployment topology consists of:
1. **Client App**: Flutter mobile and desktop application functioning completely offline on cashiers' and shop owners' devices.
2. **Shop Server (Optional)**: Lightweight Laravel + SQLite service running either locally on a shop master machine or on budget shared hosting (providing Telegram bot access and multi-device sync).
3. **License Console**: Standalone, air-gapped or secured super-admin Laravel portal operated exclusively by the product owner to issue, track, and manage commercial licenses.

### Business Model & Licensing Philosophy
* **Offline-First Expiry**: License tokens must reliably activate and expire without requiring an active internet connection.
* **Flexible Plans**: Supports lifetime purchase (one-off), subscription tokens (monthly, yearly), and trial periods.
* **No Staff Tax**: Staff devices connecting to a shop server do not require individual paid licenses; seats are licensed at the shop level, or single devices cover local operator profiles.
* **Zero External Dependencies**: Cryptographic operations rely strictly on standard system libraries: `libsodium` / `ext-sodium` (PHP) and standard Ed25519 libraries (`crypto` / `pinenacl` / `cryptography` in Dart). No proprietary licensing SDKs orComposer dependencies are needed.

---

## 2. Threat Model & Security Boundaries

### 2.1 In Scope (Defended)
* **Token Sharing Across Devices**: A license issued for Shop A or Device A cannot be copied to and activated on Device B.
* **System Clock Rollback**: An operator cannot roll back the operating system clock (e.g. from December to January) to artificially prolong an expired subscription.
* **Reinstallation & Local Storage Tricks**: Clearing app storage or reinstalling the app cannot reset a past high-water mark or resurrect an expired token without administrative intervention.
* **Token Tampering**: Modifying any field (dates, seats, features, flags) in a signed token invalidates the signature and results in immediate rejection.
* **Key Impersonation**: Only signatures produced by the product owner's private Ed25519 root keys are accepted.

### 2.2 Out of Scope (Explicitly Stated)
* **Client Binary Modification & APK Patching**: Reverse-engineering the Flutter APK, patching the compiled ARM/DEX code, modifying Dalvik bytecode, or hooking Flutter method channels via Frida/Xposed are out of scope.
* **Rooted & Compromised Host Attacks**: A device owner with root privileges who modifies memory at runtime, patches the local SQLite database directly, or hooks OS kernel time syscalls can bypass software controls on their physical hardware.
* **Collusion with Database Admin**: A shop owner who directly alters the shop server's SQLite schema or database rows can bypass server-side checks.

TGStock implements commercial-grade protection against casual and operational piracy, unauthorized sharing, and clock manipulation without introducing fragile, user-hostile DRM kernel drivers.

---

## 3. Cryptographic Foundation & Key Management

### 3.1 Digital Signature Scheme
* **Algorithm**: **Ed25519** (Edwards-curve Digital Signature Algorithm over Curve25519 with SHA-512, RFC 8032).
* **Key Sizes**: 32-byte public key, 64-byte secret key (or 32-byte seed), 64-byte detached signature.
* **Domain Separation**: All signatures are computed over the domain separation prefix concatenated with the binary payload:
  $$\text{DataToSign} = \text{"LIC1"} \parallel \text{Payload} \quad (4 + 31 = 35 \text{ bytes})$$
  This guarantees that license signatures cannot collide with other signatures (such as sync tokens, audit records, or revocation lists).

### 3.2 Key Management Lifecycle
1. **Offline Keypair Generation**:
   Root Ed25519 keypairs are generated in an offline, air-gapped environment using libsodium's `crypto_sign_seed_keypair()`.
2. **Console Private Key Storage**:
   The private signing key resides **strictly in the License Console**. It is encrypted at rest in the database or filesystem using libsodium:
   * Key derivation: `crypto_pwhash` (Argon2id) with salt and strong memory limits.
   * Encryption: `crypto_secretbox` (XSalsa20-Poly1305 authenticated symmetric encryption).
   * Decryption occurs in-memory only during administrative token issuance.
3. **Offline Backups**:
   Two air-gapped backups of the key seed must be created:
   * One hardware offline backup (read-only encrypted USB drive in a secure safe).
   * One paper backup (QR code and Crockford Base32 paper recovery card stored in a fireproof safe).
4. **Key Rotation & Embedded Public Keys**:
   The Flutter app and shop server bundle up to 3 trusted public keys identified by a 1-byte Key ID (`kid`):
   * `kid = 1`: Primary production root key.
   * `kid = 2`: Secondary / rotation key.
   * `kid = 3`: Reserved fallback key.
   When rotating keys, the new public key is shipped in an app update. Existing tokens signed with `kid = 1` continue verifying successfully until their natural expiration.

---

## 4. Binary Token Format & Payload Layout

A license token binary consists of exactly **95 bytes**:
* **Payload**: 31 bytes (Big-Endian layout).
* **Signature**: 64 bytes (Ed25519 detached signature).

```
 0                   1                   2                   3
 0 1 2 3 4 5 6 7 8 9 0 1 2 3 4 5 6 7 8 9 0 1 2 3 4 5 6 7 8 9 0 1
+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+
|      ver      |      kid      |     type      |     plan      |
+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+
|                          license_id                           |
|                          (8 bytes)                            |
+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+
|                             bind                              |
|                          (8 bytes)                            |
+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+
|          issued_day           |            nbf_day            |
+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+
|            exp_day            |  grace_days   |     seats     |
+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+
|           features            |   max_major   |               |
+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+               |
|                                                               |
+                       signature (Ed25519)                     +
|                            (64 bytes)                         |
+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+
```

### 4.1 Payload Field Definitions (31 Bytes)

| Byte Offset | Field Name | Type | Description & Constraints |
| :--- | :--- | :--- | :--- |
| `0` | `ver` | `uint8` | Token specification version. Must be `1` (`0x01`). |
| `1` | `kid` | `uint8` | Key Identifier (`1`..`255`) corresponding to the signing key. |
| `2` | `type` | `uint8` | Binding type: `1` = Device-bound, `2` = Shop-bound. |
| `3` | `plan` | `uint8` | Plan category: `0` = Trial, `1` = Monthly, `2` = Yearly, `3` = Lifetime, `4` = Custom. |
| `4..11` | `license_id` | `uint64` | 64-bit random identifier used for tracking and revocation. |
| `12..19` | `bind` | `byte[8]` | First 8 bytes of SHA-256 of `install_id` (device) or lowercase `shop_uuid` (shop). |
| `20..21` | `issued_day` | `uint16` | Token issuance day (days since 2024-01-01 in `Africa/Addis_Ababa`). |
| `22..23` | `nbf_day` | `uint16` | "Not Before" day. Token is invalid prior to this day. |
| `24..25` | `exp_day` | `uint16` | Expiry day. `0xFFFF` indicates lifetime validity (never expires). |
| `26` | `grace_days` | `uint8` | Grace period in days permitted after `exp_day` (typically 7 or 14). |
| `27` | `seats` | `uint8` | Maximum concurrent active cashier terminals (enforced by shop server). |
| `28..29` | `features` | `uint16` | Bitmask of enabled optional feature modules (see §4.2). |
| `30` | `max_major` | `uint8` | Maximum supported app major version (`0` = any version; `1` = v1.x only, etc.). |

### 4.2 Feature Flags Bitmask (16 Bits)

| Bit Position | Hex Value | Name | Capability Granted |
| :--- | :--- | :--- | :--- |
| `bit 0` | `0x0001` | `online_sync` | Cloud & multi-device background synchronization. |
| `bit 1` | `0x0002` | `telegram_bot` | Telegram bot alerts, daily reports, and remote commands. |
| `bit 2` | `0x0004` | `team_roles` | Granular staff roles and permission boundaries. |
| `bit 3` | `0x0008` | `multi_location`| Multi-warehouse and multiple shop location support. |
| `bit 4` | `0x0010` | `export` | PDF/Excel detailed accounting and inventory reporting. |
| `bits 5..15`| `0xFFE0` | *Reserved* | Reserved for future features; must be `0`. |

---

## 5. Epoch, Calendar & Timezone Specification

* **Reference Timezone**: `Africa/Addis_Ababa` (UTC+3, exactly +10800 seconds offset, no Daylight Saving Time).
* **Reference Epoch (Day 0)**: `2024-01-01 00:00:00 +03:00` (Unix timestamp `1704056400`).
* **Conversion Formula**:
  $$\text{day} = \left\lfloor \frac{\text{unix\_timestamp} + 10800}{86400} \right\rfloor - 19723$$
  *(where 19723 is the day index of 2024-01-01 from 1970-01-01).*
* **End of Day Rule**: Expiry is valid through **23:59:59.999** in `Africa/Addis_Ababa` on `exp_day`. The token only transitions to `GRACE` on `exp_day + 1`.

---

## 6. String Token Encoding & Crockford Base32

To ensure error-free transmission over SMS, Telegram, printed paper cards, or manual phone typing, tokens are formatted into human-friendly strings.

### 6.1 Alphabet & Character Normalization
Crockford Base32 uses 32 unambiguous alphanumeric characters:
```
0 1 2 3 4 5 6 7 8 9 A B C D E F G H J K M N P Q R S T V W X Y Z
```
*(Excluded to avoid transcription confusion: `I`, `L`, `O`, `U`).*

**Parser Normalization Rules**:
1. Strip all whitespace (` `) and dashes (`-`).
2. Convert all characters to uppercase.
3. Map Crockford aliases:
   * `O` (letter) $\to$ `0` (digit zero)
   * `I` (letter) and `L` (letter) $\to$ `1` (digit one)

### 6.2 Token String Layout
* A 95-byte binary token contains $95 \times 8 = 760$ bits.
* In Crockford Base32, each character encodes 5 bits:
  $$\frac{760 \text{ bits}}{5 \text{ bits/char}} = 152 \text{ characters (exact, zero padding bits)}$$
* Formatted representation:
  Prefix `LIC1-` followed by the 152 characters separated into blocks of 5 with dashes (30 blocks of 5 characters, plus 1 trailing block of 2 characters):
  ```
  LIC1-XXXXX-XXXXX-XXXXX-XXXXX-XXXXX-XXXXX-XXXXX-XXXXX-XXXXX-XXXXX-XXXXX-XXXXX-XXXXX-XXXXX-XXXXX-XXXXX-XXXXX-XXXXX-XXXXX-XXXXX-XXXXX-XXXXX-XXXXX-XXXXX-XXXXX-XXXXX-XXXXX-XXXXX-XXXXX-XXXXX-XX
  ```
  *(Total length: 187 characters including prefix and dashes).*

---

## 7. Device Codes & Shop Codes

When a customer orders a license, they share their identifier with the product owner. Instead of exposing raw UUIDs or technical hashes, TGStock generates an ergonomic, checksummed **Device Code** or **Shop Code**.

### 7.1 Derivation & Formatting
1. **Binding Source**:
   * Device: `install_id` (a securely generated UUID v4 stored in app private storage).
   * Shop: `shop_uuid` (the shop's unique UUID v4, converted to lowercase).
2. **8-Byte Bind Computation**:
   $$\text{bind} = \text{SHA-256}(\text{source})[0..7]$$
3. **Prefix Differentiation**:
   * Device Code: Starts with letter `D`.
   * Shop Code: Starts with letter `S`.
   *(This ensures that a device code can never be confused with or entered as a shop code).*
4. **Base32 Body**:
   The 8 bytes (64 bits) are encoded using Crockford Base32:
   $$\lceil 64 / 5 \rceil = 13 \text{ Base32 characters}$$
5. **Check Character Calculation**:
   To catch typos and transpositions when read over the phone, a single check character is appended over the 14 characters ($\text{Prefix} + 13 \text{ Base32 chars}$):
   $$\text{check\_val} = \left( \sum_{i=0}^{13} v_i \times (2i + 1) \right) \pmod{32}$$
   where $v_i \in [0..31]$ is the Crockford Base32 index of character $i$.
   Because $(2i + 1)$ is always odd ($\gcd(2i + 1, 32) = 1$), this formula guarantees 100% detection of single-character substitution errors and high detection of adjacent transpositions.
6. **Code Grouping**:
   The resulting 15 characters are displayed in blocks of 4 separated by dashes:
   * Device: `DXXX-XXXX-XXXX-XXX` (e.g. `D7ME-5Q0R-XFHD-QJS`)
   * Shop: `SXXX-XXXX-XXXX-XXX` (e.g. `S6AS-9YEH-9Q7D-FY9`)

### 7.2 Console Workflow
The License Console operator pastes the code directly into the issuing screen:
1. Console validates the check character.
2. Console verifies the prefix matches the selected license plan (Device vs Shop).
3. Console decodes the 13 Base32 characters back to the exact 8-byte `bind`.
4. **The console never needs the raw install ID or customer UUID.**

---

## 8. Verification Algorithm & State Machine

When a token is evaluated against the current context, the verification engine follows this strict sequence:

```mermaid
flowchart TD
    A[Input Token String] --> B[Strip spaces/dashes, uppercase, map O/I/L]
    B --> C{Prefix == 'LIC1' & Len == 152?}
    C -- No --> R_SIG[INVALID_SIGNATURE]
    C -- Yes --> D[Decode Base32 -> 95 bytes]
    D --> E{ver == 1?}
    E -- No --> R_VER[UNSUPPORTED_VERSION]
    E -- Yes --> F{kid in App Trusted Keys?}
    F -- No --> R_SIG
    F -- Yes --> G{Ed25519 verify 'LIC1' || payload?}
    G -- Fail --> R_SIG
    G -- Pass --> H{license_id in Revocation List?}
    H -- Yes --> R_REV[REVOKED]
    H -- No --> I{max_major > 0 & app_major > max_major?}
    I -- Yes --> R_VER
    I -- No --> J{type == expected_type & bind == expected_bind?}
    J -- No --> R_BIND[WRONG_BIND]
    J -- Yes --> K[Compute effectiveToday & Check Clock Rollback]
    K --> L{effectiveToday < nbf_day?}
    L -- Yes --> S_NBF[NOT_YET_VALID]
    L -- No --> M{exp_day == 0xFFFF?}
    M -- Yes --> S_ACT[ACTIVE]
    M -- No --> N{effectiveToday <= exp_day?}
    N -- Yes --> O{exp_day - effectiveToday <= 7?}
    O -- Yes --> S_EXP[EXPIRING]
    O -- No --> S_ACT
    N -- No --> P{effectiveToday - exp_day <= grace_days?}
    P -- Yes --> S_GRACE[GRACE]
    P -- No --> S_EXPIRED[EXPIRED]
```

### 8.1 Verification States & Operational Semantics

| State | Status Category | Business Permissions | UI Banner & Warning |
| :--- | :--- | :--- | :--- |
| `ACTIVE` | Normal | Full read & write permissions. | No warning (standard header). |
| `EXPIRING` | Warning | Full read & write permissions. | Amber banner: *"License expires in $N$ days. Contact vendor to renew."* |
| `GRACE` | Grace Period | Full read & write permissions. | Orange banner: *"License expired on [Date]. Operating in grace period ($M$ days left)."* |
| `EXPIRED` | Read-Only | **Read-Only Mode**: Writes blocked (§10). | Red banner: *"License expired. Terminal in Read-Only mode. Renew to record sales."* |
| `NOT_YET_VALID` | Blocked | Writes blocked; reads permitted. | Red banner: *"License validity begins on [Date]. Check device date."* |
| `WRONG_BIND` | Error | Token rejected; defaults to unactivated. | Red alert: *"This license token belongs to a different device or shop."* |
| `INVALID_SIGNATURE`| Error | Token rejected; defaults to unactivated. | Red alert: *"Invalid license token or checksum error."* |
| `UNSUPPORTED_VERSION`| Error | Writes blocked. | Red alert: *"App update required to use this license."* |
| `REVOKED` | Blocked | Writes blocked; terminal locked out. | Critical banner: *"License has been administratively revoked."* |

---

## 9. Monotonic Clock Rules & Anti-Rollback Defense

To prevent users from rolling back the device calendar (e.g. holding the date permanently at January 2026), TGStock implements a monotonic high-water mark defense.

### 9.1 Clock Variables & Calculations
* `today`: Current day index calculated from local system clock in `Africa/Addis_Ababa`.
* `highWater`: The maximum day index ever observed by the local installation, persisted in secure storage.
* `effectiveToday`: The effective operating day used for all license calculations:
  $$\text{effectiveToday} = \max(\text{today}, \text{highWater})$$

### 9.2 Sources Updating `highWater`
`highWater` is monotonically updated whenever any of the following events occur:
1. **Local System Clock**: If $\text{today} > \text{highWater}$, set $\text{highWater} = \text{today}$.
2. **Local Movement / Sale Timestamp**: When any sale, stock movement, or cash transaction is committed, its day index updates `highWater`.
3. **Token Issuance Date**: When an activated token is inspected, set $\text{highWater} = \max(\text{highWater}, \text{issued\_day})$.
4. **Authenticated Server Time**: During online sync or Telegram polling, if the response contains authenticated server time, update `highWater`.

### 9.3 Clock Rollback Detection (`CLOCK_SUSPECT`)
If the user sets their system clock backward:
$$\text{If } \text{today} < (\text{highWater} - 1) \implies \text{Set } \mathbf{CLOCK\_SUSPECT} = \mathbf{true}$$
*(A 1-day tolerance accommodates international travel, clock drift, or minor timezone reconfigurations).*

**Consequences of `CLOCK_SUSPECT`**:
* The application continues using $\text{effectiveToday} = \text{highWater}$.
* Rolling the clock back **cannot** revive an expired token or postpone an upcoming expiration.
* If a token is near expiry or expired, rolling the clock backward keeps it expired.
* A warning is logged and reported to the shop server on next connection.

### 9.4 High Water Recovery
If an operator accidentally sets their device clock to the year 2030 and records transactions, `highWater` will advance to that future day.
* **The device clock alone cannot lower `highWater`.**
* **Only an authenticated cryptographic time packet from the shop server or license console can lower `highWater`** back to actual current time.

---

## 10. Expired Behavior: "Read-Only Mode"

When a license enters the `EXPIRED` state (or `REVOKED`), the application immediately switches to **Read-Only Mode**.

### 10.1 Absolute Data Protection Guarantee
> **Important**: Under no circumstances does TGStock delete, hide, truncate, or encrypt existing business records. The merchant's historical ledger, stock records, and financial summaries belong entirely to them.

### 10.2 Permission Matrix

| Feature / Screen | Allowed in Expired Mode? | Technical Behavior & Enforcement |
| :--- | :--- | :--- |
| **View Products & Stock** | ✅ **ALLOWED** | Can browse catalog, search items, view stock counts. |
| **Sales History & Receipts** | ✅ **ALLOWED** | Can view past receipts, reprints, and customer orders. |
| **Customer Dube (Credit) Ledger** | ✅ **ALLOWED** | Can view customer balances, credit history, and phone numbers. |
| **Reports & Analytics** | ✅ **ALLOWED** | Can view daily, weekly, and monthly sales summaries. |
| **Data Export (CSV/PDF)** | ✅ **ALLOWED** | Can export product lists, sales logs, and debtor lists. |
| **Backup & Restore** | ✅ **ALLOWED** | Full SQLite database export and restore functionality remains functional. |
| **License Activation** | ✅ **ALLOWED** | License input screen is always accessible to paste a new token. |
| **Language & Preferences** | ✅ **ALLOWED** | Switch between Amharic and English, adjust display settings. |
| **New Sale (Checkout)** | ❌ **BLOCKED** | "Complete Sale" button disabled; returns error if invoked. |
| **Add / Edit Product** | ❌ **BLOCKED** | Product creation and inventory editing disabled. |
| **Stock Adjustments** | ❌ **BLOCKED** | Cannot record received stock, damaged goods, or wastage. |
| **Record Customer Payment** | ❌ **BLOCKED** | Debt settlement entry blocked. |
| **Server Synchronization** | ❌ **BLOCKED** | Cloud/shared-hosting sync rejects upstream write mutations. |

---

## 11. Multi-Device Seats & Staff Licensing

### 11.1 Shop-Bound vs Device-Bound
1. **Device-Bound Tokens (`type = 1`)**:
   * Covers a single physical device.
   * Multiple local operator profiles (e.g. Owner, Cashier 1, Cashier 2) can share the same physical tablet or phone without purchasing additional seats.
2. **Shop-Bound Tokens (`type = 2`)**:
   * Issued to a shop's unique identifier (`shop_uuid`).
   * The shop server manages and enforces the `seats` field (e.g. 3 active cashier terminals).
   * Staff cashier devices connect over local WiFi (LAN) to the shop server without requiring individual paid licenses.

### 11.2 Concurrency Enforcement
* Each active cashier terminal maintains an ephemeral local session token with the shop server.
* Terminals ping the shop server every 60 seconds with a lightweight heartbeat.
* If the number of active terminals exceeds `seats`, additional terminals receive a `SEATS_EXCEEDED` notice and operate in read-only mode until an existing terminal logs out or times out (after 5 minutes of inactivity).

---

## 12. Online Revocation Protocol

While short monthly tokens naturally limit exposure, the product owner can revoke rogue or refunded tokens via signed revocation lists.

### 12.1 Revocation Payload Format (`REV1`)
Revocation lists are signed using the same root Ed25519 key with domain separation prefix `"REV1"`:
* `ver` (`uint8`): `1`
* `kid` (`uint8`): Signing key ID.
* `list_day` (`uint16`): Day index of list publication.
* `count` (`uint16`): Number of revoked `license_id` entries.
* `license_ids` (`uint64[]`): Array of 8-byte revoked license IDs.
* `signature` (`byte[64]`): Ed25519 signature over `"REV1" || payload`.

### 12.2 Distribution & Local Cache
* Revocation lists are downloaded opportunistically whenever the device connects to the internet or shop server.
* The local client caches the latest valid revocation list in SQLite.
* Offline devices preserve their cached revocation list across restarts.

---

## 13. Shared Test Vectors & Verification Suite

A deterministic test vector generator is provided at [scripts/make_license_vectors.php](file:///home/abshewabu/Documents/projects/laravel/tgstock/scripts/make_license_vectors.php). It produces standard JSON test fixtures at [docs/fixtures/license_vectors.json](file:///home/abshewabu/Documents/projects/laravel/tgstock/docs/fixtures/license_vectors.json).

### 13.1 Test Cases Overview

The fixture suite tests 17 complete license scenarios and 5 code derivation scenarios:

| Test ID | Plan / Scenario | Inputs (Clock, Exp, Conditions) | Expected State | Flag |
| :--- | :--- | :--- | :--- | :--- |
| `valid_monthly` | Monthly device license | Issued: 1040, Exp: 1070, Clock: 1050 | `ACTIVE` | suspect: false |
| `expiring` | Monthly device license | Issued: 1025, Exp: 1055, Clock: 1050 (5 days left) | `EXPIRING` | suspect: false |
| `grace` | Monthly device license | Issued: 1015, Exp: 1045, Clock: 1050 (5 days past exp) | `GRACE` | suspect: false |
| `expired` | Monthly device license | Issued: 1000, Exp: 1030, Clock: 1050 (20 days past exp)| `EXPIRED` | suspect: false |
| `lifetime` | Lifetime device license | Issued: 1000, Exp: `0xFFFF`, Clock: 1050 | `ACTIVE` | suspect: false |
| `trial` | 14-day free trial | Issued: 1045, Exp: 1059, Clock: 1050 (9 days left) | `ACTIVE` | suspect: false |
| `not_yet_valid` | Future scheduled license | Issued: 1050, Nbf: 1060, Clock: 1050 | `NOT_YET_VALID` | suspect: false |
| `tampered_payload` | Tampered byte in payload| Byte 24 modified after signing | `INVALID_SIGNATURE` | suspect: false |
| `wrong_kid` | Untrusted key | Signed with untrusted `kid = 99` | `INVALID_SIGNATURE` | suspect: false |
| `wrong_device_bind`| Shared token | Bound to Device 1, presented on Device 2 | `WRONG_BIND` | suspect: false |
| `shop_bound` | Shop yearly license | Bound to `sampleShopUuid1`, verified on Shop 1 | `ACTIVE` | suspect: false |
| `max_major_too_low`| Version restricted | Token `max_major = 1`, tested on app v2.0 | `UNSUPPORTED_VERSION`| suspect: false |
| `encoding_variants_lowercase_spaces` | Formatting tolerance | All lowercase with spaces around dashes | `ACTIVE` | suspect: false |
| `encoding_variants_o0_il1` | Crockford aliases | Replaced `0` with `O`, `1` with `I`/`L` | `ACTIVE` | suspect: false |
| `clock_suspect` | Clock rollback | Clock: 1040, HighWater: 1050 (rolled back 10 days) | `ACTIVE` | **suspect: true** |
| `revoked` | Administratively revoked | `license_id = deadbeef00000001` in revocation list | `REVOKED` | suspect: false |
| `key_rotation_kid_2`| Secondary root key | Signed with `kid = 2`, verified with trusted key 2 | `ACTIVE` | suspect: false |

### 13.2 Device & Shop Code Fixtures

| Test ID | Type | Source ID | Expected Output Code | Valid? |
| :--- | :--- | :--- | :--- | :--- |
| `device_code_sample_1` | Device | `a1b2c3d4-e5f6-7a8b-9c0d-1e2f3a4b5c6d` | `D7ME-5Q0R-XFHD-QJS` | ✅ Yes |
| `device_code_sample_2` | Device | `ffffffff-0000-1111-2222-333333333333` | `DKAG-PWKM-4WGX-N0N` | ✅ Yes |
| `shop_code_sample_1` | Shop | `e3b0c442-98fc-1c14-9afb-4c8996fb9242` | `S6AS-9YEH-9Q7D-FY9` | ✅ Yes |
| `device_code_aliases_spaces` | Device | Case/Separator tolerance (`d7me 5q0r xfhd qjs`) | Normalizes to `D7ME-5Q0R-XFHD-QJS` | ✅ Yes |
| `device_code_bad_checksum` | Device | Check character altered to `0` | Rejection: Check character mismatch | ❌ No |

---

## 14. Implementation Checklist for Subsystems

### 14.1 License Console (Laravel PHP)
- [ ] Implement `LicenseGeneratorService` using `ext-sodium` with seed-based Ed25519 signing.
- [ ] Encrypt private signing keys at rest using `crypto_pwhash` and `crypto_secretbox`.
- [ ] Provide admin UI to paste Device Code (`D...`) or Shop Code (`S...`), automatically calculating 8-byte `bind`.
- [ ] Support generating all 5 plans (Trial, Monthly, Yearly, Lifetime, Custom).
- [ ] Export signed token formatted as `LIC1-XXXXX-...`.

### 14.2 Shop Server (Laravel PHP + SQLite)
- [ ] Embed trusted public keys (`kid = 1, 2`).
- [ ] Verify shop-bound tokens (`type = 2`) against local `shop_uuid`.
- [ ] Enforce concurrent cashier terminal seats based on `seats` field.
- [ ] Return authenticated server time in API ping responses to calibrate high-water marks.

### 14.3 Flutter Client App (Dart)
- [ ] Embed trusted public keys (`kid = 1, 2`).
- [ ] Implement Crockford Base32 decoder and normalization logic.
- [ ] Display Device Code on the Settings/License screen formatted as `DXXX-XXXX-XXXX-XXX` with one-tap copy and Telegram share.
- [ ] Enforce monotonic `highWater` in `flutter_secure_storage` or encrypted SQLite settings.
- [ ] Implement state machine: route expired state to Read-Only Mode disabling sale finalization.
- [ ] Write integration test verifying all cases in `license_vectors.json`.
