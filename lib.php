<?php

declare(strict_types=1);

/**
 * Sensor Grid core library: storage, fetching, extraction, diffing, mail, view helpers.
 * Shared by index.php and cron.php. No classes; everything is prefixed sg_.
 */

// ---------- CONFIG / BOOTSTRAP ----------

// Single point to relocate mutable state (e.g. outside the web root on a production host).
define('SG_DATA_DIR', __DIR__ . '/data');
define('SG_ROOT', __DIR__);

const SG_JSON_FLAGS       = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
const SG_HISTORY_MAX      = 20;   // entries kept per monitor
const SG_LINE_MAX_CHARS   = 500;  // per stored / emailed diff line
const SG_BLOCK_MAX_LINES  = 40;   // per added/removed block in history and email
const SG_DIFF_TABLE_LIMIT = 250000;

// Fields written back by a check run. Merging only these (instead of whole records)
// keeps a UI edit made during a cron run from being clobbered.
const SG_CHECK_FIELDS = [
    'lastChange', 'lastCheckAt', 'lastStatus', 'lastError', 'lastHttpCode',
    'contentHash', 'consecutiveFailures', 'failureNotified',
];

/**
 * Default settings; also the schema that hand-edited files are merged onto.
 *
 * @return array<string,mixed>
 */
function sg_defaults(): array
{
    return [
        'version'          => 1,
        'timezone'         => 'Europe/Berlin',
        'appUrl'           => 'http://localhost:1337/',
        'notifyEmail'      => '',
        'fromEmail'        => 'sensorgrid@localhost',
        'fromName'         => 'Sensor Grid',
        'mail'             => [
            'transport'  => 'smtp',
            'smtpHost'   => '',
            'smtpPort'   => 587,
            'smtpSecure' => 'tls',
            'smtpAuth'   => true,
            'smtpUser'   => '',
            'smtpPass'   => '',
        ],
        'cronToken'        => '',
        'userAgent'        => 'SensorGrid/1.0 (personal website monitor)',
        'requestTimeout'   => 20,
        'connectTimeout'   => 10,
        'maxRedirects'     => 5,
        'maxContentBytes'  => 2000000,
        'notifyOnFailure'  => true,
        'failureThreshold' => 3,
        'lastCronRun'      => null,
        'lastCronDuration' => null,
    ];
}

/**
 * Entry-point bootstrap: make sure data/ exists and is protected, load settings
 * (creating them on first run) and apply the configured timezone.
 *
 * @return array<string,mixed> settings
 */
function sg_bootstrap(): array
{
    sg_ensure_dir(SG_DATA_DIR);

    // A fresh checkout or a wiped data/ must never expose stored snapshots.
    $htaccess = SG_DATA_DIR . '/.htaccess';
    if (!is_file($htaccess)) {
        file_put_contents(
            $htaccess,
            "Require all denied\n<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n"
        );
    }

    $settings = sg_settings_load();
    if (in_array($settings['timezone'], timezone_identifiers_list(), true)) {
        date_default_timezone_set($settings['timezone']);
    }
    return $settings;
}

// ---------- FILESYSTEM HELPERS ----------

/**
 * Locked read-modify-write over a JSON file.
 * $mutator receives the decoded array and must return the array to persist.
 * Returns the persisted array.
 */
function sg_json_update(string $path, callable $mutator, array $default = []): array
{
    sg_ensure_dir(dirname($path));
    $fh = fopen($path, 'c+');
    if ($fh === false) {
        throw new RuntimeException('Cannot open ' . $path);
    }
    if (!flock($fh, LOCK_EX)) {
        fclose($fh);
        throw new RuntimeException('Cannot lock ' . $path);
    }
    $raw = stream_get_contents($fh);
    if ($raw !== '' && $raw !== false) {
        $data = json_decode($raw, true);
        // Refuse to overwrite a hand-edit typo with an empty list.
        if (!is_array($data)) {
            flock($fh, LOCK_UN);
            fclose($fh);
            throw new RuntimeException(basename($path) . ' is not valid JSON: ' . json_last_error_msg());
        }
    } else {
        $data = $default;
    }
    $data    = $mutator($data);
    $encoded = json_encode($data, SG_JSON_FLAGS);

    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, $encoded);
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);

    return $data;
}

/**
 * Read a JSON file under a shared lock. Missing/empty file returns $default;
 * invalid JSON throws so the UI fails loudly instead of showing an empty list.
 */
function sg_json_read(string $path, array $default = []): array
{
    $fh = is_file($path) ? fopen($path, 'rb') : false;
    if ($fh === false) {
        return $default;
    }
    flock($fh, LOCK_SH);
    $raw = stream_get_contents($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
    if ($raw === false || trim($raw) === '') {
        return $default;
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw new RuntimeException(basename($path) . ' is not valid JSON: ' . json_last_error_msg());
    }
    return $data;
}

/** Recursive mkdir (0775); no-op when the directory exists. */
function sg_ensure_dir(string $path): void
{
    if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
        throw new RuntimeException('Cannot create directory ' . $path);
    }
}

/** Read a plain-text file under a shared lock ('' when missing). */
function sg_text_read(string $path): string
{
    $fh = is_file($path) ? fopen($path, 'rb') : false;
    if ($fh === false) {
        return '';
    }
    flock($fh, LOCK_SH);
    $raw = stream_get_contents($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
    return $raw === false ? '' : $raw;
}

/** Write a plain-text file under an exclusive lock, creating the directory if needed. */
function sg_text_write(string $path, string $content): void
{
    sg_ensure_dir(dirname($path));
    if (file_put_contents($path, $content, LOCK_EX) === false) {
        throw new RuntimeException('Cannot write ' . $path);
    }
}

/**
 * Resolve a data-relative path (as stored in monitoredPages.txt) to an absolute one.
 * The stored files are hand-editable, so refuse anything that could leave data/.
 */
function sg_data_path(string $relative): string
{
    if ($relative === '' || str_contains($relative, '..') || preg_match('#^([a-zA-Z]:|[\\\\/])#', $relative)) {
        throw new RuntimeException('Illegal data path: ' . $relative);
    }
    return SG_DATA_DIR . '/' . $relative;
}

/** RFC-4122 v4 GUID. */
function sg_guid(): string
{
    $b    = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}

/** Current time as ISO-8601 with offset. */
function sg_now(): string
{
    return date('c');
}

/**
 * Append a line to data/cron.log, keeping the file bounded: once it passes
 * 5000 lines it is cut back to the last 2000.
 */
function sg_log(string $message): void
{
    $path = SG_DATA_DIR . '/cron.log';
    sg_ensure_dir(SG_DATA_DIR);
    $fh = fopen($path, 'c+');
    if ($fh === false || !flock($fh, LOCK_EX)) {
        return; // logging must never break a run
    }
    fseek($fh, 0, SEEK_END);
    fwrite($fh, '[' . sg_now() . '] ' . str_replace(["\r", "\n"], ' ', $message) . "\n");

    // 5000 lines are always > 100 KB, so the size check avoids reading the file on every call.
    if ((int)(fstat($fh)['size'] ?? 0) > 100000) {
        rewind($fh);
        $lines = explode("\n", rtrim((string)stream_get_contents($fh), "\n"));
        if (count($lines) > 5000) {
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, implode("\n", array_slice($lines, -2000)) . "\n");
        }
    }
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
}

