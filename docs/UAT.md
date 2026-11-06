# Shopkeeper UAT script

Tester: __________  Device 1: __________  Device 2: __________  Build: __________  Date: __________

Mark each step **Pass** or **Fail** and attach the Data check report when a step fails.

| # | Shopkeeper action | Expected result | Pass/Fail |
|---:|---|---|---|
| 1 | Complete first run in Amharic and English | Shop name and language persist; no backup prompt appears | ☐ |
| 2 | Add a stockable product with opening stock | Product and quantity appear in Products and stock report | ☐ |
| 3 | Add a service | Service can be sold and does not change stock | ☐ |
| 4 | Add a consumable and recipe material | Recipe saves; sale consumes the configured material | ☐ |
| 5 | Complete a cash sale | Receipt, movement, payment, and stock decrease match | ☐ |
| 6 | Complete a bank sale and a wallet sale | Provider/reference is recorded and totals reconcile | ☐ |
| 7 | Make a partial Dube sale, then record repayment | Outstanding balance and repayment history are correct | ☐ |
| 8 | Stock in a product | Stock increases by the entered quantity | ☐ |
| 9 | Adjust stock | Append-only adjustment changes the computed balance | ☐ |
| 10 | Transfer stock warehouse → store | Source decreases, destination increases, and both ledgers show the transfer | ☐ |
| 11 | Cross the low-stock threshold | Low-stock notification/banner is generated once per reminder window | ☐ |
| 12 | Enable daily summary | Summary is scheduled at the selected time and uses correct totals | ☐ |
| 13 | Export products, sales, and movements | CSV files open and contain the displayed records | ☐ |
| 14 | Create backup, copy it to phone 2, restore | Backup validates before restore; balances and reports are unchanged | ☐ |
| 15 | Pair with Telegram and sync | Products, stock, sales, and changes appear in the bot | ☐ |
| 16 | Disable network during sync, then reconnect | Offline work continues; pending outbox resumes without duplicates | ☐ |
| 17 | Test staff permissions | Restricted staff cannot perform disallowed actions or see protected data | ☐ |
| 18 | Settings → About → Data check | Report is CLEAN; copy report works | ☐ |

## Sign-off

- [ ] Two physical low-end Android devices completed the script.
- [ ] No unresolved data-integrity issue remains.
- Tester signature: ____________________  Reviewer: ____________________

