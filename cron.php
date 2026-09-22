<?php

declare(strict_types=1);

/**
 * Scheduler entry point: runs every active monitor that is due.
 *
 * CLI (preferred):  php cron.php
 * HTTP (fallback):  cron.php?token=<settings.cronToken>
 *
 * Call it every minute; per-monitor intervals are honoured by sg_is_due().
 */

require_once __DIR__ . '/lib.php';

$isCli = PHP_SAPI === 'cli';

try {
    $settings = sg_bootstrap();
} catch (Throwable $e) {
    if (!$isCli) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
    }
    echo "cron: cannot load settings\n";
    error_log('sensorgrid cron: ' . $e->getMessage());
    exit(1);
}

if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    $given = (string)($_GET['token'] ?? '');
    // Same answer for "no token configured", "missing" and "wrong": nothing to learn from the response.
    if ($settings['cronToken'] === '' || !hash_equals((string)$settings['cronToken'], $given)) {
        http_response_code(403);
        echo "Forbidden\n";
        exit;
    }
    set_time_limit(300);
    ignore_user_abort(true);
} else {
    set_time_limit(0);
}

// A slow run must not overlap the next minute's tick, or monitors would be checked (and emailed) twice.
$lock = sg_lock_acquire();
if ($lock === null) {
    sg_log('cron: skipped: already running');
    echo "cron: skipped: already running\n";
    exit(0);
}

$exit = 0;
try {
    $summary = sg_run_due_checks($settings);
    $line    = sprintf(
        'cron: checked=%d changed=%d errors=%d skipped=%d in %.2fs',
        $summary['checked'], $summary['changed'], $summary['errors'], $summary['skipped'], $summary['duration']
    );
    sg_settings_update(static function (array $cur) use ($summary): array {
        $cur['lastCronRun']      = sg_now();
        $cur['lastCronDuration'] = $summary['duration'];
        return $cur;
    });
    sg_log($line);
    echo $line . "\n";
} catch (Throwable $e) {
    $exit = 1;
    sg_log('cron: FAILED: ' . $e->getMessage());
    if (!$isCli) {
        http_response_code(500);
    }
    echo "cron: failed (see data/cron.log)\n";
} finally {
    sg_lock_release($lock);
}
exit($exit);