/**
 * Try to take the run lock shared by cron.php and manual "check now" actions.
 *
 * @return resource|null lock handle, or null when another run holds it
 */
function sg_lock_acquire()
{
    $fh = fopen(SG_DATA_DIR . '/cron.lock', 'c');
    if ($fh === false) {
        return null;
    }
    if (!flock($fh, LOCK_EX | LOCK_NB)) {
        fclose($fh);
        return null;
    }
    return $fh;
}

/** @param resource $fh handle from sg_lock_acquire() */
function sg_lock_release($fh): void
{
    flock($fh, LOCK_UN);
    fclose($fh);
}

// ---------- SETTINGS ----------

/** Absolute path of settings.txt. */
function sg_settings_path(): string
{
    return SG_DATA_DIR . '/settings.txt';
}

/**
 * Load settings, creating the file from defaults on first run and back-filling
 * keys added by newer versions. Never fatals on a fresh checkout.
 *
 * @return array<string,mixed>
 */
function sg_settings_load(): array
{
    $current = sg_json_read(sg_settings_path());
    $merged  = array_replace_recursive(sg_defaults(), $current);
    if ($merged['cronToken'] !== '' && $merged == $current) {
        return $merged;
    }
    // Mutator re-merges under the lock so two first-time loaders cannot generate different tokens.
    return sg_settings_update(static function (array $cur): array {
        $cur = array_replace_recursive(sg_defaults(), $cur);
        if ($cur['cronToken'] === '') {
            $cur['cronToken'] = bin2hex(random_bytes(16));
        }
        return $cur;
    });
}

/**
 * Locked read-modify-write of settings.txt.
 *
 * @param callable(array):array $mutator
 * @return array<string,mixed>
 */
function sg_settings_update(callable $mutator): array
{
    return sg_json_update(sg_settings_path(), $mutator, sg_defaults());
}

/**
 * Validate the settings form. Returns the new settings (not persisted) plus errors.
 * An empty SMTP password keeps the stored one; cron token and cron stats are never form-editable.
 *
 * @param array<string,mixed> $post
 * @param array<string,mixed> $current
 * @return array{errors:string[],settings:array<string,mixed>}
 */
function sg_settings_from_post(array $post, array $current): array
{
    $errors = [];
    $s      = $current;
    $str    = static fn(string $k, string $d = ''): string => trim((string)($post[$k] ?? $d));
    $int    = static function (string $k, int $min, int $max, string $label) use ($post, &$errors, $current): int {
        $v = filter_var($post[$k] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]);
        if ($v === false) {
            $errors[] = "$label must be a whole number between $min and $max.";
            return (int)($current[$k] ?? $current['mail'][$k] ?? $min);
        }
        return $v;
    };

    $notify = $str('notifyEmail');
    if ($notify !== '' && !sg_valid_email($notify)) {
        $errors[] = 'Notify email is not a valid address.';
    }
    $from = $str('fromEmail');
    if (!sg_valid_email($from)) {
        $errors[] = 'From email is not a valid address.';
    }
    $appUrl = $str('appUrl');
    if ($appUrl !== '' && !filter_var($appUrl, FILTER_VALIDATE_URL)) {
        $errors[] = 'App URL is not a valid URL.';
    }
    $tz = $str('timezone');
    if (!in_array($tz, timezone_identifiers_list(), true)) {
        $errors[] = 'Unknown timezone.';
        $tz       = (string)$current['timezone'];
    }
    $transport = $str('transport');
    if (!in_array($transport, ['smtp', 'mail', 'log'], true)) {
        $errors[] = 'Unknown mail transport.';
        $transport = (string)$current['mail']['transport'];
    }
    $secure = $str('smtpSecure');
    if (!in_array($secure, ['tls', 'ssl', 'none'], true)) {
        $errors[] = 'Unknown SMTP encryption.';
        $secure = (string)$current['mail']['smtpSecure'];
    }

    $s['notifyEmail']      = $notify;
    $s['fromEmail']        = $from;
    $s['fromName']         = str_replace(["\r", "\n"], '', mb_substr($str('fromName'), 0, 80));
    $s['appUrl']           = $appUrl;
    $s['timezone']         = $tz;
    $s['userAgent']        = str_replace(["\r", "\n"], '', mb_substr($str('userAgent'), 0, 200)) ?: $current['userAgent'];
    $s['requestTimeout']   = $int('requestTimeout', 1, 120, 'Request timeout');
    $s['connectTimeout']   = $int('connectTimeout', 1, 60, 'Connect timeout');
    $s['failureThreshold'] = $int('failureThreshold', 1, 100, 'Failure threshold');
    $s['notifyOnFailure']  = !empty($post['notifyOnFailure']);

    $s['mail']['transport']  = $transport;
    $s['mail']['smtpHost']   = $str('smtpHost');
    $s['mail']['smtpPort']   = $int('smtpPort', 1, 65535, 'SMTP port');
    $s['mail']['smtpSecure'] = $secure;
    $s['mail']['smtpAuth']   = !empty($post['smtpAuth']);
    $s['mail']['smtpUser']   = $str('smtpUser');
    $pass = (string)($post['smtpPass'] ?? '');
    if ($pass !== '') {
        $s['mail']['smtpPass'] = $pass;
    }
    return ['errors' => $errors, 'settings' => $s];
}

