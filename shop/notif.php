<?php
// public_html/api/notification.php

header('Content-Type: application/json; charset=UTF-8');

// 1) Ambil server key dari env (fallback ke $_SERVER/getenv)
$SERVER_KEY = $_ENV['MIDTRANS_SERVER_KEY']
    ?? $_SERVER['MIDTRANS_SERVER_KEY']
    ?? getenv('MIDTRANS_SERVER_KEY')
    ?? ''; // terakhir, bisa hardcode: 'SB-Mid-server-XXXX'

// Ambil raw body dan decode
$raw  = file_get_contents('php://input') ?: '';
$data = json_decode($raw, true) ?: [];

$logf = __DIR__ . '/midtrans-callback.log';
function log_cb($m) {
    global $logf;
    @file_put_contents($logf, '[' . date('c') . "] $m\n", FILE_APPEND);
}

// 2) Handle ping/GET dari test tool juga
if (($_SERVER['REQUEST_METHOD'] ?? 'POST') !== 'POST') {
    log_cb('PING method=' . ($_SERVER['REQUEST_METHOD'] ?? '-'));
    echo json_encode(['ok' => true, 'msg' => 'pong']);
    exit;
}

// 3) Validasi signature (jangan pernah update DB kalau invalid)
$order_id     = $data['order_id'] ?? '';
$status_code  = $data['status_code'] ?? '';
$gross_amount = $data['gross_amount'] ?? '';
$sig_recv     = $data['signature_key'] ?? '';

$sig_calc = $SERVER_KEY
    ? hash('sha512', $order_id . $status_code . $gross_amount . $SERVER_KEY)
    : '';

if (!$SERVER_KEY) {
    log_cb('NO_SERVER_KEY set');
    echo json_encode(['ok' => true]); // tetap 200 agar Midtrans tidak retry
    exit;
}

if (!$sig_recv || !hash_equals($sig_calc, $sig_recv)) {
    log_cb("INVALID_SIGNATURE oid=$order_id calc=$sig_calc recv=$sig_recv raw=$raw");
    echo json_encode(['ok' => true]); // 200 tapi tidak update DB
    exit;
}

// 4) Map status dan update DB
require __DIR__ . '/db.php';

$tx_status = $data['transaction_status'] ?? 'pending';
$map = [
    'capture'        => 'paid',
    'settlement'     => 'paid',
    'pending'        => 'pending',
    'deny'           => 'failed',
    'cancel'         => 'canceled',
    'expire'         => 'expired',
    'refund'         => 'refunded',
    'partial_refund' => 'partially_refunded',
];
$status_db = $map[$tx_status] ?? $tx_status;

try {
    $pdo->beginTransaction();

    $stmt1 = $pdo->prepare("UPDATE payments SET status_payment=:st WHERE order_id=:oid");
    $stmt1->execute([':st' => $status_db, ':oid' => $order_id]);

    $stmt2 = $pdo->prepare("UPDATE orders SET status_order=:st WHERE order_id=:oid");
    $stmt2->execute([':st' => $status_db, ':oid' => $order_id]);

    $pdo->commit();
    log_cb("UPDATED oid=$order_id status=$tx_status");
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    log_cb("DB_ERR oid=$order_id " . $e->getMessage());
}

// 5) Wajib balas 200
echo json_encode(['ok' => true]);



<?php
// public_html/api/notification.php
declare(strict_types=1);
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
// Midtrans menganggap sukses jika HTTP 200. Kita pastikan eksplisit.
http_response_code(200);

// 1) Server key dari env
$SERVER_KEY = $_ENV['MIDTRANS_SERVER_KEY']
    ?? $_SERVER['MIDTRANS_SERVER_KEY']
    ?? getenv('MIDTRANS_SERVER_KEY')
    ?? '';

// 2) Logging sederhana
$logf = __DIR__ . '/midtrans-callback.log';
function log_cb(string $m): void {
  global $logf; @file_put_contents($logf, '['.date('c')."] $m\n", FILE_APPEND);
}

// 3) Terima ping GET/HEAD dari test
$method = $_SERVER['REQUEST_METHOD'] ?? 'POST';
if ($method !== 'POST') {
  log_cb('PING method=' . $method . ' ip=' . ($_SERVER['REMOTE_ADDR'] ?? '?'));
  echo json_encode(['ok'=>true,'msg'=>'pong']); // 200
  exit;
}

// 4) Ambil body
$raw  = file_get_contents('php://input') ?: '';
$data = json_decode($raw, true) ?: [];

