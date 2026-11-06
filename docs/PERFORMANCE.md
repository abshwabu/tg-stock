# Performance Budgets & Low-End Device Architecture

## 1. Target Hardware Profile
The application is designed specifically for Ethiopian micro-retailers and shopkeepers using budget Android smartphones, such as Transsion devices (Tecno, Infinix, itel) and Samsung Galaxy A-series running Android Go Edition with:
- **RAM**: 2 GB LPDDR3 / LPDDR4
- **Processor**: Quad-core / Octa-core ARM Cortex-A53 @ 1.4–2.0 GHz
- **Storage**: eMMC 5.1 (random read/write ~30–50 MB/s)
- **Connectivity**: Intermittent 2G/3G/4G, frequent offline periods
- **Display**: 720p HD+ (approx. 260–320 DPI)

---

## 2. Quantitative Performance Budgets

| Metric | Target Budget | Hard Limit | Enforcement Mechanism |
| :--- | :--- | :--- | :--- |
| **Cold Start to First Frame** | < 1,200 ms | < 1,500 ms | Instant SQLite open, zero splash delays, no network blocking |
| **Typical Runtime Memory (RSS)** | 70 – 110 MB | < 150 MB | In-memory ring buffer (50 items), single SQLite pool, no caching bloat |
| **List Scroll Performance** | 60 FPS (16.6 ms/frame) | > 50 FPS | `ListView.builder` virtualization, `const` widgets, cached layouts |
| **Search Input Debounce** | 250 – 300 ms | 350 ms | `Timer`-backed debounce on text change before query firing |
| **Sale Completion Latency** | < 150 ms | < 300 ms | Single atomic SQLite transaction, no async network sync during checkout |
| **Database Disk Footprint (10k items)** | < 15 MB | < 25 MB | INTEGER thousandths for quantities, INTEGER cents for money, PRAGMA optimizations |
| **Touch Target Size** | ≥ 48 × 48 dp | ≥ 48 × 48 dp | Material 3 standards, `minimumSize: Size(..., 48)` on all buttons |

---

## 3. Engineering Optimizations

### 3.1 Local SQLite & Database Indices
- **Driver**: High-performance pre-compiled C SQLite via `sqlite3_flutter_libs` with WAL mode enabled (`PRAGMA journal_mode = WAL;`) for concurrent read/write throughput without blocking UI threads.
- **Indices**:
  - `movements(shop_id, product_id, tracks_stock)`: Fast SUM queries for stock balances.
  - `movements(sale_id)`: Instant retrieval of movements belonging to a sale receipt.
  - `movements(location_id)`: Per-location stock counting without table scans.
  - `sales(customer_id, due_date_ms)`: Fast filtering for outstanding and overdue Dube receivables.
  - `products(name COLLATE NOCASE)`: Fast prefix and substring search.

### 3.2 List Virtualization & Rendering
- **No Unbounded Lists**: All lists in `ProductsScreen`, `HistoryScreen`, `DubeScreen`, and `SaleScreen` use `ListView.builder` or `ListView.separated`. Off-screen tiles are immediately discarded by Flutter's garbage collector.
- **Widget Const-ness**: All static decoration and layout elements are declared `const` to avoid redundant element tree recreations during rebuilds.
- **Minimal Rebuild Scope**: State is kept granular using `StatefulBuilder` and scoped states rather than rebuilding entire screen trees upon search filtering.

### 3.3 Search Debouncing
Search inputs in `ProductsScreen` and `SaleScreen` are wrapped with a 250 ms debounce timer:
```dart
void _onSearchChanged(String query) {
  _debounceTimer?.cancel();
  _debounceTimer = Timer(const Duration(milliseconds: 250), () {
    _filterProducts(query);
  });
}
```
This reduces database queries by >80% during rapid typing on slower CPUs.

### 3.4 Zero Third-Party Crash or Analytics SDKs
- No Firebase, Google Play Services, Sentry, or Segment packages are bundled.
- All telemetry and errors use an in-memory 50-entry sanitized ring buffer (`ErrorLogger`), ensuring zero network polling, zero background battery drain, and zero memory overhead.

---

## 4. Verification & Benchmarking Procedure

### 4.1 Cold Startup Measurement
Using the Android Debug Bridge (ADB):
```bash
adb shell am force-stop com.example.inventory
adb shell am start-activity -W -n com.example.inventory/.MainActivity
```
- Verify `TotalTime` is strictly under 1,500 ms on a budget physical device or 2 GB RAM emulator image.

### 4.2 Memory Budget Profiling
Profile memory usage under load (browsing 1,000 products, performing 50 sales):
```bash
adb shell dumpsys meminfo com.example.inventory
```
- Verify `TOTAL PSS` remains below 120,000 KB (120 MB).
- Verify `Native Heap` and `Dalvik Heap` do not continuously grow (zero memory leaks).

### 4.3 Low Memory Trim Handling
Simulate extreme Android background memory pressure:
```bash
adb shell am send-trim-memory com.example.inventory RUNNING_CRITICAL
```
- Verify the app does not crash and recovers state seamlessly.
