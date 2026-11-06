# QA release report

This report is completed for each release tag. It must contain evidence, not assumptions.

## Build

- Tag: __________
- Commit: __________
- Version/build: __________
- Tester/date: __________

## Automated gates

| Gate | Command/evidence | Result |
|---|---|---|
| Flutter analysis | `flutter analyze` | ☐ Pass ☐ Fail |
| Flutter tests | `flutter test` | ☐ Pass ☐ Fail |
| Laravel tests | `php artisan test` | ☐ Pass ☐ Fail |
| Dependency audit | `osv-scanner`, `composer audit` | ☐ Pass ☐ Fail |
| Release artifacts | Checksums for AAB, arm64, armv7, universal APK | ☐ Pass ☐ Fail |
| Migrated fixture Data check | Report attached | ☐ Clean ☐ Issues |

## Physical UAT

Attach the completed [UAT script](UAT.md) for two low-end phones. Record model, Android version, RAM, and result here:

1. Device: __________ Result: ☐ Pass ☐ Fail
2. Device: __________ Result: ☐ Pass ☐ Fail

## Known limitations and open issues

Document every limitation with an issue ID, affected versions/devices, workaround, and release decision. An unknown or untriaged stock, payment, Dube, or sync discrepancy blocks release.

## Release decision

- [ ] All automated gates pass.
- [ ] UAT passes on two physical low-end devices.
- [ ] Data check is clean on migrated fixtures.
- [ ] No open data-integrity issues.
- Decision: ☐ Release ☐ Hold