// ---------- PAGES ----------

/**
 * Shape of a monitor record; also supplies defaults for hand-added entries.
 *
 * @return array<string,mixed>
 */
function sg_page_template(): array
{
    return [
        'id'                  => '',
        'name'                => '',
        'url'                 => '',
        'elementId'           => '',
        'intervalMinutes'     => 60,
        'createdAt'           => '',
        'active'              => true,
        'lastChange'          => null,
        'lastCheckFile'       => '',
        'historyFile'         => '',
        'lastCheckAt'         => null,
        'lastStatus'          => 'new',
        'lastError'           => '',
        'lastHttpCode'        => 0,
        'contentHash'         => '',
        'consecutiveFailures' => 0,
        'failureNotified'     => false,
    ];
}

/**
 * Fill missing fields (including the file paths, derived from the id).
 *
 * @param array<string,mixed> $page
 * @return array<string,mixed>
 */
function sg_page_normalize(array $page): array
{
    $page = array_merge(sg_page_template(), $page);
    if ($page['lastCheckFile'] === '') {
        $page['lastCheckFile'] = 'snapshots/' . $page['id'] . '.txt';
    }
    if ($page['historyFile'] === '') {
        $page['historyFile'] = 'history/' . $page['id'] . '.txt';
    }
    return $page;
}

/** Build a new monitor from validated values (see sg_validate_monitor). */
function sg_page_new(string $name, string $url, string $elementId, int $interval): array
{
    $id = sg_guid();
    return sg_page_normalize([
        'id'              => $id,
        'name'            => $name,
        'url'             => $url,
        'elementId'       => $elementId,
        'intervalMinutes' => $interval,
        'createdAt'       => sg_now(),
    ]);
}

/**
 * All monitors, normalised.
 *
 * @return list<array<string,mixed>>
 */
function sg_pages_read(): array
{
    return array_values(array_map('sg_page_normalize', sg_json_read(SG_DATA_DIR . '/monitoredPages.txt')));
}

/**
 * Locked read-modify-write of monitoredPages.txt.
 *
 * @param callable(list<array<string,mixed>>):array $mutator
 * @return list<array<string,mixed>>
 */
function sg_pages_update(callable $mutator): array
{
    return sg_json_update(
        SG_DATA_DIR . '/monitoredPages.txt',
        static fn(array $pages): array => array_values($mutator(array_map('sg_page_normalize', $pages)))
    );
}

/**
 * Merge check results back by id, touching only check-managed fields.
 *
 * @param array<string,array<string,mixed>> $sitesById updated sites keyed by id
 */
function sg_pages_apply_results(array $sitesById): void
{
    if ($sitesById === []) {
        return;
    }
    sg_pages_update(static function (array $pages) use ($sitesById): array {
        foreach ($pages as $i => $page) {
            if (!isset($sitesById[$page['id']])) {
                continue; // deleted while the check was running
            }
            foreach (SG_CHECK_FIELDS as $field) {
                $pages[$i][$field] = $sitesById[$page['id']][$field];
            }
        }
        return $pages;
    });
}

/**
 * Validate monitor form input.
 *
 * @param array<string,mixed> $in
 * @return array{errors:string[],values:array{name:string,url:string,elementId:string,intervalMinutes:int}}
 */
function sg_validate_monitor(array $in, bool $requireName = true): array
{
    $errors = [];
    $name   = trim((string)($in['name'] ?? ''));
    if ($requireName && (mb_strlen($name) < 1 || mb_strlen($name) > 80)) {
        $errors[] = 'Name is required (1–80 characters).';
    }
    $url = trim((string)($in['url'] ?? ''));
    if (!sg_valid_url($url)) {
        $errors[] = 'URL must be a valid http:// or https:// address.';
    }
    $el = trim((string)($in['elementId'] ?? ''));
    if (str_starts_with($el, '#')) {
        $el = substr($el, 1);
    }
    // A leading "." selects by class (kept in the stored value); anything else is an id.
    if (!preg_match('/^(?:\.[A-Za-z0-9_\-]{1,100}|(?!\.)[A-Za-z0-9_\-:.]{0,100})$/', $el)) {
        $errors[] = 'Element must be an id (#price: letters, digits and _ - : .) or a class (.price: letters, digits and _ -), max 100 characters.';
    }
    $interval = filter_var($in['intervalMinutes'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1440]]);
    if ($interval === false) {
        $errors[] = 'Interval must be a whole number of minutes between 1 and 1440.';
        $interval = 60;
    }
    return [
        'errors' => $errors,
        'values' => ['name' => $name, 'url' => $url, 'elementId' => $el, 'intervalMinutes' => $interval],
    ];
}

/**
 * True for a valid email address. Uses the HTML5 rules rather than FILTER_VALIDATE_EMAIL,
 * which rejects dotless hosts such as the default sensorgrid@localhost.
 */
function sg_valid_email(string $email): bool
{
    return (bool)preg_match(
        '/^[a-zA-Z0-9.!#$%&\'*+\/=?^_`{|}~-]+@[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(?:\.[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*$/D',
        $email
    );
}

/** True for a syntactically valid http(s) URL. */
function sg_valid_url(string $url): bool
{
    if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
        return false;
    }
    return in_array(strtolower((string)parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true);
}

// ---------- FETCHING ----------

/**
 * Fetch a URL with cURL. Never uses file_get_contents on remote URLs.
 *
 * @param array<string,mixed> $settings
 * @return array{ok:bool,body:string,httpCode:int,finalUrl:string,contentType:string,elapsedMs:int,error:string}
 */
