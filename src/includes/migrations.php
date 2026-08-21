<?php
/**
 * src/includes/migrations.php
 * Bindeglied zum Migrationslauf.
 *
 * Bisher lief der vollstaendige Durchlauf bei jedem Request - 16 Dateien,
 * jeweils eine Abfrage gegen migration_log. Jetzt genuegt eine Dateipruefung,
 * solange sich der Satz an Migrationen nicht geaendert hat.
 *
 * Fuer Deployments ist `php bin/migrate.php` der vorgesehene Weg. Der
 * automatische Lauf bleibt als Netz aktiv und laesst sich mit AUTO_MIGRATE=0
 * abschalten.
 */

require_once __DIR__ . '/../bootstrap.php';

use App\Support\Database;
use App\Support\Migrator;

function run_all_migrations(): void {
    static $geprueft = false;
    if ($geprueft) {
        return;
    }
    $geprueft = true;

    $migrator = new Migrator();

    if ($migrator->isUpToDate()) {
        return;
    }

    if (env('AUTO_MIGRATE', '1') !== '1') {
        error_log('Es stehen Migrationen aus. AUTO_MIGRATE ist deaktiviert - bitte php bin/migrate.php ausführen.');
        return;
    }

    try {
        $ergebnis = $migrator->migrate(Database::connection());
    } catch (\Throwable $e) {
        error_log('Automatischer Migrationslauf fehlgeschlagen: ' . $e->getMessage());
        return;
    }

    foreach ($ergebnis['fehler'] as $fehler) {
        error_log('Migration: ' . $fehler);
    }
}
