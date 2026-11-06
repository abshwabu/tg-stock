# QA test matrix

Run the automated suite before each tag:

```bash
cd app && flutter analyze && flutter test
cd ../server && php artisan test
```

| Area | Android 8 | Android 10–12 | Android 13–14 | 2 GB RAM | 320 dp | 200% text | Amharic | English | Light | Dark | Offline | Online |
|---|---|---|---|---|---|---|---|---|---|---|---|---|
| First run and navigation | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ |
| Add product/service/consumable | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ |
| Sale, payment, Dube and repayment | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ |
| Locations, transfers and stock adjustment | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ |
| Backup, restore and export | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ |
| Pairing, sync and Telegram bot | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ |
| Data check report is clean | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ |

Record device model, Android version, build number, result, and issue ID for every checked cell. A blank cell is not a pass.

