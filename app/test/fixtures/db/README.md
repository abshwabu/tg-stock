# Database upgrade fixtures

Each release adds a fixture directory named `v<semver>/` containing the previous schema and a populated SQLite database (or a deterministic SQL seed when binary files are unsuitable for source control). The upgrade test installs that schema, opens it with `AppDatabase`, runs migrations, and asserts stock, sale totals, Dube balances, and report totals.

The first release fixture is `v1.0.0/fixture.sql`. Copy the fixture before changing the schema and update the expected values in the migration test.