function sg_fetch(string $url, array $settings): array
{
    $result = ['ok' => false, 'body' => '', 'httpCode' => 0, 'finalUrl' => $url, 'contentType' => '', 'elapsedMs' => 0, 'error' => ''];

    if (!sg_valid_url($url)) {
        $result['error'] = 'INVALID_URL';
        return $result;
    }

    $body     = '';
    $tooLarge = false;
    $max      = (int)$settings['maxContentBytes'];
    $ch       = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => (int)$settings['maxRedirects'],
        CURLOPT_TIMEOUT        => (int)$settings['requestTimeout'],
        CURLOPT_CONNECTTIMEOUT => (int)$settings['connectTimeout'],
        CURLOPT_USERAGENT      => (string)$settings['userAgent'],
        CURLOPT_ENCODING       => '',        // accept gzip/deflate/br
        CURLOPT_SSL_VERIFYPEER => true,      // never disable, not even to "make it work"
        CURLOPT_SSL_VERIFYHOST => 2,
        // A redirect must not be able to downgrade us to file://, ftp:// and friends.
        CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_HTTPHEADER     => [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: en;q=0.9,*;q=0.5',
            'Cache-Control: no-cache',
            'Pragma: no-cache',
        ],
        // Abort mid-stream instead of buffering an arbitrarily large (or decompressed) body.
        CURLOPT_WRITEFUNCTION  => static function ($ch, string $chunk) use (&$body, &$tooLarge, $max): int {
            $body .= $chunk;
            if (strlen($body) > $max) {
                $tooLarge = true;
                return 0;
            }
            return strlen($chunk);
        },
    ]);
    curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);

    $result['httpCode']    = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $result['finalUrl']    = (string)(curl_getinfo($ch, CURLINFO_EFFECTIVE_URL) ?: $url);
    $result['contentType'] = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $result['elapsedMs']   = (int)round(((float)curl_getinfo($ch, CURLINFO_TOTAL_TIME)) * 1000);

    if ($tooLarge) {
        $result['error'] = 'CONTENT_TOO_LARGE';
        return $result;
    }
    if ($errno !== 0) {
        $result['error'] = ($errno === CURLE_OPERATION_TIMEDOUT ? 'TIMEOUT' : 'CURL_' . $errno) . ': ' . $error;
        return $result;
    }
    if ($result['httpCode'] >= 400) {
        $result['error'] = 'HTTP_' . $result['httpCode'];
        return $result;
    }

    $result['body'] = sg_to_utf8($body, $result['contentType']);
    $result['ok']   = true;
    return $result;
}

/**
 * Convert a response body to UTF-8 using the Content-Type charset, falling back
 * to a <meta> declaration in the first 2 KB.
 */
function sg_to_utf8(string $body, string $contentType): string
{
    $charset = '';
    if (preg_match('/charset\s*=\s*["\']?([\w\-:.]+)/i', $contentType, $m)) {
        $charset = $m[1];
    } elseif (preg_match('/<meta[^>]+charset\s*=\s*["\']?([\w\-:.]+)/i', substr($body, 0, 2048), $m)) {
        $charset = $m[1];
    }
    if ($charset !== '' && strcasecmp($charset, 'utf-8') !== 0 && strcasecmp($charset, 'utf8') !== 0) {
        try {
            $body = mb_convert_encoding($body, 'UTF-8', $charset);
        } catch (ValueError) {
            // Unknown charset label: fall through to the scrub below.
        }
    }
    $body = preg_replace('/^\xEF\xBB\xBF/', '', $body) ?? $body;
    // Later regexes use /u and return null on invalid UTF-8, so scrub stray bytes here.
    return mb_check_encoding($body, 'UTF-8') ? $body : mb_convert_encoding($body, 'UTF-8', 'UTF-8');
}

// ---------- EXTRACTION ----------

/** Quote a string as an XPath literal, handling embedded quotes. */
function sg_xpath_literal(string $value): string
{
    if (!str_contains($value, "'"))  { return "'" . $value . "'"; }
    if (!str_contains($value, '"'))  { return '"' . $value . '"'; }
    return "concat('" . str_replace("'", "',\"'\",'", $value) . "')";
}

/**
 * Extract the inner HTML of #elementId (or <body>) after stripping noise.
 * A leading "." selects by class instead: the outer HTML of every match, in document order,
 * so a change to a matched element's own attributes counts too.
 *
 * @return array{ok:bool,html:string,error:string}
 */
function sg_extract(string $html, string $elementId): array
{
    if (trim($html) === '') {
        return ['ok' => false, 'html' => '', 'error' => 'EMPTY_RESPONSE'];
    }

    // The XML prefix stops loadHTML() from assuming ISO-8859-1 and mangling UTF-8.
    $doc  = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);

    $xp = new DOMXPath($doc);

    // Strip noise that changes on every load but is not a real content change.
    // iterator_to_array: the NodeList is live, removing while iterating would skip nodes.
    foreach (iterator_to_array($xp->query('//script | //style | //noscript | //comment()')) as $n) {
        $n->parentNode?->removeChild($n);
    }

    if (str_starts_with($elementId, '.')) {
        // Whole-token match: padding with spaces stops ".foo" from matching "foo--bar" or "foobar".
        $cls   = sg_xpath_literal(' ' . substr($elementId, 1) . ' ');
        $nodes = $xp->query("//*[contains(concat(' ', normalize-space(@class), ' '), $cls)]");
        if ($nodes === false || $nodes->length === 0) {
            return ['ok' => false, 'html' => '', 'error' => 'ELEMENT_NOT_FOUND'];
        }
        $outer = '';
        foreach ($nodes as $node) {
            $outer .= $doc->saveHTML($node) . "\n";
        }
        return ['ok' => true, 'html' => $outer, 'error' => ''];
    }

    if ($elementId !== '') {
        // XPath instead of getElementById(), which returns null for HTML without a DTD.
        $nodes = $xp->query('//*[@id=' . sg_xpath_literal($elementId) . ']');
        if ($nodes === false || $nodes->length === 0) {
            return ['ok' => false, 'html' => '', 'error' => 'ELEMENT_NOT_FOUND'];
        }
        $target = $nodes->item(0);
    } else {
        $target = $xp->query('//body')->item(0) ?? $doc->documentElement;
    }
    if ($target === null) {
        return ['ok' => false, 'html' => '', 'error' => 'EMPTY_RESPONSE'];
    }

    $inner = '';
    foreach ($target->childNodes as $child) {
        $inner .= $doc->saveHTML($child);
    }

    return ['ok' => true, 'html' => $inner, 'error' => ''];
}

/**
 * Turn a fragment into comparable lines: one per tag boundary, whitespace collapsed.
 *
 * @return list<string>
 */
