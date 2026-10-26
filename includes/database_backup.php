<?php

date_default_timezone_set('Asia/Manila');

function scholarlinkDatabaseConfig(): array
{
    global $host, $port, $db, $user, $pass;

    return [
        'host' => (string)$host,
        'port' => (int)$port,
        'database' => (string)$db,
        'username' => (string)$user,
        'password' => (string)$pass,
    ];
}

function scholarlinkMysqlBinary(string $name): string
{
    $extension = PHP_OS_FAMILY === 'Windows' ? '.exe' : '';
    $candidates = [
        dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'mysql' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . $name . $extension,
        'C:\\xampp\\mysql\\bin\\' . $name . $extension,
        $name . $extension,
    ];

    foreach ($candidates as $candidate) {
        if ($candidate === $name . $extension || is_file($candidate)) {
            return $candidate;
        }
    }

    throw new RuntimeException("Unable to locate {$name}. Use the database tools bundled with XAMPP.");
}

function scholarlinkRunDatabaseCommand(array $command, ?string $stdinFile = null): void
{
    $descriptorSpec = [
        0 => $stdinFile === null ? ['pipe', 'r'] : ['file', $stdinFile, 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $environment = getenv();
    $config = scholarlinkDatabaseConfig();
    if ($config['password'] !== '') {
        $environment['MYSQL_PWD'] = $config['password'];
    }

    $process = proc_open($command, $descriptorSpec, $pipes, null, $environment, ['bypass_shell' => true]);
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start the XAMPP database utility.');
    }

    if ($stdinFile === null) {
        fclose($pipes[0]);
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    if ($exitCode !== 0) {
        $details = trim($stderr ?: $stdout);
        throw new RuntimeException($details !== '' ? $details : "Database utility exited with code {$exitCode}.");
    }
}

function scholarlinkCreateDatabaseBackup(string $destination): int
{
    $config = scholarlinkDatabaseConfig();
    $directory = dirname($destination);
    if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create the backup directory.');
    }

    $rawFile = $destination . '.dumping';
    $finalTemp = $destination . '.part';
    @unlink($rawFile);
    @unlink($finalTemp);

    $command = [
        scholarlinkMysqlBinary('mysqldump'),
        '--host=' . $config['host'],
        '--port=' . $config['port'],
        '--user=' . $config['username'],
        '--default-character-set=utf8mb4',
        '--single-transaction',
        '--quick',
        '--routines',
        '--triggers',
        '--events',
        '--hex-blob',
        '--add-drop-table',
        '--result-file=' . $rawFile,
        $config['database'],
    ];

    try {
        scholarlinkRunDatabaseCommand($command);
        if (!is_file($rawFile) || filesize($rawFile) === 0) {
            throw new RuntimeException('The database utility produced an empty backup.');
        }

        $input = fopen($rawFile, 'rb');
        $output = fopen($finalTemp, 'wb');
        if ($input === false || $output === false) {
            throw new RuntimeException('Unable to finalize the backup file.');
        }
        fwrite($output, "-- ScholarLink Database Backup\n");
        fwrite($output, '-- Generated: ' . date('Y-m-d H:i:s') . "\n\n");
        stream_copy_to_stream($input, $output);
        fclose($input);
        fclose($output);

        if (!rename($finalTemp, $destination)) {
            throw new RuntimeException('Unable to move the completed backup into place.');
        }
    } finally {
        @unlink($rawFile);
        @unlink($finalTemp);
    }

    return (int)filesize($destination);
}

function scholarlinkValidateBackupFile(string $path, string $originalName = ''): void
{
    if (!is_file($path) || filesize($path) === 0) {
        throw new RuntimeException('The selected backup is empty or unreadable.');
    }
    if (filesize($path) > 100 * 1024 * 1024) {
        throw new RuntimeException('Backup files must not exceed 100 MB.');
    }
    if ($originalName !== '' && strtolower(pathinfo($originalName, PATHINFO_EXTENSION)) !== 'sql') {
        throw new RuntimeException('Only .sql backup files are accepted.');
    }

    $handle = fopen($path, 'rb');
    $header = $handle === false ? '' : fread($handle, 65536);
    if (is_resource($handle)) {
        fclose($handle);
    }
    $recognizedMarkers = [
        'ScholarLink Database Backup',
        'ScholarLink Automated Daily Backup',
        'MariaDB dump',
        'MySQL dump',
        'phpMyAdmin SQL Dump',
    ];
    $recognized = false;
    foreach ($recognizedMarkers as $marker) {
        if (str_contains($header, $marker)) {
            $recognized = true;
            break;
        }
    }
    if (!$recognized || (!str_contains($header, 'CREATE TABLE') && !str_contains($header, 'INSERT INTO'))) {
        throw new RuntimeException('This file is not a recognized ScholarLink database backup.');
    }
}

function scholarlinkRestoreDatabaseBackup(string $path): void
{
    $config = scholarlinkDatabaseConfig();
    $command = [
        scholarlinkMysqlBinary('mysql'),
        '--host=' . $config['host'],
        '--port=' . $config['port'],
        '--user=' . $config['username'],
        '--default-character-set=utf8mb4',
        '--database=' . $config['database'],
    ];
    scholarlinkRunDatabaseCommand($command, $path);
}

function scholarlinkBackupDirectory(): string
{
    $envPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env';
    $env = is_file($envPath) ? (parse_ini_file($envPath) ?: []) : [];
    $configuredPath = trim($env['BACKUP_DIRECTORY'] ?? '');
    if ($configuredPath !== '') {
        return rtrim($configuredPath, '\\/');
    }

    // C:\xampp\scholarlink_backups on a standard XAMPP installation.
    // This stays outside htdocs so SQL files cannot be downloaded by URL.
    return dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'scholarlink_backups';
}

function scholarlinkPruneAutomaticBackups(int $retentionDays = 7): void
{
    $cutoff = time() - ($retentionDays * 86400);
    foreach (glob(scholarlinkBackupDirectory() . DIRECTORY_SEPARATOR . 'AutoBackup_*.sql') ?: [] as $file) {
        if (is_file($file) && filemtime($file) < $cutoff) {
            @unlink($file);
        }
    }
}
