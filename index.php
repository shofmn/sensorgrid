<?php

declare(strict_types=1);

/**
 * Sensor Grid UI: one page, plus every form/AJAX action it posts to itself.
 * Order: bootstrap → handle POST → load view data → render.
 */

require_once __DIR__ . '/lib.php';

// ---------- BOOTSTRAP ----------

$settings = sg_bootstrap();

session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS'])]);
session_start();
if (empty($_SESSION['sg_csrf'])) {
    $_SESSION['sg_csrf'] = bin2hex(random_bytes(16));
}
$csrf = (string)$_SESSION['sg_csrf'];

// Everything is served from this folder; the CSP makes "no external requests" enforceable, not just intended.
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
header('Cache-Control: no-store');

// ---------- ACTION HELPERS ----------

/** Queue a toast for the next page load. */
function sg_flash(string $type, string $message): void
{
    $_SESSION['sg_flash'][] = ['type' => $type, 'message' => $message];
}

/** Queue one error toast per message. */
function sg_flash_errors(array $errors): void
{
    foreach ($errors as $e) {
        sg_flash('error', $e);
    }
}

/** Emit a JSON response and stop. */
function sg_json_out(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

/** Find a monitor by id. */
function sg_find_page(string $id): ?array
{
    foreach (sg_pages_read() as $page) {
        if ($page['id'] === $id) {
            return $page;
        }
    }
    return null;
}

/** Add a monitor from the form. */
function sg_action_add(array $post): void
{
    $v = sg_validate_monitor($post);
    if ($v['errors'] !== []) {
        sg_flash_errors($v['errors']);
        $_SESSION['sg_form'] = ['id' => '', 'values' => $v['values']];
        return;
    }
    $x    = $v['values'];
    $page = sg_page_new($x['name'], $x['url'], $x['elementId'], $x['intervalMinutes']);
    sg_pages_update(static fn(array $pages): array => [...$pages, $page]);
    sg_flash('ok', 'Monitor "' . $x['name'] . '" added. Its first check will store a baseline (no email).');
}

/** Edit a monitor; a new URL or element re-baselines instead of reporting a bogus change. */
function sg_action_update(array $post): void
{
    $id = (string)($post['id'] ?? '');
    $v  = sg_validate_monitor($post);
    if ($v['errors'] !== []) {
        sg_flash_errors($v['errors']);
        $_SESSION['sg_form'] = ['id' => $id, 'values' => $v['values']];
        return;
    }
    $x = $v['values'];
    $found = null;
    sg_pages_update(static function (array $pages) use ($id, $x, &$found): array {
        foreach ($pages as $i => $p) {
            if ($p['id'] !== $id) {
                continue;
            }
            $retarget = $p['url'] !== $x['url'] || $p['elementId'] !== $x['elementId'];
            $p = array_merge($p, $x);
            if ($retarget) {
                $p = array_merge($p, [
                    'lastStatus' => $p['active'] ? 'new' : 'paused', 'lastError' => '', 'lastHttpCode' => 0,
                    'contentHash' => '', 'consecutiveFailures' => 0, 'failureNotified' => false,
                    'lastCheckAt' => null, // due on the next tick, so the new baseline arrives promptly
                ]);
            }
            $pages[$i] = $p;
            $found     = ['page' => $p, 'retarget' => $retarget];
        }
        return $pages;
    });
    if ($found === null) {
        sg_flash('error', 'That monitor no longer exists.');
        return;
    }
    if ($found['retarget']) {
        sg_delete_site_files($found['page'], false);
    }
    sg_flash('ok', 'Monitor updated.' . ($found['retarget'] ? ' Target changed, so the next check re-baselines.' : ''));
}

/** Pause or resume a monitor. */
function sg_action_toggle(array $post): void
{
    $id = (string)($post['id'] ?? '');
    sg_pages_update(static function (array $pages) use ($id): array {
        foreach ($pages as $i => $p) {
            if ($p['id'] === $id) {
                $pages[$i]['active']     = !$p['active'];
                $pages[$i]['lastStatus'] = $p['active'] ? 'paused' : ($p['lastCheckAt'] === null ? 'new' : 'ok');
            }
        }
        return $pages;
    });
    sg_flash('info', 'Monitor state changed.');
}

/** Delete a monitor together with its snapshot and history. */
function sg_action_delete(array $post): void
{
    $id    = (string)($post['id'] ?? '');
    $gone  = null;
    sg_pages_update(static function (array $pages) use ($id, &$gone): array {
        foreach ($pages as $i => $p) {
            if ($p['id'] === $id) {
                $gone = $p;
                unset($pages[$i]);
            }
        }
        return $pages;
    });
    if ($gone !== null) {
        sg_delete_site_files($gone);
        sg_flash('info', 'Monitor "' . $gone['name'] . '" deleted.');
    }
}

/**
 * Run one monitor now (a full run: it can email).
 *
 * @param array<string,mixed> $settings
 */
function sg_action_check(array $post, array $settings): void
{
    $site = sg_find_page((string)($post['id'] ?? ''));
    if ($site === null) {
        sg_flash('error', 'That monitor no longer exists.');
        return;
    }
    $lock = sg_lock_acquire();
    if ($lock === null) {
        sg_flash('error', 'A check run is already in progress. Try again in a moment.');
        return;
    }
    try {
        set_time_limit(300);
        $r = sg_check_site_guarded($site, $settings);
        sg_pages_apply_results([$site['id'] => $r['site']]);
    } finally {
        sg_lock_release($lock);
    }
    $name = $site['name'];
    switch ($r['status']) {
        case 'new':
            sg_flash('ok', "$name: baseline stored. No email is sent for a first check.");
            break;
        case 'ok':
            sg_flash('ok', "$name: no change.");
            break;
        case 'changed':
            $mailNote = ($r['mail']['ok'] ?? false) ? 'Email sent.' : 'Email FAILED: ' . ($r['mail']['error'] ?? 'unknown');
            sg_flash(($r['mail']['ok'] ?? false) ? 'ok' : 'error', "$name: change detected (+{$r['diff']['added']} / −{$r['diff']['removed']}). $mailNote");
            break;
        default:
            sg_flash('error', "$name: {$r['error']}");
    }
}

/**
 * Run every due monitor now, through the same code path as cron.
 *
 * @param array<string,mixed> $settings
 */
function sg_action_check_all_due(array $settings): void
{
    $lock = sg_lock_acquire();
    if ($lock === null) {
        sg_flash('error', 'A check run is already in progress. Try again in a moment.');
        return;
    }
    try {
        set_time_limit(300);
        $s = sg_run_due_checks($settings);
    } finally {
        sg_lock_release($lock);
    }
    sg_flash($s['errors'] > 0 ? 'error' : 'ok', sprintf(
        'Checked %d, changed %d, errors %d, not due %d (%.1fs).',
        $s['checked'], $s['changed'], $s['errors'], $s['skipped'], $s['duration']
    ));
}

/**
 * Dry run for the Test Run button: fetch + extract only, nothing is stored or sent.
 *
 * @param array<string,mixed> $settings
 */
function sg_action_test(array $post, array $settings): never
{
    // Only the target matters for a dry run; name and interval are not sent, so default them.
    $v = sg_validate_monitor($post + ['intervalMinutes' => 60], false);
    if ($v['errors'] !== []) {
        sg_json_out(['ok' => false, 'error' => implode(' ', $v['errors'])]);
    }
    set_time_limit(120);
    $site = sg_page_normalize(['id' => 'test', 'name' => 'test'] + $v['values']);
    $r    = sg_check_site($site, $settings, true);
    sg_json_out(['ok' => $r['status'] === 'test', 'error' => $r['error']] + $r['test']);
}

/**
 * Persist the settings form.
 *
 * @param array<string,mixed> $settings
 */
function sg_action_save_settings(array $post, array $settings): void
{
    $r = sg_settings_from_post($post, $settings);
    if ($r['errors'] !== []) {
        sg_flash_errors($r['errors']);
        return;
    }
    $new = $r['settings'];
    sg_settings_update(static function (array $cur) use ($new): array {
        // Values owned by the app, not the form; take the live ones in case cron ran meanwhile.
        foreach (['version', 'cronToken', 'lastCronRun', 'lastCronDuration'] as $k) {
            $new[$k] = $cur[$k];
        }
        return $new;
    });
    sg_flash('ok', 'Settings saved.');
}

/**
 * Send a test message using the form's current (possibly unsaved) values.
 *
 * @param array<string,mixed> $settings
 */
function sg_action_send_test_mail(array $post, array $settings): never
{
    $r = sg_settings_from_post($post, $settings);
    if ($r['errors'] !== []) {
        sg_json_out(['ok' => false, 'error' => implode(' ', $r['errors'])]);
    }
    $s    = $r['settings'];
    $when = sg_format_dt(sg_now());
    $res  = sg_send_mail(
        '[Sensor Grid] Test email',
        '<p style="font:14px Arial,sans-serif">Sensor Grid test email, sent ' . sg_e($when) . ' via <b>' . sg_e($s['mail']['transport']) . '</b>. If you can read this, notifications work.</p>',
        "Sensor Grid test email, sent $when via {$s['mail']['transport']}. If you can read this, notifications work.\n",
        $s
    );
    sg_json_out($res + ['transport' => $s['mail']['transport'], 'to' => $s['notifyEmail']]);
}

/**
 * Send a Telegram test message using the form's current values, even before Telegram is enabled.
 *
 * @param array<string,mixed> $settings
 */
function sg_action_send_test_telegram(array $post, array $settings): never
{
    // Validate as if disabled, so an unchecked box is no obstacle; then force-enable for this one send.
    $r = sg_settings_from_post(['telegramEnabled' => ''] + $post, $settings);
    if ($r['errors'] !== []) {
        sg_json_out(['ok' => false, 'error' => implode(' ', $r['errors'])]);
    }
    $s = $r['settings'];
    $s['telegram']['enabled'] = true;
    $when = sg_format_dt(sg_now());
    sg_json_out(sg_tg_send('✅ <b>Sensor Grid</b> test message, sent ' . sg_e($when) . '. If you can read this, Telegram notifications work.', $s));
}

/**
 * Look up the chat id of whoever last messaged the bot (token from the form, else the stored one).
 *
 * @param array<string,mixed> $settings
 */
function sg_action_detect_telegram_chat(array $post, array $settings): never
{
    $token = trim((string)($post['telegramToken'] ?? '')) ?: (string)$settings['telegram']['botToken'];
    sg_json_out(sg_tg_detect_chat($token, $settings));
}

// ---------- HANDLE POST ----------

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $isAjax = in_array($action, ['test', 'sendTestMail', 'sendTestTelegram', 'detectTelegramChat'], true);

    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        if ($isAjax) {
            sg_json_out(['ok' => false, 'error' => 'Invalid CSRF token — reload the page.'], 400);
        }
        http_response_code(400);
        header('Content-Type: text/plain; charset=utf-8');
        exit("Bad request: invalid CSRF token. Reload the page and try again.\n");
    }

    try {
        switch ($action) {
            case 'add':          sg_action_add($_POST); break;
            case 'update':       sg_action_update($_POST); break;
            case 'toggle':       sg_action_toggle($_POST); break;
            case 'delete':       sg_action_delete($_POST); break;
            case 'check':        sg_action_check($_POST, $settings); break;
            case 'checkAllDue':  sg_action_check_all_due($settings); break;
            case 'test':         sg_action_test($_POST, $settings); break;
            case 'saveSettings': sg_action_save_settings($_POST, $settings); break;
            case 'sendTestMail': sg_action_send_test_mail($_POST, $settings); break;
            case 'sendTestTelegram':   sg_action_send_test_telegram($_POST, $settings); break;
            case 'detectTelegramChat': sg_action_detect_telegram_chat($_POST, $settings); break;
            default:
                http_response_code(400);
                exit("Unknown action\n");
        }
    } catch (Throwable $e) {
        sg_log('ui action "' . $action . '" failed: ' . $e->getMessage());
        if ($isAjax) {
            sg_json_out(['ok' => false, 'error' => $e->getMessage()], 500);
        }
        sg_flash('error', 'Action failed: ' . $e->getMessage());
    }

    // POST → redirect → GET, so a refresh never re-submits.
    header('Location: index.php');
    exit;
}

