<?php
// api/np-lite.php
declare(strict_types=1);

/* -------------------- 0) EARLY 200 CLOSE -------------------- */
ignore_user_abort(true);
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
http_response_code(200);

$ack = json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
if (!headers_sent()) {
  header('Content-Length: ' . strlen($ack));
}
echo $ack;
if (function_exists('fastcgi_finish_request')) {
  fastcgi_finish_request();          // PHP-FPM: tutup koneksi cepat
} else {
  @ob_flush(); @flush();              // Apache/CGI: paksa flush
}

/* -------------------- 1) UTIL & ENV -------------------- */
$logf = __DIR__ . '/midtrans-callback.log';
function log_cb(string $m): void { global $logf; @file_put_contents($logf, '['.date('c')."] $m\n", FILE_APPEND); }

$method = $_SERVER['REQUEST_METHOD'] ?? 'POST';
if ($method !== 'POST') { log_cb('PING method=' . $method); return; }

/* mini .env loader (agar MIDTRANS_SERVER_KEY tersedia sebelum validasi) */
$envPath = __DIR__ . '/../.env';
if (is_file($envPath)) {
  $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
  foreach ($lines as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#') continue;
    [$k, $v] = array_pad(explode('=', $line, 2), 2, '');
    $k = trim($k); $v = trim($v);
    $_ENV[$k] = $v;
    $_SERVER[$k] = $_SERVER[$k] ?? $v;
    if (!getenv($k)) putenv("$k=$v");
  }
}

/* -------------------- 2) PAYLOAD & SIGNATURE -------------------- */
$raw  = file_get_contents('php://input') ?: '';
$data = json_decode($raw, true) ?: [];

$SERVER_KEY = $_ENV['MIDTRANS_SERVER_KEY']
  ?? $_SERVER['MIDTRANS_SERVER_KEY']
  ?? getenv('MIDTRANS_SERVER_KEY')
  ?? '';

$order_id     = (string)($data['order_id'] ?? '');
$status_code  = (string)($data['status_code'] ?? '');
$gross_amount = (string)($data['gross_amount'] ?? '');   // string dari Midtrans, contoh "105000.00"
$sig_recv     = (string)($data['signature_key'] ?? '');
$sig_calc     = $SERVER_KEY ? hash('sha512', $order_id.$status_code.$gross_amount.$SERVER_KEY) : '';

if ($SERVER_KEY === '') { log_cb('NO_SERVER_KEY set'); return; }
if ($sig_recv === '' || !hash_equals($sig_calc, $sig_recv)) {
  log_cb("INVALID_SIGNATURE oid=$order_id calc=$sig_calc recv=$sig_recv raw=$raw");
  // simpan log minimal ke webhook_logs(headers, body)
  try {
    require __DIR__ . '/db.php';
    @$pdo->prepare("INSERT INTO webhook_logs(headers, body, created_at)
                    VALUES(:h,:b,NOW())")
        ->execute([':h'=>"invalid_signature oid=$order_id", ':b'=>$raw]);
  } catch (\Throwable $e) {}
  return;
}

/* -------------------- 3) MAP STATUS -------------------- */
$tx_status = strtolower((string)($data['transaction_status'] ?? 'pending'));
$map = [
  'capture'        => 'paid',
  'settlement'     => 'paid',
  'pending'        => 'pending',
  'deny'           => 'deny',
  'cancel'         => 'cancel',
  'expire'         => 'expire',
  'refund'         => 'refunded',
  'partial_refund' => 'partially_refunded',
];
$status_db      = $map[$tx_status] ?? 'pending';
$transaction_id = (string)($data['transaction_id'] ?? '');
$payment_type   = (string)($data['payment_type'] ?? '');

// simpan total sebagai INT (IDR): "105000.00" -> 105000
$gross_int = (int) round((float) $gross_amount);

/* -------------------- 4) DB WRITE -------------------- */
try {
  require __DIR__ . '/db.php';
  $pdo->beginTransaction();

  // payments upsert (butuh UNIQUE KEY(order_id))
  $pdo->prepare("
    INSERT INTO payments (order_id, transaction_id, payment_type, gross_amount,
                          status_payment, payload_json, created_at, updated_at)
    VALUES (:oid,:tx,:ptype,:gross,:st,:p,NOW(),NOW())
    ON DUPLICATE KEY UPDATE
      transaction_id  = NULLIF(VALUES(transaction_id), ''),
      payment_type    = NULLIF(VALUES(payment_type), ''),
      gross_amount    = VALUES(gross_amount),
      status_payment  = VALUES(status_payment),
      payload_json    = VALUES(payload_json),
      updated_at      = NOW()
  ")->execute([
    ':oid'=>$order_id,
    ':tx'=>$transaction_id,
    ':ptype'=>$payment_type,
    ':gross'=>$gross_int,
    ':st'=>$status_db,
    ':p'=>json_encode($data, JSON_UNESCAPED_UNICODE)
  ]);

  // orders: no-downgrade + set paid_at sekali saja
  $pdo->prepare("
    UPDATE orders
       SET status_order = CASE WHEN status_order='paid' THEN 'paid' ELSE :st END,
           paid_at      = CASE WHEN :st='paid' AND paid_at IS NULL THEN NOW() ELSE paid_at END,
           updated_at   = NOW()
     WHERE order_id = :oid
  ")->execute([':st'=>$status_db, ':oid'=>$order_id]);

  // jejak webhook ke tabel versi kamu (headers, body)
  @$pdo->prepare("INSERT INTO webhook_logs(headers, body, created_at)
                  VALUES(:h,:b,NOW())")
      ->execute([':h'=>"oid=$order_id status=$tx_status", ':b'=>$raw]);

  $pdo->commit();
  log_cb("UPDATED oid=$order_id tx=$tx_status -> db=$status_db gross=$gross_int");
} catch (\Throwable $e) {
  if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
  log_cb("DB_ERR oid=$order_id ".$e->getMessage());
}