function sg_normalize_lines(string $html): array
{
    $html  = preg_replace('#>\s+<#u', ">\n<", $html) ?? $html;
    $html  = preg_replace('#\R#u', "\n", $html) ?? $html;
    $lines = [];
    foreach (explode("\n", $html) as $line) {
        $line = trim(preg_replace('#[ \t\x{00A0}]+#u', ' ', $line) ?? $line);
        if ($line !== '') {
            $lines[] = $line;
        }
    }
    return $lines;
}

// ---------- DIFFING ----------

/**
 * Line diff. Trims the common prefix/suffix first, then runs LCS on what is left,
 * or falls back to a set difference when the table would get too big.
 *
 * @param list<string> $old
 * @param list<string> $new
 * @return array{ops:list<array{type:string,line:string}>,added:int,removed:int,approximate:bool}
 */
function sg_diff_lines(array $old, array $new): array
{
    $old = array_values($old);
    $new = array_values($new);

    // 1. Trim the common prefix and suffix — cheap, and usually removes almost everything.
    $start = 0;
    $oN = count($old);
    $nN = count($new);
    while ($start < $oN && $start < $nN && $old[$start] === $new[$start]) {
        $start++;
    }
    $endO = $oN - 1;
    $endN = $nN - 1;
    while ($endO >= $start && $endN >= $start && $old[$endO] === $new[$endN]) {
        $endO--;
        $endN--;
    }

    $a = array_slice($old, $start, $endO - $start + 1);
    $b = array_slice($new, $start, $endN - $start + 1);
    $m = count($a);
    $n = count($b);

    // 2. Guard: PHP arrays cost ~80 bytes/element, so a big LCS table would eat hundreds of MB.
    if ($m * $n > SG_DIFF_TABLE_LIMIT) {
        $ops = [];
        $del = array_diff($a, $b);
        $add = array_diff($b, $a);
        foreach ($del as $line) { $ops[] = ['type' => 'del', 'line' => $line]; }
        foreach ($add as $line) { $ops[] = ['type' => 'add', 'line' => $line]; }
        return ['ops' => $ops, 'added' => count($add), 'removed' => count($del), 'approximate' => true];
    }

    // 3. LCS table.
    $lcs = [];
    for ($i = 0; $i <= $m; $i++) {
        $lcs[$i] = array_fill(0, $n + 1, 0);
    }
    for ($i = 1; $i <= $m; $i++) {
        for ($j = 1; $j <= $n; $j++) {
            $lcs[$i][$j] = ($a[$i - 1] === $b[$j - 1])
                ? $lcs[$i - 1][$j - 1] + 1
                : max($lcs[$i - 1][$j], $lcs[$i][$j - 1]);
        }
    }

    // 4. Backtrack. Append + reverse; array_unshift in a loop would be O(n^2).
    $ops = [];
    $i = $m;
    $j = $n;
    $added = 0;
    $removed = 0;
    while ($i > 0 || $j > 0) {
        if ($i > 0 && $j > 0 && $a[$i - 1] === $b[$j - 1]) {
            $ops[] = ['type' => 'ctx', 'line' => $a[$i - 1]];
            $i--;
            $j--;
        } elseif ($j > 0 && ($i === 0 || $lcs[$i][$j - 1] >= $lcs[$i - 1][$j])) {
            $ops[] = ['type' => 'add', 'line' => $b[$j - 1]];
            $added++;
            $j--;
        } else {
            $ops[] = ['type' => 'del', 'line' => $a[$i - 1]];
            $removed++;
            $i--;
        }
    }

    return ['ops' => array_reverse($ops), 'added' => $added, 'removed' => $removed, 'approximate' => false];
}

/**
 * Split a diff into its added and removed lines.
 *
 * @param array{ops:list<array{type:string,line:string}>} $diff
 * @return array{0:list<string>,1:list<string>} [added, removed]
 */
function sg_diff_split(array $diff): array
{
    $added = [];
    $removed = [];
    foreach ($diff['ops'] as $op) {
        if ($op['type'] === 'add') {
            $added[] = $op['line'];
        } elseif ($op['type'] === 'del') {
            $removed[] = $op['line'];
        }
    }
    return [$added, $removed];
}

// ---------- CHECKING ----------

/** Whether an active monitor is due for a check at time $now (unix seconds). */
function sg_is_due(array $site, int $now): bool
{
    if (empty($site['active'])) { return false; }
    if (empty($site['lastCheckAt'])) { return true; }
    $last = strtotime((string)$site['lastCheckAt']);
    // 30s tolerance so a 1-minute interval is not permanently skipped by cron jitter.
    return $now >= ($last + $site['intervalMinutes'] * 60 - 30);
}

/** Truncate to at most $max characters, the trailing ellipsis included. */
function sg_truncate(string $s, int $max): string
{
    return mb_strlen($s) > $max ? mb_substr($s, 0, $max - 1) . '…' : $s;
}

/**
 * Run one check. Does not write monitoredPages.txt — the caller persists the returned site,
 * so a single locked write can cover a whole run. Writes snapshot/history and sends mail
 * unless $dryRun (fetch + extract + normalise only; used by Test Run).
 *
 * @param array<string,mixed> $site
 * @param array<string,mixed> $settings
 * @return array{status:string,site:array<string,mixed>,diff:?array,error:string,test:array<string,mixed>,mail?:array{ok:bool,error:string}}
 */