// ---------- VIEW DATA ----------

$flash = $_SESSION['sg_flash'] ?? [];
$form  = $_SESSION['sg_form'] ?? null;
unset($_SESSION['sg_flash'], $_SESSION['sg_form']);

$loadError = null;
try {
    $pages = sg_pages_read();
} catch (Throwable $e) {
    $pages     = [];
    $loadError = $e->getMessage();
}

$now        = time();
$history    = [];
$stats      = ['total' => count($pages), 'active' => 0, 'changed' => 0, 'errors' => 0];
foreach ($pages as $p) {
    $stats['active']  += $p['active'] ? 1 : 0;
    $stats['errors']  += ($p['active'] && $p['lastStatus'] === 'error') ? 1 : 0;
    $stats['changed'] += ($p['lastChange'] !== null && $now - (int)strtotime($p['lastChange']) < 86400) ? 1 : 0;
    try {
        $history[$p['id']] = sg_history_read($p);
    } catch (Throwable) {
        $history[$p['id']] = [];
    }
}

$cronAge     = $settings['lastCronRun'] === null ? null : $now - (int)strtotime((string)$settings['lastCronRun']);
$cronOffline = $cronAge === null || $cronAge > 300;
$mailCfg     = $settings['mail'];
$tgCfg       = $settings['telegram'];
$appBase     = rtrim((string)$settings['appUrl'], '/');
$dataDir     = str_replace('\\', '/', SG_DATA_DIR);
$cronCli     = '* * * * * /usr/bin/php ' . str_replace('\\', '/', SG_ROOT) . '/cron.php >> ' . $dataDir . '/cron.log 2>&1';
$cronHttp    = '* * * * * curl -fsS "' . ($appBase !== '' ? $appBase : 'https://example.com/sensorgrid') . '/cron.php?token=' . $settings['cronToken'] . '" > /dev/null';
$asset       = static fn(string $path): string => $path . '?v=' . (is_file(__DIR__ . '/' . $path) ? filemtime(__DIR__ . '/' . $path) : 0);

