<?php

require __DIR__.'/../vendor/autoload.php';
define('BASE_DIR', dirname(__DIR__).'/');
$config = require BASE_DIR.'config.php';
$dsn = dsnFromConfig($config);
if (($config['db']['connection'] ?? 'sqlite') === 'sqlite') {
    $path = substr($dsn, strlen('sqlite:'));
    if (!is_file($path) || filesize($path) === 0) {
        throw new RuntimeException('The configured SQLite database is missing or empty. Check the existing volume mapping; no database was created.');
    }
}
$db = new App\Database\DB($dsn, $config['db']['username'] ?? null, $config['db']['password'] ?? null);
$pending = false;
try {
    $applied = $db->query('SELECT name FROM migrations WHERE migrated=1')->fetchAll(PDO::FETCH_COLUMN);
    foreach (glob(BASE_DIR.'resources/schemas/'.$db->getCurrentDriver().'/*.sql') as $migration) {
        if (!in_array(basename($migration), $applied, true)) {
            $pending = true;
        }
    }
} catch (PDOException) {
    $pending = true;
}
if ($db->getCurrentDriver() === 'sqlite' && $pending) {
    $backupDir = '/config/backups';
    if (!is_dir($backupDir)) {
        mkdir($backupDir, 0700, true);
    }
    $backup = $backupDir.'/xbackbone-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(3)).'.db';
    $db->getPdo()->exec('VACUUM INTO '.$db->getPdo()->quote($backup));
}
// Schema-only migrations: never recalculate quotas or remove records on startup.
(new App\Database\Migrator($db, BASE_DIR.'resources/schemas'))->migrate();
echo "Schema migrations complete; existing users, tokens, links and storage paths retained.\n";