// 5) Signature validation (jangan update DB bila invalid)
$order_id     = (string)($data['order_id'] ?? '');
$status_code  = (string)($data['status_code'] ?? '');
$gross_amount = (string)($data['gross_amount'] ?? '');
$sig_recv     = (string)($data['signature_key'] ?? '');
$sig_calc     = $SERVER_KEY ? hash('sha512', $order_id.$status_code.$gross_amount.$SERVER_KEY) : '';

if ($SERVER_KEY === '') {
  log_cb('NO_SERVER_KEY set');
  echo json_encode(['ok'=>true]); // tetap 200 agar Midtrans tidak retry
  exit;
}
if ($sig_recv === '' || !hash_equals($sig_calc, $sig_recv)) {
  log_cb("INVALID_SIGNATURE oid=$order_id calc=$sig_calc recv=$sig_recv raw=$raw");
  // simpan ke webhook_logs (best-effort)
  try {
    require __DIR__ . '/db.php';
    @$pdo->prepare("INSERT INTO webhook_logs (order_id, status, payload_json, created_at)
                    VALUES (:oid, 'invalid_signature', :payload, NOW())")
        ->execute([':oid'=>$order_id, ':payload'=>$raw]);
  } catch (\Throwable $e) { /* ignore */ }
  echo json_encode(['ok'=>true]);
  exit;
}

// 6) Map status → kosa kata konsisten
$tx_status = strtolower((string)($data['transaction_status'] ?? 'pending'));
$map = [
  'capture'        => 'paid',
  'settlement'     => 'paid',
  'pending'        => 'pending',
  'deny'           => 'failed',
  'cancel'         => 'canceled',
  'expire'         => 'expired',
  'refund'         => 'refunded',
  'partial_refund' => 'refunded', // atau 'partially_refunded' kalau kamu pakai itu
];
$status_db = $map[$tx_status] ?? 'pending';

// 7) Update DB (no-downgrade + upsert payments) + log webhook
try {
  require __DIR__ . '/db.php';
  $pdo->beginTransaction();

  // Upsert payments (butuh UNIQUE KEY(order_id) di payments)
  $transaction_id = (string)($data['transaction_id'] ?? '');
  $payment_type   = (string)($data['payment_type'] ?? '');
  $gross_int      = isset($data['gross_amount']) ? (int)round((float)$data['gross_amount']) : null;

  $pdo->prepare("
    INSERT INTO payments (order_id, transaction_id, payment_type, gross_amount,
                          status_payment, payload_json, created_at, updated_at)
    VALUES (:oid, :txid, :ptype, :amt, :st, :payload, NOW(), NOW())
    ON DUPLICATE KEY UPDATE
      transaction_id = COALESCE(VALUES(transaction_id), transaction_id),
      payment_type   = COALESCE(VALUES(payment_type),   payment_type),
      gross_amount   = COALESCE(VALUES(gross_amount),   gross_amount),
      status_payment = VALUES(status_payment),
      payload_json   = VALUES(payload_json),
      updated_at     = NOW()
  ")->execute([
    ':oid'=>$order_id,
    ':txid'=>$transaction_id ?: null,
    ':ptype'=>$payment_type ?: null,
    ':amt'=>$gross_int,
    ':st'=>$status_db,
    ':payload'=>json_encode($data, JSON_UNESCAPED_UNICODE),
  ]);

  // Update orders: tidak menurunkan status jika sudah 'paid'
  $pdo->prepare("
    UPDATE orders
       SET status_order = CASE WHEN status_order='paid' THEN 'paid' ELSE :st END,
           paid_at      = CASE WHEN :st='paid' AND paid_at IS NULL THEN NOW() ELSE paid_at END,
           updated_at   = NOW()
     WHERE order_id = :oid
  ")->execute([':st'=>$status_db, ':oid'=>$order_id]);

  // Simpan jejak ke webhook_logs (best-effort)
  @$pdo->prepare("
    INSERT INTO webhook_logs (order_id, status, payload_json, created_at)
    VALUES (:oid, :st, :payload, NOW())
  ")->execute([':oid'=>$order_id, ':st'=>$tx_status, ':payload'=>$raw]);

  $pdo->commit();
  log_cb("UPDATED oid=$order_id tx=$tx_status -> db=$status_db");
} catch (\Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  log_cb("DB_ERR oid=$order_id ".$e->getMessage());
  // tetap 200 agar Midtrans tidak banjir retry
}

// 8) Balasan wajib 200
echo json_encode(['ok'=>true]);