function sg_check_site(array $site, array $settings, bool $dryRun = false): array
{
    $original = $site;
    $fetch    = sg_fetch((string)$site['url'], $settings);
    $test     = [
        'httpCode' => $fetch['httpCode'], 'finalUrl' => $fetch['finalUrl'], 'elapsedMs' => $fetch['elapsedMs'],
        'elementFound' => false, 'extractedLength' => 0, 'preview' => '',
    ];
    $site['lastHttpCode'] = $fetch['httpCode'];
    $site['lastCheckAt']  = sg_now();

    $error = $fetch['error'];
    $ex    = ['ok' => false, 'html' => ''];
    if ($fetch['ok']) {
        $ex    = sg_extract($fetch['body'], (string)$site['elementId']);
        $error = $ex['error'];
    }
    if ($error !== '') {
        if ($dryRun) {
            return ['status' => 'error', 'site' => $original, 'diff' => null, 'error' => $error, 'test' => $test];
        }
        $site = sg_record_failure($site, $settings, $error);
        return ['status' => 'error', 'site' => $site, 'diff' => null, 'error' => $error, 'test' => $test];
    }

    $lines = sg_normalize_lines($ex['html']);
    $text  = implode("\n", $lines);
    $hash  = hash('sha256', $text);

    $test['elementFound']    = true;
    $test['extractedLength'] = mb_strlen($text);
    $test['preview']         = mb_substr($text, 0, 600);
    if ($dryRun) {
        return ['status' => 'test', 'site' => $original, 'diff' => null, 'error' => '', 'test' => $test];
    }

    $site['consecutiveFailures'] = 0;
    $site['failureNotified']     = false;
    $site['lastError']           = '';
    $site['contentHash']         = $hash;

    // Existence, not emptiness, marks a baseline: an element that is legitimately empty
    // must not be re-baselined forever, and "everything was removed" is a real change.
    $snapPath = sg_data_path((string)$site['lastCheckFile']);
    if (!is_file($snapPath)) {
        sg_text_write($snapPath, $text);
        $site['lastStatus'] = 'new';
        return ['status' => 'new', 'site' => $site, 'diff' => null, 'error' => '', 'test' => $test];
    }

    $oldText = sg_text_read($snapPath);
    if (hash('sha256', $oldText) === $hash) {
        $site['lastStatus'] = 'ok';
        return ['status' => 'ok', 'site' => $site, 'diff' => null, 'error' => '', 'test' => $test];
    }

    $diff = sg_diff_lines($oldText === '' ? [] : explode("\n", $oldText), $lines);
    [$added, $removed] = sg_diff_split($diff);
    $at = sg_now();
    sg_history_add($site, [
        'at'           => $at,
        'added'        => sg_cap_lines($added),
        'removed'      => sg_cap_lines($removed),
        'addedCount'   => $diff['added'],
        'removedCount' => $diff['removed'],
    ]);
    $mail = sg_send_change_email($site, $diff, $settings, $at);
    sg_text_write($snapPath, $text);

    $site['lastChange'] = $at;
    $site['lastStatus'] = 'changed';
    return ['status' => 'changed', 'site' => $site, 'diff' => $diff, 'error' => '', 'test' => $test, 'mail' => $mail];
}

/**
 * sg_check_site() that turns any exception into an errored site, so one bad monitor
 * can never abort a run.
 *
 * @return array{status:string,site:array<string,mixed>,diff:?array,error:string,test:array<string,mixed>}
 */
function sg_check_site_guarded(array $site, array $settings): array
{
    try {
        return sg_check_site($site, $settings);
    } catch (Throwable $e) {
        sg_log('check crashed for "' . $site['name'] . '": ' . $e->getMessage());
        $error = 'EXCEPTION: ' . $e->getMessage();
        $site['lastCheckAt'] = sg_now();
        $site['lastStatus']  = 'error';
        $site['lastError']   = $error;
        $site['consecutiveFailures'] = (int)$site['consecutiveFailures'] + 1;
        return ['status' => 'error', 'site' => $site, 'diff' => null, 'error' => $error, 'test' => []];
    }
}

/**
 * Bookkeeping for a failed check; sends the one-time failure email when the threshold is crossed.
 *
 * @return array<string,mixed> updated site
 */
function sg_record_failure(array $site, array $settings, string $error): array
{
    $site['lastStatus']          = 'error';
    $site['lastError']           = $error;
    $site['consecutiveFailures'] = (int)$site['consecutiveFailures'] + 1;

    if (
        !empty($settings['notifyOnFailure'])
        && $site['consecutiveFailures'] >= (int)$settings['failureThreshold']
        && empty($site['failureNotified'])
    ) {
        sg_send_failure_email($site, $settings, $error);
        $site['failureNotified'] = true;
    }
    return $site;
}

/**
 * Run every due monitor and persist the results in one locked write.
 * The caller is responsible for holding sg_lock_acquire().
 *
 * @return array{checked:int,changed:int,errors:int,skipped:int,duration:float}
 */
function sg_run_due_checks(array $settings): array
{
    $start   = microtime(true);
    $now     = time();
    $summary = ['checked' => 0, 'changed' => 0, 'errors' => 0, 'skipped' => 0, 'duration' => 0.0];
    $results = [];

    foreach (sg_pages_read() as $site) {
        if (!sg_is_due($site, $now)) {
            $summary['skipped']++;
            continue;
        }
        $r = sg_check_site_guarded($site, $settings);
        $results[$site['id']] = $r['site'];
        $summary['checked']++;
        $summary['changed'] += $r['status'] === 'changed' ? 1 : 0;
        $summary['errors']  += $r['status'] === 'error' ? 1 : 0;
    }

    sg_pages_apply_results($results);
    $summary['duration'] = round(microtime(true) - $start, 2);
    return $summary;
}

/**
 * Prepend an entry to a monitor's history file, capped at SG_HISTORY_MAX (newest first).
 *
 * @param array<string,mixed> $entry
 */
function sg_history_add(array $site, array $entry): void
{
    sg_json_update(
        sg_data_path((string)$site['historyFile']),
        static fn(array $h): array => array_slice(array_merge([$entry], array_values($h)), 0, SG_HISTORY_MAX)
    );
}

/**
 * A monitor's stored history, newest first.
 *
 * @return list<array<string,mixed>>
 */
function sg_history_read(array $site): array
{
    return array_values(sg_json_read(sg_data_path((string)$site['historyFile'])));
}

/**
 * Cap a line list for storage: at most 40 lines of 500 characters.
 *
 * @param list<string> $lines
 * @return list<string>
 */
function sg_cap_lines(array $lines): array
{
    return array_map(static fn(string $l): string => sg_truncate($l, SG_LINE_MAX_CHARS), array_slice($lines, 0, SG_BLOCK_MAX_LINES));
}

/** Delete a monitor's snapshot and (optionally) history files. */
function sg_delete_site_files(array $site, bool $history = true): void
{
    $files = [sg_data_path((string)$site['lastCheckFile'])];
    if ($history) {
        $files[] = sg_data_path((string)$site['historyFile']);
    }
    foreach ($files as $f) {
        if (is_file($f)) {
            unlink($f);
        }
    }
}

// ---------- MAIL ----------

/**
 * Send one message through the configured transport. Never throws; failures are logged
 * and returned so a mail problem cannot abort a cron run.
 *
 * @param array<string,mixed> $settings
 * @return array{ok:bool,error:string}
 */