/** Render one added/removed block of a history entry. */
function sg_history_block(array $lines, int $total, string $class, string $prefix): string
{
    if ($total === 0) {
        return '';
    }
    $out = '';
    foreach ($lines as $l) {
        $out .= '<span class="' . $class . '">' . $prefix . ' ' . sg_e($l) . '</span>';
    }
    if ($total > count($lines)) {
        $out .= '<span class="diff__more">… and ' . ($total - count($lines)) . ' more</span>';
    }
    return $out;
}

// ---------- RENDER ----------
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= sg_e($csrf) ?>">
    <meta name="robots" content="noindex, nofollow">
    <title>Sensor Grid</title>
    <link rel="icon" href="data:,">
    <link rel="stylesheet" href="<?= sg_e($asset('assets/css/app.css')) ?>">
    <script src="<?= sg_e($asset('assets/js/app.js')) ?>" defer></script>
</head>
<body>
<div class="lcars-frame">

    <aside class="lcars-rail" aria-hidden="true">
        <div class="lcars-rail__elbow"></div>
        <div class="lcars-rail__block lcars-rail__block--lilac">01-4720</div>
        <div class="lcars-rail__block lcars-rail__block--blue">02-7311</div>
        <div class="lcars-rail__block lcars-rail__block--almond lcars-rail__block--grow">03-9068</div>
        <div class="lcars-rail__block lcars-rail__block--orange">04-1183</div>
    </aside>

    <div class="lcars-body">
        <header class="lcars-header">
            <div class="lcars-bar" aria-hidden="true">
                <span class="lcars-bar__seg lcars-bar__seg--orange"></span>
                <span class="lcars-bar__seg lcars-bar__seg--lilac"></span>
                <span class="lcars-bar__seg lcars-bar__seg--blue"></span>
            </div>
            <h1 class="wordmark">Sensor Grid</h1>
            <p class="subtitle">Continuous web surveillance</p>
        </header>

        <main class="lcars-main">

            <div id="toasts" class="toasts" aria-live="polite">
                <?php foreach ($flash as $f): ?>
                    <div class="toast toast--<?= sg_e($f['type']) ?>"><?= sg_e($f['message']) ?></div>
                <?php endforeach; ?>
            </div>

            <?php if ($loadError !== null): ?>
                <section class="lcars-panel lcars-panel--red" role="alert">
                    <h2 class="panel-title">Data file unreadable</h2>
                    <p><?= sg_e($loadError) ?></p>
                    <p class="dim">Fix the JSON by hand or restore it from a backup. Sensor Grid will not overwrite it.</p>
                </section>
            <?php endif; ?>

            <section class="status-strip" aria-label="Status">
                <span class="lcars-pill lcars-pill--lilac"><b><?= $stats['total'] ?></b> Monitors</span>
                <span class="lcars-pill lcars-pill--blue"><b><?= $stats['active'] ?></b> Active</span>
                <span class="lcars-pill lcars-pill--orange"><b><?= $stats['changed'] ?></b> Changed 24h</span>
                <span class="lcars-pill <?= $stats['errors'] > 0 ? 'lcars-pill--red' : 'lcars-pill--grey' ?>"><b><?= $stats['errors'] ?></b> Errors</span>
                <?php if ($cronOffline): ?>
                    <span class="lcars-pill lcars-pill--red is-alert" id="cron-pill" data-cron data-ts="<?= sg_e((string)$settings['lastCronRun']) ?>">
                        Cron offline<?= $settings['lastCronRun'] !== null ? ' · last run <time data-ts="' . sg_e($settings['lastCronRun']) . '">' . sg_e(sg_relative($settings['lastCronRun'])) . '</time>' : '' ?>
                    </span>
                <?php else: ?>
                    <span class="lcars-pill lcars-pill--sky" id="cron-pill" data-cron data-ts="<?= sg_e((string)$settings['lastCronRun']) ?>">
                        Last cron run: <time data-ts="<?= sg_e($settings['lastCronRun']) ?>"><?= sg_e(sg_relative($settings['lastCronRun'])) ?></time>
                    </span>
                <?php endif; ?>
                <form method="post" action="index.php" class="inline-form">
                    <input type="hidden" name="csrf" value="<?= sg_e($csrf) ?>">
                    <input type="hidden" name="action" value="checkAllDue">
                    <button type="submit" class="lcars-btn lcars-btn--gold">Run due checks now</button>
                </form>
            </section>

            <section class="lcars-panel" aria-labelledby="monitors-title">
                <h2 class="panel-title" id="monitors-title">Monitors</h2>
                <?php if ($pages === []): ?>
                    <p class="empty">No monitors yet. Add one below — the first check stores a baseline, changes are reported from the second check on.</p>
                <?php else: ?>
                <div class="table-wrap">
                <table class="lcars-table">
                    <thead>
                        <tr><th>Status</th><th>Monitor</th><th>Interval</th><th>Last check</th><th>Last change</th><th>Actions</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($pages as $p):
                        $st   = $p['active'] ? $p['lastStatus'] : 'paused';
                        $hist = $history[$p['id']] ?? [];
                    ?>
                        <tr class="site-row">
                            <td data-label="Status"><span class="status status--<?= sg_e($st) ?>"><?= sg_e($st) ?></span></td>
                            <td data-label="Monitor" class="cell-main">
                                <span class="site-name"><?= sg_e($p['name']) ?></span>
                                <a class="site-url" href="<?= sg_e($p['url']) ?>" target="_blank" rel="noopener noreferrer"><?= sg_e($p['url']) ?></a>
                                <?php if ($p['elementId'] !== ''): ?><span class="badge"><?= sg_e(sg_element_display($p['elementId'])) ?></span><?php else: ?><span class="badge badge--dim">whole page</span><?php endif; ?>
                                <?php if ($p['active'] && $p['lastStatus'] === 'error'): ?>
                                    <p class="site-error"><?= sg_e($p['lastError']) ?> · <?= (int)$p['consecutiveFailures'] ?> failed in a row</p>
                                <?php endif; ?>
                            </td>
                            <td data-label="Interval"><?= sg_e(sg_interval_label((int)$p['intervalMinutes'])) ?></td>
                            <td data-label="Last check">
                                <time data-ts="<?= sg_e((string)$p['lastCheckAt']) ?>" title="<?= sg_e(sg_format_dt($p['lastCheckAt'])) ?>"><?= sg_e(sg_relative($p['lastCheckAt'])) ?></time>
                                <?php if ($p['lastHttpCode']): ?><span class="dim">HTTP <?= (int)$p['lastHttpCode'] ?></span><?php endif; ?>
                            </td>
                            <td data-label="Last change">
                                <?php if ($p['lastChange']): ?>
                                    <time data-ts="<?= sg_e($p['lastChange']) ?>" title="<?= sg_e(sg_format_dt($p['lastChange'])) ?>"><?= sg_e(sg_relative($p['lastChange'])) ?></time>
                                <?php else: ?><span class="dim">never</span><?php endif; ?>
                            </td>
                            <td data-label="Actions">
                                <form method="post" action="index.php" class="row-actions">
                                    <input type="hidden" name="csrf" value="<?= sg_e($csrf) ?>">
                                    <input type="hidden" name="id" value="<?= sg_e($p['id']) ?>">
                                    <button type="submit" name="action" value="check" class="lcars-btn lcars-btn--sm lcars-btn--orange">Check</button>
                                    <button type="button" class="lcars-btn lcars-btn--sm lcars-btn--blue" data-test data-name="<?= sg_e($p['name']) ?>" data-url="<?= sg_e($p['url']) ?>" data-element="<?= sg_e($p['elementId']) ?>">Test</button>
                                    <button type="button" class="lcars-btn lcars-btn--sm lcars-btn--lilac" data-edit data-id="<?= sg_e($p['id']) ?>" data-name="<?= sg_e($p['name']) ?>" data-url="<?= sg_e($p['url']) ?>" data-element="<?= sg_e($p['elementId']) ?>" data-interval="<?= (int)$p['intervalMinutes'] ?>">Edit</button>
                                    <button type="submit" name="action" value="toggle" class="lcars-btn lcars-btn--sm lcars-btn--grey"><?= $p['active'] ? 'Pause' : 'Resume' ?></button>
                                    <button type="button" class="lcars-btn lcars-btn--sm lcars-btn--almond" data-history="<?= sg_e($p['id']) ?>" aria-expanded="false" aria-controls="history-<?= sg_e($p['id']) ?>">History (<?= count($hist) ?>)</button>
                                    <button type="submit" name="action" value="delete" class="lcars-btn lcars-btn--sm lcars-btn--red" data-confirm="Delete “<?= sg_e($p['name']) ?>” and its history?">Delete</button>
                                </form>
                            </td>
                        </tr>
                        <tr class="history-row" id="history-<?= sg_e($p['id']) ?>" hidden>
                            <td colspan="6">
                                <?php if ($hist === []): ?>
                                    <p class="dim">No changes recorded yet.</p>
                                <?php endif; ?>
                                <?php foreach ($hist as $h): ?>
                                    <article class="history-entry">
                                        <h3 class="history-head"><time title="<?= sg_e(sg_format_dt($h['at'] ?? null)) ?>"><?= sg_e(sg_format_dt($h['at'] ?? null)) ?></time>
                                            <span class="diff-count diff-count--add">+<?= (int)($h['addedCount'] ?? 0) ?></span>
                                            <span class="diff-count diff-count--del">−<?= (int)($h['removedCount'] ?? 0) ?></span></h3>
                                        <pre class="diff"><?= sg_history_block($h['removed'] ?? [], (int)($h['removedCount'] ?? 0), 'diff__del', '−') ?><?= sg_history_block($h['added'] ?? [], (int)($h['addedCount'] ?? 0), 'diff__add', '+') ?></pre>
                                    </article>
                                <?php endforeach; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <?php endif; ?>
            </section>

            <form id="monitor-form" class="lcars-panel lcars-panel--lilac" method="post" action="index.php" aria-labelledby="form-title">
                <h2 class="panel-title" id="form-title">Add monitor</h2>
                <input type="hidden" name="csrf" value="<?= sg_e($csrf) ?>">
                <input type="hidden" name="action" value="<?= $form && $form['id'] !== '' ? 'update' : 'add' ?>" id="f-action">
                <input type="hidden" name="id" value="<?= sg_e($form['id'] ?? '') ?>" id="f-id">
                <div class="field-grid">
                    <div class="field">
                        <label for="f-name">Name</label>
                        <input type="text" id="f-name" name="name" required maxlength="80" placeholder="ACME pricing" value="<?= sg_e($form['values']['name'] ?? '') ?>">
                    </div>
                    <div class="field field--wide">
                        <label for="f-url">URL</label>
                        <input type="url" id="f-url" name="url" required placeholder="https://example.com/pricing" value="<?= sg_e($form['values']['url'] ?? '') ?>">
                    </div>
                    <div class="field">
                        <label for="f-element">Element <span class="dim">(optional)</span></label>
                        <input type="text" id="f-element" name="elementId" maxlength="101" pattern="[#.]?[A-Za-z0-9_:.\-]{0,100}" placeholder="#pricing or .price-box" title="#id watches that one element, .class watches every element with that class — leave empty to watch the whole page" value="<?= sg_e($form['values']['elementId'] ?? '') ?>">
                    </div>
                    <div class="field">
                        <label for="f-interval">Interval (minutes)</label>
                        <input type="number" id="f-interval" name="intervalMinutes" required min="1" max="1440" step="1" value="<?= sg_e($form['values']['intervalMinutes'] ?? 60) ?>">
                        <div class="quick-picks" role="group" aria-label="Quick intervals">
                            <?php foreach ([1 => '1m', 5 => '5m', 15 => '15m', 30 => '30m', 60 => '1h', 360 => '6h', 720 => '12h', 1440 => '24h'] as $min => $label): ?>
                                <button type="button" class="lcars-btn lcars-btn--xs lcars-btn--almond" data-minutes="<?= $min ?>"><?= $label ?></button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <div class="form-actions">
                    <button type="submit" id="f-submit" class="lcars-btn lcars-btn--orange"><?= $form && $form['id'] !== '' ? 'Update monitor' : 'Add monitor' ?></button>
                    <button type="button" id="f-test" class="lcars-btn lcars-btn--blue">Test run</button>
                    <button type="button" id="f-cancel" class="lcars-btn lcars-btn--grey" hidden>Cancel edit</button>
                </div>
                <div id="test-result" class="test-result" aria-live="polite" hidden></div>
            </form>

            <section class="lcars-panel lcars-panel--blue" aria-labelledby="settings-title">
                <h2 class="panel-title">
                    <button type="button" id="settings-toggle" class="panel-toggle" aria-expanded="false" aria-controls="settings-body"><span id="settings-title">Settings</span> <span class="panel-toggle__icon" aria-hidden="true">▸</span></button>
                </h2>
                <div id="settings-body" hidden>
                    <form id="settings-form" method="post" action="index.php">
                        <input type="hidden" name="csrf" value="<?= sg_e($csrf) ?>">
                        <input type="hidden" name="action" value="saveSettings">

                        <h3 class="group-title">Notifications</h3>
                        <div class="field-grid">
                            <div class="field"><label for="s-notify">Notify email</label><input type="email" id="s-notify" name="notifyEmail" value="<?= sg_e($settings['notifyEmail']) ?>" placeholder="you@example.com"></div>
                            <div class="field"><label for="s-from">From email</label><input type="email" id="s-from" name="fromEmail" required value="<?= sg_e($settings['fromEmail']) ?>"></div>
                            <div class="field"><label for="s-fromname">From name</label><input type="text" id="s-fromname" name="fromName" maxlength="80" value="<?= sg_e($settings['fromName']) ?>"></div>
                            <div class="field"><label for="s-appurl">App URL</label><input type="url" id="s-appurl" name="appUrl" value="<?= sg_e($settings['appUrl']) ?>"></div>
                            <div class="field">
                                <label for="s-tz">Timezone</label>
                                <select id="s-tz" name="timezone">
                                    <?php foreach (timezone_identifiers_list() as $tz): ?>
                                        <option value="<?= sg_e($tz) ?>"<?= $tz === $settings['timezone'] ? ' selected' : '' ?>><?= sg_e($tz) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="field">
                                <label for="transport">Mail transport</label>
                                <select id="transport" name="transport">
                                    <?php foreach (['smtp' => 'SMTP (PHPMailer)', 'mail' => 'PHP mail()', 'log' => 'Log file (data/mail.log)'] as $val => $label): ?>
                                        <option value="<?= $val ?>"<?= $val === $mailCfg['transport'] ? ' selected' : '' ?>><?= sg_e($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <fieldset id="smtp-fields" class="smtp-fields"<?= $mailCfg['transport'] === 'smtp' ? '' : ' hidden' ?>>
                            <legend>SMTP</legend>
                            <div class="field-grid">
                                <div class="field"><label for="s-host">Host</label><input type="text" id="s-host" name="smtpHost" value="<?= sg_e($mailCfg['smtpHost']) ?>" placeholder="smtp.example.com"></div>
                                <div class="field"><label for="s-port">Port</label><input type="number" id="s-port" name="smtpPort" min="1" max="65535" value="<?= (int)$mailCfg['smtpPort'] ?>"></div>
                                <div class="field">
                                    <label for="s-secure">Encryption</label>
                                    <select id="s-secure" name="smtpSecure">
                                        <?php foreach (['tls' => 'STARTTLS (587)', 'ssl' => 'SSL/TLS (465)', 'none' => 'None'] as $val => $label): ?>
                                            <option value="<?= $val ?>"<?= $val === $mailCfg['smtpSecure'] ? ' selected' : '' ?>><?= sg_e($label) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="field field--check"><label><input type="checkbox" name="smtpAuth" value="1"<?= $mailCfg['smtpAuth'] ? ' checked' : '' ?>> Authenticate</label></div>
                                <div class="field"><label for="s-user">Username</label><input type="text" id="s-user" name="smtpUser" autocomplete="off" value="<?= sg_e($mailCfg['smtpUser']) ?>"></div>
                                <div class="field"><label for="s-pass">Password</label><input type="password" id="s-pass" name="smtpPass" autocomplete="new-password" placeholder="unchanged" value=""></div>
                            </div>
                        </fieldset>

                        <fieldset class="smtp-fields">
                            <legend>Telegram</legend>
                            <div class="field-grid">
                                <div class="field field--check field--wide"><label><input type="checkbox" id="s-tg-enabled" name="telegramEnabled" value="1"<?= $tgCfg['enabled'] ? ' checked' : '' ?>> Also send notifications to Telegram</label></div>
                            </div>
                            <div id="tg-fields"<?= $tgCfg['enabled'] ? '' : ' hidden' ?>>
                                <div class="field-grid">
                                    <div class="field"><label for="s-tg-token">Bot token</label><input type="password" id="s-tg-token" name="telegramToken" autocomplete="new-password" placeholder="<?= $tgCfg['botToken'] !== '' ? 'unchanged' : '123456789:AA…' ?>" value=""></div>
                                    <div class="field"><label for="s-tg-chat">Chat ID</label><input type="text" id="s-tg-chat" name="telegramChatId" inputmode="numeric" pattern="-?[0-9]{1,20}" value="<?= sg_e($tgCfg['chatId']) ?>"></div>
                                </div>
                                <p class="dim">Create a bot with @BotFather, press Start in its chat, then Detect chat ID. Tip: in @BotFather, <code>/setjoingroups</code> → Disable. The bot never answers anyone; it only sends to this chat ID.</p>
                                <div class="form-actions">
                                    <button type="button" id="tg-detect" class="lcars-btn lcars-btn--sm lcars-btn--lilac">Detect chat ID</button>
                                    <button type="button" id="tg-test" class="lcars-btn lcars-btn--sm lcars-btn--blue">Send test message</button>
                                </div>
                                <div id="tg-result" class="test-result" aria-live="polite" hidden></div>
                            </div>
                        </fieldset>

                        <h3 class="group-title">Fetching &amp; failures</h3>
                        <div class="field-grid">
                            <div class="field field--wide"><label for="s-ua">User agent</label><input type="text" id="s-ua" name="userAgent" maxlength="200" value="<?= sg_e($settings['userAgent']) ?>"></div>
                            <div class="field"><label for="s-rt">Request timeout (s)</label><input type="number" id="s-rt" name="requestTimeout" min="1" max="120" value="<?= (int)$settings['requestTimeout'] ?>"></div>
                            <div class="field"><label for="s-ct">Connect timeout (s)</label><input type="number" id="s-ct" name="connectTimeout" min="1" max="60" value="<?= (int)$settings['connectTimeout'] ?>"></div>
                            <div class="field"><label for="s-ft">Failure threshold</label><input type="number" id="s-ft" name="failureThreshold" min="1" max="100" value="<?= (int)$settings['failureThreshold'] ?>"></div>
                            <div class="field field--check"><label><input type="checkbox" name="notifyOnFailure" value="1"<?= $settings['notifyOnFailure'] ? ' checked' : '' ?>> Notify when a monitor keeps failing</label></div>
                        </div>

                        <h3 class="group-title">Cron</h3>
                        <div class="field-grid">
                            <div class="field field--wide"><label for="s-token">Cron token (read-only)</label><input type="text" id="s-token" readonly value="<?= sg_e($settings['cronToken']) ?>"></div>
                        </div>
                        <p class="dim">Preferred — CLI, no HTTP or Basic Auth involved:</p>
                        <pre class="code"><?= sg_e($cronCli) ?></pre>
                        <p class="dim">Fallback — for hosts that only offer a web-cron panel:</p>
                        <pre class="code"><?= sg_e($cronHttp) ?></pre>

                        <div class="form-actions">
                            <button type="submit" class="lcars-btn lcars-btn--orange">Save settings</button>
                            <button type="button" id="send-test-mail" class="lcars-btn lcars-btn--blue">Send test email</button>
                        </div>
                        <div id="mail-result" class="test-result" aria-live="polite" hidden></div>
                    </form>
                </div>
            </section>

            <footer class="lcars-footer">Sensor Grid · self-hosted · times shown in <?= sg_e($settings['timezone']) ?></footer>
        </main>
    </div>
</div>
</body>
</html>
