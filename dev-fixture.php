<?php

declare(strict_types=1);

/**
 * Local test target for Sensor Grid. Delete this file on a production host.
 *
 * Renders data/dev-fixture.txt (one <li> per line) inside <div id="watch">, surrounded by
 * noise that changes on every load (a random number, a script) so that element scoping and
 * script stripping are genuinely exercised. Edit the txt file to simulate a real change.
 * Add ?sleep=5 to the URL to simulate a slow response.
 */

$file = __DIR__ . '/data/dev-fixture.txt';
if (!is_dir(dirname($file))) {
    mkdir(dirname($file), 0775, true);
}
if (!is_file($file)) {
    file_put_contents($file, "Starter plan: 9 EUR / month\nTeam plan: 29 EUR / month\nEnterprise: contact sales\n");
}
$lines = array_values(array_filter(array_map('trim', file($file, FILE_IGNORE_NEW_LINES) ?: []), 'strlen'));
$noise = random_int(100000, 999999);

// ?sleep=N (capped) simulates a slow site, for testing timeouts and the cron lock.
sleep(min(30, max(0, (int)($_GET['sleep'] ?? 0))));

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Sensor Grid dev fixture</title>
    <style>body { font-family: sans-serif; }</style>
    <script>window.noise = <?= $noise ?>;</script>
</head>
<body>
    <nav><a href="#">Home</a> <a href="#">Pricing</a> <span>Request #<?= $noise ?></span></nav>
    <!-- rendered at <?= $noise ?> -->
    <div id="watch">
        <h2>Pricing</h2>
        <ul>
<?php foreach ($lines as $line): ?>
            <li><?= htmlspecialchars($line, ENT_QUOTES, 'UTF-8') ?></li>
<?php endforeach; ?>
        </ul>
    </div>
    <footer>Visitor number <?= $noise ?></footer>
    <script>console.log(<?= $noise ?>);</script>
</body>
</html>