function sg_send_mail(string $subject, string $htmlBody, string $textBody, array $settings): array
{
    $to        = trim((string)$settings['notifyEmail']);
    $transport = (string)($settings['mail']['transport'] ?? 'smtp');
    $subject   = str_replace(["\r", "\n"], ' ', $subject);

    if (!sg_valid_email($to)) {
        $res = ['ok' => false, 'error' => 'NO_NOTIFY_EMAIL: set a valid notify email in Settings'];
    } else {
        try {
            $res = match ($transport) {
                'smtp'  => sg_mail_smtp($to, $subject, $htmlBody, $textBody, $settings),
                'mail'  => sg_mail_native($to, $subject, $htmlBody, $settings),
                'log'   => sg_mail_log($to, $subject, $htmlBody, $textBody, $settings),
                default => ['ok' => false, 'error' => 'Unknown transport ' . $transport],
            };
        } catch (Throwable $e) {
            $res = ['ok' => false, 'error' => $e->getMessage()];
        }
    }
    sg_log('mail[' . $transport . '] ' . ($res['ok'] ? 'sent' : 'FAILED (' . $res['error'] . ')') . ' to ' . $to . ': ' . $subject);
    return $res;
}

/** @return array{ok:bool,error:string} */
function sg_mail_smtp(string $to, string $subject, string $html, string $text, array $settings): array
{
    $cfg = $settings['mail'];
    if (trim((string)$cfg['smtpHost']) === '') {
        return ['ok' => false, 'error' => 'SMTP host is not configured'];
    }
    // Loaded on demand; there is no autoloader.
    require_once SG_ROOT . '/lib/PHPMailer/Exception.php';
    require_once SG_ROOT . '/lib/PHPMailer/PHPMailer.php';
    require_once SG_ROOT . '/lib/PHPMailer/SMTP.php';

    // The default 'php' validator (filter_var) refuses dotless hosts like sensorgrid@localhost.
    \PHPMailer\PHPMailer\PHPMailer::$validator = 'html5';
    $m = new \PHPMailer\PHPMailer\PHPMailer(true);
    $m->isSMTP();
    $m->Host     = (string)$cfg['smtpHost'];
    $m->Port     = (int)$cfg['smtpPort'];
    $m->SMTPAuth = !empty($cfg['smtpAuth']);
    $m->Username = (string)$cfg['smtpUser'];
    $m->Password = (string)$cfg['smtpPass'];
    $m->Timeout  = 15;
    if ($cfg['smtpSecure'] === 'ssl') {
        $m->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
    } elseif ($cfg['smtpSecure'] === 'tls') {
        $m->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
    } else {
        $m->SMTPSecure  = '';
        $m->SMTPAutoTLS = false; // "none" must really mean none, e.g. for a local Mailpit
    }
    $m->CharSet = 'UTF-8';
    $m->setFrom((string)$settings['fromEmail'], (string)$settings['fromName']);
    $m->addAddress($to);
    $m->isHTML(true);
    $m->Subject = $subject;
    $m->Body    = $html;
    $m->AltBody = $text;
    $m->send();
    return ['ok' => true, 'error' => ''];
}

/** @return array{ok:bool,error:string} */
function sg_mail_native(string $to, string $subject, string $html, array $settings): array
{
    $fromName = str_replace(['"', "\r", "\n"], '', (string)$settings['fromName']);
    $from     = str_replace(["\r", "\n"], '', (string)$settings['fromEmail']);
    $headers  = implode("\r\n", [
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'From: "' . $fromName . '" <' . $from . '>',
        'Reply-To: ' . $from,
    ]);
    $ok = mail($to, mb_encode_mimeheader($subject, 'UTF-8'), $html, $headers);
    return ['ok' => $ok, 'error' => $ok ? '' : 'mail() returned false (check sendmail_path / SMTP in php.ini)'];
}

/** @return array{ok:bool,error:string} */
function sg_mail_log(string $to, string $subject, string $html, string $text, array $settings): array
{
    $entry = "=== " . sg_now() . " ===\n"
        . 'To: ' . $to . "\n"
        . 'From: ' . $settings['fromName'] . ' <' . $settings['fromEmail'] . ">\n"
        . 'Subject: ' . $subject . "\n\n"
        . $text . "\n\n--- HTML ---\n" . $html . "\n\n";
    $ok = file_put_contents(SG_DATA_DIR . '/mail.log', $entry, FILE_APPEND | LOCK_EX) !== false;
    return ['ok' => $ok, 'error' => $ok ? '' : 'Cannot write data/mail.log'];
}

/** Selector form of a stored element: ".class" as-is, a bare id as "#id". */
function sg_element_display(string $el): string
{
    return str_starts_with($el, '.') ? $el : '#' . $el;
}

/** Human label for the monitored element. */
function sg_element_label(array $site): string
{
    return $site['elementId'] !== '' ? sg_element_display($site['elementId']) : 'entire <body>';
}

/**
 * One coloured added/removed block for the HTML email.
 *
 * @param list<string> $lines
 */
function sg_email_block(string $title, array $lines, string $bg, string $border, string $fg): string
{
    if ($lines === []) {
        return '';
    }
    $shown = array_slice($lines, 0, SG_BLOCK_MAX_LINES);
    $pre   = '';
    foreach ($shown as $l) {
        $pre .= sg_e(sg_truncate($l, SG_LINE_MAX_CHARS)) . "\n";
    }
    $more = count($lines) - count($shown);
    if ($more > 0) {
        $pre .= '… and ' . $more . " more\n";
    }
    return '<h3 style="margin:20px 0 6px;font:bold 14px Arial,sans-serif;color:' . $fg . '">' . sg_e($title) . '</h3>'
        . '<pre style="margin:0;padding:10px 12px;background:' . $bg . ';border-left:4px solid ' . $border . ';color:' . $fg
        . ';font:12px/1.5 Consolas,Menlo,monospace;white-space:pre-wrap;word-break:break-all">' . $pre . '</pre>';
}

/**
 * Send the change email for one site (one email per site, never a digest).
 *
 * @param array{ops:list<array{type:string,line:string}>,added:int,removed:int,approximate:bool} $diff
 * @return array{ok:bool,error:string}
 */
