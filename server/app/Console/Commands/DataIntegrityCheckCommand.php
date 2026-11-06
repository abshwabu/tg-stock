<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DataIntegrityCheckCommand extends Command
{
    protected $signature = 'data:check {--json : Output machine-readable JSON}';
    protected $description = 'Run read-only ledger, payment, reference, and outbox integrity checks';

    public function handle(): int
    {
        $checks = [
            'sqlite' => $this->scalar("SELECT CASE WHEN (SELECT COUNT(*) FROM pragma_integrity_check WHERE integrity_check <> 'ok') = 0 THEN 1 ELSE 0 END"),
            'orphan_movements' => $this->scalar('SELECT COUNT(*) FROM movements m LEFT JOIN products p ON p.id=m.product_id WHERE p.id IS NULL'),
            'orphan_locations' => $this->scalar('SELECT COUNT(*) FROM movements m LEFT JOIN locations l ON l.id=m.location_id WHERE m.location_id IS NOT NULL AND l.id IS NULL'),
            'orphan_payments' => $this->scalar('SELECT COUNT(*) FROM payments p LEFT JOIN sales s ON s.id=p.sale_id WHERE s.id IS NULL'),
            'overpaid_sales' => $this->scalar('SELECT COUNT(*) FROM (SELECT s.id FROM sales s LEFT JOIN payments p ON p.sale_id=s.id WHERE s.voided_at_ms IS NULL GROUP BY s.id HAVING COALESCE(SUM(p.amount_cents),0) > s.total_cents)'),
        ];
        if (Schema::hasTable('product_materials')) {
            $checks['orphan_materials'] = $this->scalar('SELECT COUNT(*) FROM product_materials pm LEFT JOIN products p ON p.id=pm.product_id LEFT JOIN products m ON m.id=pm.material_product_id WHERE p.id IS NULL OR m.id IS NULL');
        } else {
            $checks['orphan_materials'] = 0;
        }
        $checks['orphan_outbox'] = Schema::hasTable('outbox_entries')
            ? $this->scalar("SELECT COUNT(*) FROM outbox_entries o WHERE (o.entity='product' AND NOT EXISTS (SELECT 1 FROM products p WHERE p.id=o.entity_id)) OR (o.entity='movement' AND NOT EXISTS (SELECT 1 FROM movements m WHERE m.id=o.entity_id)) OR (o.entity='sale' AND NOT EXISTS (SELECT 1 FROM sales s WHERE s.id=o.entity_id)) OR (o.entity='payment' AND NOT EXISTS (SELECT 1 FROM payments p WHERE p.id=o.entity_id)) OR (o.entity='location' AND NOT EXISTS (SELECT 1 FROM locations l WHERE l.id=o.entity_id))")
            : 0;

        $failed = array_filter($checks, static fn ($value, $key) => $key === 'sqlite' ? (int) $value !== 1 : (int) $value !== 0, ARRAY_FILTER_USE_BOTH);
        if ($this->option('json')) {
            $this->line((string) json_encode(['clean' => $failed === [], 'checks' => $checks], JSON_PRETTY_PRINT));
        } else {
            foreach ($checks as $name => $value) {
                $ok = $name === 'sqlite' ? (int) $value === 1 : (int) $value === 0;
                $this->line(($ok ? 'PASS' : 'FAIL') . " {$name}: {$value}");
            }
        }
        return $failed === [] ? self::SUCCESS : self::FAILURE;
    }

    private function scalar(string $sql): int
    {
        $row = DB::selectOne($sql);
        $values = array_values((array) $row);
        return (int) ($values[0] ?? 0);
    }
}
