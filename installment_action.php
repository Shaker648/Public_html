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

    // "Add bank" mode: append bank lines to an EXISTING request — the customer
    // and car are taken from the stored request, nothing is retyped.
    $existingId = (int)($_POST['request_id'] ?? 0);

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

    /* ── Append to an existing request ── */
    if ($existingId > 0) {
        if (empty($lines)) inst_back($lang, 'inst_err');

        $rq = $pdo->prepare("SELECT * FROM installment_requests WHERE id = ? LIMIT 1");
        $rq->execute([$existingId]);
        $req = $rq->fetch(PDO::FETCH_ASSOC);
        if (!$req) inst_back($lang, 'inst_err');

        // Without view_all you may only extend your own requests.
        if (!can('installments.view_all') && ($req['created_by'] ?? '') !== $me) {
            http_response_code(403);
            die('Access Denied');
        }

        try {
            $pdo->beginTransaction();

            // Skip banks this customer already has on this request.
            $have = $pdo->prepare("SELECT bank_key FROM installment_bank_requests WHERE request_id = ?");
            $have->execute([$existingId]);
            $existingBanks = $have->fetchAll(PDO::FETCH_COLUMN);

            $ins = $pdo->prepare("
                INSERT INTO installment_bank_requests (request_id, bank_key, down_payment, status)
                VALUES (?, ?, ?, 'pending')
            ");
            $added = 0;
            foreach ($lines as $bankKey => $pct) {
                if (in_array($bankKey, $existingBanks, true)) continue;
                $ins->execute([$existingId, $bankKey, $pct]);
                $added++;
            }

            $pdo->commit();
            if ($added === 0) inst_back($lang, 'inst_err');   // everything picked was a duplicate
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('Installment add-bank failed: ' . $e->getMessage());
            inst_back($lang, 'inst_err');
        }

        inst_back($lang, 'sent');
    }

    /* ── Brand-new request ── */
    // The year comes from a fixed list (2026–2030) — accept nothing else.
    if (!in_array($car_year, inst_years(), true)) {
        inst_back($lang, 'inst_err');
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

/* ───────────────────── MANAGE THE BANK LIST ───────────────────── */
// Add / remove the banks that appear on the request form (AR + EN names).

if ($action === 'bank_add') {

    if (!can('installments.manage_banks')) { http_response_code(403); die('Access Denied'); }

    $nameAr = trim($_POST['name_ar'] ?? '');
    $nameEn = trim($_POST['name_en'] ?? '');

    if ($nameAr === '' || $nameEn === '') {
        inst_back($lang, 'bank_err');
    }
    if (!inst_ensure_bank_table($pdo)) inst_back($lang, 'bank_err');

    try {
        // Don't allow the exact same name twice.
        $dup = $pdo->prepare("SELECT 1 FROM installment_bank_list WHERE name_ar = ? OR name_en = ? LIMIT 1");
        $dup->execute([$nameAr, $nameEn]);
        if ($dup->fetchColumn()) {
            inst_back($lang, 'bank_dup');
        }

        $key  = inst_make_bank_key($pdo, $nameEn, $nameAr);
        $next = (int)$pdo->query("SELECT COALESCE(MAX(sort_order),0) + 10 FROM installment_bank_list")->fetchColumn();

        $pdo->prepare("
            INSERT INTO installment_bank_list (bank_key, name_ar, name_en, sort_order, active)
            VALUES (?, ?, ?, ?, 1)
        ")->execute([$key, $nameAr, $nameEn, $next]);
    } catch (Exception $e) {
        error_log('bank add failed: ' . $e->getMessage());
        inst_back($lang, 'bank_err');
    }

    inst_back($lang, 'bank_added');
}

if ($action === 'bank_delete') {

    if (!can('installments.manage_banks')) { http_response_code(403); die('Access Denied'); }

    $key = trim($_POST['bank_key'] ?? '');
    if ($key === '' || !inst_ensure_bank_table($pdo)) inst_back($lang);

    try {
        // If the bank was already used on a request, keep the row (so history
        // still shows its name) and just hide it from new requests.
        $used = $pdo->prepare("SELECT COUNT(*) FROM installment_bank_requests WHERE bank_key = ?");
        $used->execute([$key]);

        if ((int)$used->fetchColumn() > 0) {
            $pdo->prepare("UPDATE installment_bank_list SET active = 0 WHERE bank_key = ?")->execute([$key]);
            inst_back($lang, 'bank_hidden');
        }

        $pdo->prepare("DELETE FROM installment_bank_list WHERE bank_key = ?")->execute([$key]);
    } catch (Exception $e) {
        error_log('bank delete failed: ' . $e->getMessage());
        inst_back($lang, 'bank_err');
    }

    inst_back($lang, 'bank_deleted');
}

if ($action === 'bank_restore') {

    if (!can('installments.manage_banks')) { http_response_code(403); die('Access Denied'); }

    $key = trim($_POST['bank_key'] ?? '');
    if ($key === '' || !inst_ensure_bank_table($pdo)) inst_back($lang);

    try {
        $pdo->prepare("UPDATE installment_bank_list SET active = 1 WHERE bank_key = ?")->execute([$key]);
    } catch (Exception $e) {
        error_log('bank restore failed: ' . $e->getMessage());
        inst_back($lang, 'bank_err');
    }

    inst_back($lang, 'bank_restored');
}

inst_back($lang);