function sg_send_change_email(array $site, array $diff, array $settings, string $at): array
{
    [$added, $removed] = sg_diff_split($diff);
    $approx  = $diff['approximate'] ? ' (approximate)' : '';
    $summary = sprintf(
        '%d line%s added, %d line%s removed%s',
        $diff['added'], $diff['added'] === 1 ? '' : 's',
        $diff['removed'], $diff['removed'] === 1 ? '' : 's',
        $approx
    );
    $subject = '[Sensor Grid] Change detected: ' . $site['name'];
    $when    = sg_format_dt($at);
    $rows    = [
        ['URL', '<a href="' . sg_e($site['url']) . '" style="color:#2a5db0">' . sg_e($site['url']) . '</a>'],
        ['Monitored element', sg_e(sg_element_label($site))],
        ['Detected at', sg_e($when)],
        ['Check interval', sg_e(sg_interval_label((int)$site['intervalMinutes']))],
    ];
    if (trim((string)$settings['appUrl']) !== '') {
        $rows[] = ['Sensor Grid', '<a href="' . sg_e($settings['appUrl']) . '" style="color:#2a5db0">' . sg_e($settings['appUrl']) . '</a>'];
    }
    $meta = '';
    foreach ($rows as [$k, $v]) {
        $meta .= '<tr><td style="padding:3px 16px 3px 0;color:#666;white-space:nowrap">' . sg_e($k) . '</td><td style="padding:3px 0">' . $v . '</td></tr>';
    }

    $html = '<!DOCTYPE html><html><body style="margin:0;padding:20px;background:#f4f4f7;font:14px/1.5 Arial,sans-serif;color:#222">'
        . '<div style="max-width:720px;margin:0 auto;background:#fff;border:1px solid #ddd;padding:24px">'
        . '<h2 style="margin:0 0 14px;font-size:20px">' . sg_e($site['name']) . '</h2>'
        . '<table style="border-collapse:collapse;font-size:14px">' . $meta . '</table>'
        . '<p style="margin:18px 0 0;font-weight:bold">' . sg_e($summary) . '</p>'
        . sg_email_block('Added', $added, '#e9f7ec', '#2e9d4a', '#14532d')
        . sg_email_block('Removed', $removed, '#fdecec', '#c94040', '#7f1d1d')
        . '</div></body></html>';

    $text = $site['name'] . "\n" . str_repeat('=', mb_strlen((string)$site['name'])) . "\n"
        . 'URL:      ' . $site['url'] . "\n"
        . 'Element:  ' . sg_element_label($site) . "\n"
        . 'Detected: ' . $when . "\n"
        . 'Interval: ' . sg_interval_label((int)$site['intervalMinutes']) . "\n\n"
        . $summary . "\n";
    foreach ([['+', $added], ['-', $removed]] as [$prefix, $lines]) {
        foreach (array_slice($lines, 0, SG_BLOCK_MAX_LINES) as $l) {
            $text .= $prefix . ' ' . sg_truncate($l, SG_LINE_MAX_CHARS) . "\n";
        }
        if (count($lines) > SG_BLOCK_MAX_LINES) {
            $text .= $prefix . ' … and ' . (count($lines) - SG_BLOCK_MAX_LINES) . " more\n";
        }
    }

    return sg_send_mail($subject, $html, $text, $settings);
}

/**
 * Send the one-time "check failing" email.
 *
 * @return array{ok:bool,error:string}
 */
function sg_send_failure_email(array $site, array $settings, string $error): array
{
    $subject = '[Sensor Grid] Check failing: ' . $site['name'];
    $rows    = [
        ['Error', $error],
        ['HTTP status', (string)$site['lastHttpCode']],
        ['URL', (string)$site['url']],
        ['Consecutive failures', (string)$site['consecutiveFailures']],
        ['Monitored element', sg_element_label($site)],
    ];
    $meta = '';
    $text = $site['name'] . " is failing.\n\n";
    foreach ($rows as [$k, $v]) {
        $meta .= '<tr><td style="padding:3px 16px 3px 0;color:#666;white-space:nowrap">' . sg_e($k) . '</td><td style="padding:3px 0">' . sg_e($v) . '</td></tr>';
        $text .= $k . ': ' . $v . "\n";
    }
    $text .= "\nThis is sent once; you will be notified again only after a successful check followed by a new outage.\n";
    $html = '<!DOCTYPE html><html><body style="margin:0;padding:20px;background:#f4f4f7;font:14px/1.5 Arial,sans-serif;color:#222">'
        . '<div style="max-width:720px;margin:0 auto;background:#fff;border:1px solid #ddd;border-left:6px solid #c94040;padding:24px">'
        . '<h2 style="margin:0 0 6px;font-size:20px">Check failing: ' . sg_e($site['name']) . '</h2>'
        . '<p style="margin:0 0 14px;color:#666">Sent once. You will be notified again only after a successful check followed by a new outage.</p>'
        . '<table style="border-collapse:collapse;font-size:14px">' . $meta . '</table></div></body></html>';

    return sg_send_mail($subject, $html, $text, $settings);
}

// ---------- VIEW HELPERS ----------

/** Escape for HTML output. */
function sg_e(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/** Relative time such as "4m ago"; the JS refresher mirrors this format. */
function sg_relative(?string $iso, ?int $now = null): string
{
    if ($iso === null || $iso === '' || ($ts = strtotime($iso)) === false) {
        return 'never';
    }
    $d = ($now ?? time()) - $ts;
    return match (true) {
        $d < 5      => 'just now',
        $d < 60     => $d . 's ago',
        $d < 3600   => intdiv($d, 60) . 'm ago',
        $d < 86400  => intdiv($d, 3600) . 'h ago',
        default     => intdiv($d, 86400) . 'd ago',
    };
}

/** Absolute timestamp in the configured timezone. */
function sg_format_dt(?string $iso): string
{
    if ($iso === null || $iso === '' || ($ts = strtotime($iso)) === false) {
        return '—';
    }
    return date('Y-m-d H:i:s T', $ts);
}

/** "15 min", "6 h", "1 d". */
function sg_interval_label(int $minutes): string
{
    if ($minutes >= 1440 && $minutes % 1440 === 0) { return intdiv($minutes, 1440) . ' d'; }
    if ($minutes >= 60 && $minutes % 60 === 0)     { return intdiv($minutes, 60) . ' h'; }
    return $minutes . ' min';
}
