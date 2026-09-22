<?php

declare(strict_types=1);

/**
 * Sensor Grid Telegram notifications, loaded by lib.php.
 *
 * Outbound only: messages go to the one configured chat id. Nothing here registers a
 * webhook or answers incoming messages, so anyone else who writes to the bot gets silence.
 * The single read, sg_tg_detect_chat(), runs only when the user clicks "Detect chat ID".
 */

const SG_TG_TEXT_BUDGET = 3500; // Telegram allows 4096 characters; leave room for the header

/**
 * Call a Bot API method. Never throws, and never lets the token into the returned error.
 *
 * @param array<string,scalar> $params
 * @param array<string,mixed>  $settings
 * @return array{ok:bool,error:string,result:mixed}
 */
function sg_tg_request(string $token, string $method, array $params, array $settings): array
{
    if (!preg_match('/^\d+:[A-Za-z0-9_-]{30,}$/', $token)) {
        return ['ok' => false, 'error' => 'Telegram bot token is not set or malformed', 'result' => null];
    }
    $ch = curl_init('https://api.telegram.org/bot' . $token . '/' . $method);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($params),
        CURLOPT_TIMEOUT        => (int)$settings['requestTimeout'],
        CURLOPT_CONNECTTIMEOUT => (int)$settings['connectTimeout'],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
    ]);
    $raw   = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);

    if ($errno !== 0) {
        return ['ok' => false, 'error' => str_replace($token, '***', 'CURL_' . $errno . ': ' . $error), 'result' => null];
    }
    $json = json_decode((string)$raw, true);
    if (!is_array($json)) {
        return ['ok' => false, 'error' => 'Invalid response from Telegram (HTTP ' . (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE) . ')', 'result' => null];
    }
    if (empty($json['ok'])) {
        return ['ok' => false, 'error' => 'Telegram: ' . ($json['description'] ?? 'unknown error'), 'result' => null];
    }
    return ['ok' => true, 'error' => '', 'result' => $json['result'] ?? null];
}

/**
 * Send an HTML-formatted message to the configured chat. A no-op when Telegram is disabled.
 *
 * @param array<string,mixed> $settings
 * @return array{ok:bool,error:string}
 */
function sg_tg_send(string $html, array $settings): array
{
    $cfg = $settings['telegram'];
    if (empty($cfg['enabled'])) {
        return ['ok' => true, 'error' => ''];
    }
    $chatId = (string)$cfg['chatId'];
    if (!preg_match('/^-?\d{1,20}$/', $chatId)) {
        $res = ['ok' => false, 'error' => 'Telegram chat id is not set'];
    } else {
        $res = sg_tg_request((string)$cfg['botToken'], 'sendMessage', [
            'chat_id'              => $chatId,
            'text'                 => $html,
            'parse_mode'           => 'HTML',
            'link_preview_options' => '{"is_disabled":true}',
        ], $settings);
        unset($res['result']);
    }
    $first = strtok(strip_tags($html), "\n");
    sg_log('telegram ' . ($res['ok'] ? 'sent' : 'FAILED (' . $res['error'] . ')') . ' to chat ' . $chatId . ': ' . html_entity_decode((string)$first));
    return $res;
}

/**
 * Send the change notification for one site.
 *
 * @param array{ops:list<array{type:string,line:string}>,added:int,removed:int,approximate:bool} $diff
 * @param array<string,mixed> $settings
 * @return array{ok:bool,error:string}
 */
function sg_tg_change(array $site, array $diff, array $settings): array
{
    return sg_tg_send(sg_tg_change_text($site, $diff), $settings);
}

/**
 * Change message: name, URL, counts and the removed/added lines.
 *
 * @param array{ops:list<array{type:string,line:string}>,added:int,removed:int,approximate:bool} $diff
 */
function sg_tg_change_text(array $site, array $diff): string
{
    [$added, $removed] = sg_diff_split($diff);
    $head = '🔔 <b>' . sg_e($site['name']) . '</b> changed' . "\n"
        . sg_e($site['url']) . "\n"
        . sg_e(sg_element_label($site)) . ' · +' . $diff['added'] . ' / −' . $diff['removed']
        . ($diff['approximate'] ? ' (approximate)' : '');

    // Whole lines only: cutting through an escaped entity would break parse_mode=HTML.
    $body  = '';
    $lines = array_merge(
        array_map(static fn(string $l): string => '− ' . $l, $removed),
        array_map(static fn(string $l): string => '+ ' . $l, $added)
    );
    foreach ($lines as $i => $line) {
        $escaped = sg_e(sg_truncate($line, SG_LINE_MAX_CHARS)) . "\n";
        if (mb_strlen($body . $escaped) > SG_TG_TEXT_BUDGET) {
            $body .= '… and ' . (count($lines) - $i) . " more\n";
            break;
        }
        $body .= $escaped;
    }

    return $head . ($body !== '' ? "\n<pre>" . rtrim($body) . '</pre>' : '');
}

/**
 * One-time "check failing" notification.
 *
 * @param array<string,mixed> $settings
 * @return array{ok:bool,error:string}
 */
function sg_tg_failure(array $site, array $settings, string $error): array
{
    return sg_tg_send(
        '⚠️ <b>Check failing: ' . sg_e($site['name']) . '</b>' . "\n"
        . sg_e(sg_truncate($error, 300)) . "\n"
        . sg_e($site['url']) . "\n"
        . (int)$site['consecutiveFailures'] . ' failures in a row. Sent once; you will hear again only after a recovery and a new outage.',
        $settings
    );
}

/**
 * Find the chat id of whoever last wrote to the bot privately, so the user can confirm it is them.
 *
 * @param array<string,mixed> $settings
 * @return array{ok:bool,error:string,chatId:string,name:string}
 */
function sg_tg_detect_chat(string $token, array $settings): array
{
    $res = sg_tg_request($token, 'getUpdates', ['allowed_updates' => '["message"]'], $settings);
    if (!$res['ok']) {
        return ['ok' => false, 'error' => $res['error'], 'chatId' => '', 'name' => ''];
    }
    foreach (array_reverse((array)$res['result']) as $update) {
        $chat = $update['message']['chat'] ?? null;
        if (is_array($chat) && ($chat['type'] ?? '') === 'private') {
            $name = trim(($chat['first_name'] ?? '') . ' ' . ($chat['last_name'] ?? ''));
            if (!empty($chat['username'])) {
                $name .= ' (@' . $chat['username'] . ')';
            }
            return ['ok' => true, 'error' => '', 'chatId' => (string)$chat['id'], 'name' => $name];
        }
    }
    return ['ok' => false, 'error' => 'No message found. Open your bot in Telegram, press Start (or send any message), then try again.', 'chatId' => '', 'name' => ''];
}
