<?php
/*
 * notify_cron.php — runs the automatic reminders (old reservations, cars in
 * stock too long, transfers nobody confirmed, long consignments, banks that
 * did not reply, "you're still clocked in", scheduled messages).
 *
 * Hostinger → hPanel → Advanced → Cron Jobs → every 15 minutes:
 *     /usr/bin/php /home/USER/domains/DOMAIN/public_html/notify_cron.php
 * (the same folder as the daily summary cron job).
 *
 * It only runs from the cron job, never from a browser. Without the cron job
 * the reminders still run while somebody has the system open — but the late
 * "you're still clocked in" reminder needs the cron job.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found');
}
require __DIR__ . '/config.php';
require_once __DIR__ . '/notify_smart.php';

// phones' push services want to know which site is sending (saved by the web pages)
$_SERVER['HTTP_HOST'] = push_setting($pdo, 'site_host', 'localhost');

$done = smart_run($pdo);
echo date('Y-m-d H:i') . ' — ' . ($done ? implode(', ', $done) : 'nothing due') . PHP_EOL;
