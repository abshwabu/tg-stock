-- Deterministic populated fixture for the v1.0.0 migration test.
-- The test creates the complete legacy schema, then applies this seed.
INSERT INTO products (id, name, unit, cost_price_cents, sell_price_cents, updated_at_ms)
VALUES ('fixture-product-1', 'Fixture Sugar', 'kg', 1000, 1500, 1700000000000);
INSERT INTO movements (id, product_id, type, qty_milli, unit_price_cents, created_at_ms)
VALUES ('fixture-movement-1', 'fixture-product-1', 'in', 10000, 1000, 1700000000000);

