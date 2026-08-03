<?php
/*
 * installment_action.php — create / decide / delete تقسيط requests.
 *
 * POST-only, CSRF-protected, permission-gated:
 *   action=create : sales (or anyone with installments.create) files ONE request
 *                   for a customer + car, targeting one or more banks — each
 *                   bank gets its own مقدم % and starts as 'pending'
 *                   (the UI then shows "في انتظار رد المدير").
 *   action=decide : a manager (installments.decide) accepts or rejects a single
 *                   bank line, optionally with a note.
 *   action=delete : removes a request and all its bank lines.
 */

require 'auth.php';
require 'config.php';
require 'installment_helpers.php';

perm_require('page.installments');
inst_ensure_tables($pdo);

$lang = $_POST['lang'] ?? $_GET['lang'] ?? 'ar';
if (!in_array($lang, ['ar', 'en'], true)) $lang = 'ar';

function inst_back(string $lang, string $flash = ''): void
{
    header('Location: installments.php?lang=' . $lang . ($flash !== '' ? '&' . $flash . '=1' : ''));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    inst_back($lang);
}

if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string)($_POST['csrf_token'] ?? ''))) {
    http_response_code(403);
    die('Invalid CSRF token');
}

$action = $_POST['action'] ?? '';
$me     = $_SESSION['username'] ?? '';

/* ─────────────────────────── CREATE ─────────────────────────── */
if ($action === 'create') {

    if (!can('installments.create')) { http_response_code(403); die('Access Denied'); }

    $customer_name  = trim($_POST['customer_name']  ?? '');
    $customer_phone = trim($_POST['customer_phone'] ?? '');
    $brand          = trim($_POST['brand']     ?? '');
    $model          = trim($_POST['model']     ?? '');
    $trim_name      = trim($_POST['trim_name'] ?? '');
    $car_year       = trim($_POST['car_year']  ?? '');

    // The banks that were ticked, and the % chosen for each of them.
    $banks    = (array)($_POST['banks'] ?? []);
    $percents = (array)($_POST['down_payment'] ?? []);

    $validBanks    = inst_banks();
    $validPercents = inst_down_payments();

    // Keep only real banks with a valid مقدم value.
    $lines = [];
    foreach ($banks as $key) {
        $key = (string)$key;
        if (!isset($validBanks[$key])) continue;
        $pct = (int)($percents[$key] ?? 0);
        if (!in_array($pct, $validPercents, true)) continue;
        $lines[$key] = $pct;   // keyed => the same bank can't be added twice
    }

    if ($customer_name === '' || $customer_phone === '' || $brand === '' || $model === '' || empty($lines)) {
        inst_back($lang, 'inst_err');
    }

    try {
        $pdo->beginTransaction();

        $pdo->prepare("
            INSERT INTO installment_requests
                (customer_name, customer_phone, brand, model, trim_name, car_year, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ")->execute([$customer_name, $customer_phone, $brand, $model, $trim_name, $car_year, $me]);

        $requestId = (int)$pdo->lastInsertId();

        $ins = $pdo->prepare("
            INSERT INTO installment_bank_requests (request_id, bank_key, down_payment, status)
            VALUES (?, ?, ?, 'pending')
        ");
        foreach ($lines as $bankKey => $pct) {
            $ins->execute([$requestId, $bankKey, $pct]);
        }

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log('Installment create failed: ' . $e->getMessage());
        inst_back($lang, 'inst_err');
    }

    inst_back($lang, 'sent');
}

/* ─────────────────────────── DECIDE ─────────────────────────── */
if ($action === 'decide') {

    if (!can('installments.decide')) { http_response_code(403); die('Access Denied'); }

    $lineId   = (int)($_POST['line_id'] ?? 0);
    $decision = $_POST['decision'] ?? '';
    $note     = trim($_POST['decision_note'] ?? '');

    if ($lineId <= 0 || !in_array($decision, ['approved', 'rejected'], true)) {
        inst_back($lang);
    }

    try {
        $pdo->prepare("
            UPDATE installment_bank_requests
            SET status = ?, decided_by = ?, decided_at = NOW(), decision_note = ?
            WHERE id = ?
        ")->execute([$decision, $me, $note, $lineId]);
    } catch (Exception $e) {
        error_log('Installment decide failed: ' . $e->getMessage());
        inst_back($lang, 'inst_err');
    }

    inst_back($lang, $decision === 'approved' ? 'approved' : 'rejected');
}

/* ─────────────────────────── DELETE ─────────────────────────── */
if ($action === 'delete') {

    if (!can('installments.delete')) { http_response_code(403); die('Access Denied'); }

    $requestId = (int)($_POST['request_id'] ?? 0);
    if ($requestId <= 0) inst_back($lang);

    try {
        $pdo->beginTransaction();
        $pdo->prepare("DELETE FROM installment_bank_requests WHERE request_id = ?")->execute([$requestId]);
        $pdo->prepare("DELETE FROM installment_requests WHERE id = ?")->execute([$requestId]);
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log('Installment delete failed: ' . $e->getMessage());
        inst_back($lang, 'inst_err');
    }

    inst_back($lang, 'deleted');
}

inst_back($lang);
