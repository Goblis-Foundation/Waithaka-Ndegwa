<?php
declare(strict_types=1);

/** Payment callbacks are hints. Only an authenticated provider lookup may settle
 * a contribution. Original provider_reference values are never replaced by receipts.
 * Provider contracts reviewed 2026-10-21:
 * https://developer.pesapal.com/how-to-integrate/e-commerce/api-30-json/gettransactionstatus
 * https://developer.paypal.com/api/orders/v2/orders-capture
 * https://developer.paypal.com/api/orders/v2/orders-get
 * https://developer.safaricom.co.ke/apis/MpesaExpressSimulate
 * https://github.com/safaricom/mpesa-php-sdk/blob/master/src/Mpesa.php
 */

function payment_minor_units(mixed $amount): int {
    if (!is_string($amount) && !is_int($amount) && !is_float($amount)) throw new InvalidArgumentException('Invalid payment amount.');
    if (is_float($amount) && !is_finite($amount)) throw new InvalidArgumentException('Invalid payment amount.');
    $value = (string)$amount;
    if (!preg_match('/^(0|[1-9][0-9]{0,9})(?:\.([0-9]{1,2}))?$/D', $value, $m)) throw new InvalidArgumentException('Use a positive amount with at most two decimal places.');
    return (int)$m[1] * 100 + (int)str_pad($m[2] ?? '', 2, '0');
}

function payment_decimal(mixed $amount): string {
    $minor = payment_minor_units($amount);
    return intdiv($minor,100).'.'.str_pad((string)($minor%100),2,'0',STR_PAD_LEFT);
}

function payment_equal(mixed $expected, mixed $actual): bool {
    try { return payment_minor_units($expected) === payment_minor_units($actual); } catch (InvalidArgumentException $e) { return false; }
}

class PaymentValidationException extends RuntimeException {}
function payment_assert(bool $condition, string $message): void {
    if (!$condition) throw new PaymentValidationException($message);
}

function payment_return_state(string $reference, string $provider = 'paypal'): string {
    return hash_hmac('sha256', 'payment-return|'.$provider.'|'.$reference, (string)app_config('app_key'));
}

function payment_primary_type(string $provider): string {
    return match($provider) { 'mpesa'=>'checkout','pesapal'=>'tracking','paypal'=>'order',default=>throw new InvalidArgumentException('Unknown payment provider.') };
}

function payment_provider_environment(string $provider): string { return setting($provider.'.environment','sandbox') === 'live' ? 'live' : 'sandbox'; }

function payment_provider_identifier(array $row, string $type): string {
    $st = db()->prepare('SELECT external_id FROM payment_provider_ids WHERE contribution_id=? AND provider=? AND identifier_type=? ORDER BY created_at LIMIT 1');
    $st->execute([$row['id'],$row['method'],$type]);
    $stored = $st->fetchColumn();
    if ($stored) return (string)$stored;
    // Recover historical checkout IDs without rewriting a receipt that v9 put in
    // provider_reference. Raw payloads may already be redacted, but IDs survive.
    $payload = json_decode((string)($row['provider_payload'] ?? ''),true);
    if ($row['method'] === 'mpesa') {
        if ($type === 'checkout') return (string)($payload['CheckoutRequestID'] ?? $payload['Body']['stkCallback']['CheckoutRequestID'] ?? (str_starts_with((string)$row['provider_reference'],'ws_CO_') ? $row['provider_reference'] : ''));
        if ($type === 'merchant') return (string)($payload['MerchantRequestID'] ?? $payload['Body']['stkCallback']['MerchantRequestID'] ?? '');
    }
    return $type === payment_primary_type($row['method']) ? (string)($row['provider_reference'] ?? '') : '';
}

function payment_find(string $provider, string $externalId, string $reference = ''): ?array {
    if ($externalId === '' || strlen($externalId) > 190) return null;
    $st = db()->prepare('SELECT DISTINCT c.* FROM contributions c LEFT JOIN payment_provider_ids i ON i.contribution_id=c.id AND i.provider=c.method AND i.identifier_type=? WHERE c.method=? AND (c.provider_reference=? OR i.external_id=?)'.($reference !== '' ? ' AND c.reference=?' : '').' LIMIT 2');
    $values = [payment_primary_type($provider),$provider,$externalId,$externalId];
    if ($reference !== '') $values[] = $reference;
    $st->execute($values); $rows = $st->fetchAll();
    if (count($rows) === 1) return $rows[0];
    if (count($rows) > 1) throw new RuntimeException('Ambiguous provider reference requires reconciliation.');
    if ($provider === 'mpesa') {
        $st = db()->prepare("SELECT * FROM contributions WHERE method='mpesa' AND JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(provider_payload) THEN provider_payload ELSE '{}' END,'$.Body.stkCallback.CheckoutRequestID'))=? LIMIT 2");
        $st->execute([$externalId]); $rows = $st->fetchAll();
        if (count($rows) === 1) return $rows[0];
    }
    return null;
}

function payment_claim_id(array $row, string $type, string $externalId): void {
    payment_assert($externalId !== '' && strlen($externalId) <= 190 && preg_match('/^[\x21-\x7e]+$/D',$externalId) === 1, 'Invalid provider identifier.');
    db()->prepare('INSERT INTO payment_provider_ids(provider,identifier_type,external_id,contribution_id) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE external_id=VALUES(external_id)')->execute([$row['method'],$type,$externalId,$row['id']]);
    $st = db()->prepare('SELECT contribution_id FROM payment_provider_ids WHERE provider=? AND identifier_type=? AND external_id=?');
    $st->execute([$row['method'],$type,$externalId]);
    payment_assert((int)$st->fetchColumn() === (int)$row['id'], 'This provider transaction is already attached to another contribution.');
}

function payment_event(array $row, string $source, string $outcome, array $payload): void {
    $safe = payment_payload_for_storage($payload);
    $key = hash('sha256', $row['method'].'|'.$row['id'].'|'.$source.'|'.$outcome.'|'.$safe);
    db()->prepare('INSERT IGNORE INTO payment_events(event_key,contribution_id,provider,event_source,outcome,payload) VALUES(?,?,?,?,?,?)')->execute([$key,$row['id'],$row['method'],$source,$outcome,$safe]);
}

function payment_bind_provider(int $id, string $provider, string $primary, array $identifiers, array $response): void {
    $pdo = db(); $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT * FROM contributions WHERE id=? FOR UPDATE'); $st->execute([$id]); $row = $st->fetch();
        payment_assert($row && $row['method'] === $provider, 'Payment provider does not match.');
        payment_assert(empty($row['provider_reference']) || hash_equals($row['provider_reference'],$primary), 'Provider reference cannot be replaced.');
        foreach ([payment_primary_type($provider)=>$primary] + $identifiers as $type=>$value) payment_claim_id($row,$type,(string)$value);
        $pdo->prepare("UPDATE contributions SET provider_reference=COALESCE(NULLIF(provider_reference,''),?),provider_payload=?,status=CASE WHEN status='initiated' THEN 'pending' ELSE status END WHERE id=?")->execute([$primary,payment_payload_for_storage($response),$id]);
        $pdo->prepare("INSERT INTO payment_reconciliation(contribution_id,provider_environment,next_attempt_at) VALUES(?,?,DATE_ADD(NOW(),INTERVAL 30 SECOND)) ON DUPLICATE KEY UPDATE provider_environment=COALESCE(provider_environment,VALUES(provider_environment)),next_attempt_at=IF(state IN ('verified','reversed'),next_attempt_at,IF(callback_payload IS NULL,VALUES(next_attempt_at),NOW())),state=IF(state IN ('verified','reversed'),state,'pending')")->execute([$id,payment_provider_environment($provider)]);
        payment_event($row,'initiation','pending',$response);
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}

function payment_start_tracking(int $id, string $provider): void {
    db()->prepare('INSERT INTO payment_reconciliation(contribution_id,provider_environment,next_attempt_at) VALUES(?,?,DATE_ADD(NOW(),INTERVAL 60 SECOND))')->execute([$id,payment_provider_environment($provider)]);
}

function payment_paypal_merchant(array $row): string {
    $configured=trim((string)setting('paypal.merchant_id',''));
    if($configured!=='')return $configured;
    $original=json_decode((string)($row['provider_payload']??''),true);
    return (string)($original['purchase_units'][0]['payee']['merchant_id']??'');
}

function payment_evaluate_pesapal(array $row, string $tracking, array $response): array {
    payment_assert($row['method'] === 'pesapal' && !empty($row['_primary']) && hash_equals($row['_primary'],$tracking), 'Pesapal tracking ID does not match.');
    payment_assert((string)($response['status'] ?? '') === '200', 'Pesapal has not returned a successful status lookup.');
    payment_assert(empty($response['error']['code']) && empty($response['error']['message']), 'Pesapal reported a lookup error.');
    payment_assert((string)($response['merchant_reference'] ?? '') === $row['reference'], 'Pesapal merchant reference does not match.');
    payment_assert((string)($response['currency'] ?? '') === $row['currency'], 'Pesapal currency does not match.');
    payment_assert(payment_equal($row['amount'],$response['amount'] ?? null), 'Pesapal amount does not match.');
    $codes = ['0'=>['INVALID','failed'],'1'=>['COMPLETED','completed'],'2'=>['FAILED','failed'],'3'=>['REVERSED','cancelled']];
    $code = (string)($response['status_code'] ?? '');
    payment_assert(isset($codes[$code]) && strtoupper((string)($response['payment_status_description'] ?? '')) === $codes[$code][0], 'Pesapal returned an unrecognised or conflicting status.');
    $ids = [];
    if (!empty($response['confirmation_code'])) $ids['confirmation'] = (string)$response['confirmation_code'];
    return ['status'=>$codes[$code][1],'level'=>'amount_currency_status','reversed'=>$code === '3','ids'=>$ids,'detail'=>'Authenticated Pesapal status, merchant reference, amount and currency match.'];
}

function payment_evaluate_paypal(array $row, string $orderId, array $response, string $merchantId = ''): array {
    payment_assert($row['method'] === 'paypal' && !empty($row['_primary']) && hash_equals($row['_primary'],$orderId) && (string)($response['id'] ?? '') === $orderId, 'PayPal order ID does not match.');
    payment_assert(($response['intent'] ?? '') === 'CAPTURE', 'PayPal order intent does not match.');
    $units = $response['purchase_units'] ?? [];
    payment_assert(is_array($units) && count($units) === 1, 'PayPal purchase units do not match.');
    $unit = reset($units);
    payment_assert(($unit['reference_id'] ?? '') === $row['reference'] && ($unit['custom_id'] ?? '') === $row['reference'], 'PayPal contribution reference does not match.');
    payment_assert(($unit['amount']['currency_code'] ?? '') === $row['currency'] && payment_equal($row['amount'],$unit['amount']['value'] ?? null), 'PayPal order amount or currency does not match.');
    payment_assert($merchantId!=='' && ($unit['payee']['merchant_id'] ?? '') === $merchantId, 'PayPal receiving merchant is unknown or does not match the original order.');
    $status = (string)($response['status'] ?? '');
    payment_assert(in_array($status,['CREATED','SAVED','APPROVED','PAYER_ACTION_REQUIRED','COMPLETED','VOIDED'],true), 'PayPal returned an unrecognised order status.');
    if ($status !== 'COMPLETED') return ['status'=>$status === 'VOIDED' ? 'cancelled' : 'pending','level'=>'order_only','ids'=>[],'detail'=>'PayPal order checked; no completed capture has been verified.'];
    $captures = $unit['payments']['captures'] ?? [];
    payment_assert(is_array($captures) && count($captures) > 0, 'PayPal completed order has no capture details.');
    $total = 0; $ids = []; $seen = [];
    foreach ($captures as $capture) {
        payment_assert(($capture['status'] ?? '') === 'COMPLETED', 'PayPal capture has not completed or was reversed.');
        payment_assert(($capture['amount']['currency_code'] ?? '') === $row['currency'], 'PayPal capture currency does not match.');
        $id = (string)($capture['id'] ?? '');
        payment_assert($id !== '' && !isset($seen[$id]), 'PayPal capture identifier is missing or duplicated.');
        $seen[$id] = true; $ids[] = ['capture',$id];
        $amount = payment_minor_units($capture['amount']['value'] ?? null);
        payment_assert($amount > 0, 'PayPal capture amount is invalid.');
        $total += $amount;
    }
    payment_assert($total === payment_minor_units($row['amount']), 'PayPal captured amount does not match.');
    // Refunds/partial reversals need a separate reconciliation decision; an old
    // completed capture must never be presented as a fresh verified contribution.
    payment_assert(empty($unit['payments']['refunds']), 'PayPal refund requires reconciliation.');
    return ['status'=>'completed','level'=>'amount_currency_status','ids'=>$ids,'detail'=>'Authenticated PayPal order and completed capture amounts, currency and references match.'];
}

function payment_evaluate_mpesa(array $row, array $query, ?array $callback): array {
    payment_assert($row['method'] === 'mpesa' && $row['currency'] === 'KES' && payment_minor_units($row['amount']) % 100 === 0, 'M-Pesa amount/currency is invalid.');
    payment_assert(!empty($row['_primary']) && ($query['CheckoutRequestID'] ?? '') === $row['_primary'], 'M-Pesa checkout ID does not match.');
    payment_assert(!empty($row['_merchant']) && ($query['MerchantRequestID'] ?? '') === $row['_merchant'], 'M-Pesa merchant request ID does not match.');
    payment_assert((string)($query['ResponseCode'] ?? '') === '0' && array_key_exists('ResultCode',$query), 'M-Pesa query has not returned a final result.');
    $code = (string)$query['ResultCode'];
    payment_assert(preg_match('/^[0-9]+$/D',$code) === 1, 'M-Pesa query result is invalid.');
    if ($code !== '0') return ['status'=>'failed','level'=>'status_only','ids'=>[],'detail'=>'Authenticated STK query reports unsuccessful checkout.'];
    payment_assert(is_array($callback), 'M-Pesa status succeeded; receipt metadata still requires reconciliation.');
    $cb = $callback['Body']['stkCallback'] ?? [];
    payment_assert(($cb['CheckoutRequestID'] ?? '') === $row['_primary'] && ($cb['MerchantRequestID'] ?? '') === $row['_merchant'] && (string)($cb['ResultCode'] ?? '') === '0', 'M-Pesa callback conflicts with the authenticated query.');
    $metadata = [];
    foreach (($cb['CallbackMetadata']['Item'] ?? []) as $item) {
        if (isset($item['Name'],$item['Value'])) { payment_assert(!array_key_exists($item['Name'],$metadata),'M-Pesa callback repeats metadata.'); $metadata[$item['Name']] = $item['Value']; }
    }
    payment_assert(payment_equal($row['amount'],$metadata['Amount'] ?? null), 'M-Pesa callback amount does not match.');
    $receipt = (string)($metadata['MpesaReceiptNumber'] ?? '');
    payment_assert(preg_match('/^[A-Z0-9]{8,32}$/D',$receipt) === 1, 'M-Pesa receipt is missing or invalid.');
    return ['status'=>'completed','level'=>'status_only','ids'=>[['receipt_claim',$receipt]],'detail'=>'Authenticated STK query confirms this checkout. Amount matches the original request and callback; unsigned receipt metadata still needs statement reconciliation.'];
}

function payment_next_status(string $current, string $incoming, bool $verifiedReversal = false): string {
    payment_assert(in_array($incoming,['pending','completed','failed','cancelled'],true),'Invalid payment status.');
    if ($current === 'completed' && !($incoming === 'cancelled' && $verifiedReversal)) return 'completed';
    if ($incoming === 'pending' && in_array($current,['failed','cancelled'],true)) return $current;
    return $incoming;
}

function payment_apply_verified(array $row, array $decision, array $response, string $source): void {
    $pdo = db(); $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT * FROM contributions WHERE id=? FOR UPDATE'); $st->execute([$row['id']]); $current = $st->fetch();
        payment_assert($current && $current['method'] === $row['method'], 'Contribution provider changed.');
        payment_assert($current['reference']===$row['reference'] && $current['currency']===$row['currency'] && payment_equal($current['amount'],$row['amount']), 'Contribution details changed during verification.');
        $st = $pdo->prepare('SELECT state FROM payment_reconciliation WHERE contribution_id=? FOR UPDATE');$st->execute([$row['id']]);$priorState=$st->fetchColumn();
        if($priorState==='reversed'&&empty($decision['reversed'])){
            payment_event($current,$source,'ignored_after_reversal',$response);$pdo->commit();return;
        }
        foreach ($decision['ids'] ?? [] as $type=>$id) {
            if (is_array($id)) payment_claim_id($current,$id[0],$id[1]); else payment_claim_id($current,(string)$type,(string)$id);
        }
        $status = payment_next_status($current['status'],$decision['status'],!empty($decision['reversed']));
        payment_event($current,$source,$decision['status'],$response);
        // Preserve completed evidence if an older/pending response arrives later.
        if ($status === $decision['status']) {
            $pdo->prepare('UPDATE contributions SET status=? WHERE id=?')->execute([$status,$row['id']]);
            $state = !empty($decision['reversed']) ? 'reversed' : ($status === 'pending' ? 'pending' : 'verified');
            $pdo->prepare('INSERT INTO payment_reconciliation(contribution_id,state,verification_level,detail,last_checked_at,verified_at,next_attempt_at) VALUES(?,?,?,?,NOW(),IF(?=\'pending\',NULL,NOW()),IF(?=\'pending\',DATE_ADD(NOW(),INTERVAL 5 MINUTE),NULL)) ON DUPLICATE KEY UPDATE state=VALUES(state),verification_level=VALUES(verification_level),detail=VALUES(detail),last_checked_at=NOW(),verified_at=VALUES(verified_at),next_attempt_at=VALUES(next_attempt_at)')->execute([$row['id'],$state,$decision['level'],$decision['detail'],$status,$status]);
        }
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}

function payment_needs_review(array $row, string $message, string $state = 'review'): void {
    // Preserve the financial status. An operator can still see an unresolved
    // refund/mismatch even when the original capture was previously verified.
    db()->prepare("INSERT INTO payment_reconciliation(contribution_id,state,detail,next_attempt_at) VALUES(?,?,?,IF(?='retry',DATE_ADD(NOW(),INTERVAL 5 MINUTE),NULL)) ON DUPLICATE KEY UPDATE state=IF(state='reversed',state,VALUES(state)),detail=IF(state='reversed',detail,VALUES(detail)),next_attempt_at=IF(state='reversed',NULL,VALUES(next_attempt_at))")->execute([$row['id'],$state,mb_substr($message,0,500),$state]);
}

/** Read-only provider queries; safe for a bounded reconciliation worker. This
 * function never initiates/captures a payment. Returns false when leased/not due.
 */
function payment_reconcile(int $id, bool $force = false): bool {
    $st = db()->prepare('SELECT * FROM contributions WHERE id=?'); $st->execute([$id]); $row = $st->fetch();
    if (!$row) return false;
    db()->prepare('INSERT IGNORE INTO payment_reconciliation(contribution_id) VALUES(?)')->execute([$id]);
    $lease = bin2hex(random_bytes(16));
    $st = db()->prepare('UPDATE payment_reconciliation SET lease_token=?,lease_until=DATE_ADD(NOW(),INTERVAL 90 SECOND),attempts=attempts+1,last_checked_at=NOW() WHERE contribution_id=? AND (lease_until IS NULL OR lease_until<NOW())'.($force ? '' : ' AND (next_attempt_at IS NULL OR next_attempt_at<=NOW())'));
    $st->execute([$lease,$id]); if (!$st->rowCount()) return false;
    $response=[];
    try {
        $st = db()->prepare('SELECT * FROM payment_reconciliation WHERE contribution_id=?'); $st->execute([$id]); $reconciliation = $st->fetch();
        payment_assert(!empty($reconciliation['provider_environment']) && $reconciliation['provider_environment'] === payment_provider_environment($row['method']), 'Provider environment is unknown or has changed; verify the original environment before reconciliation.');
        $row['_primary'] = payment_provider_identifier($row,payment_primary_type($row['method']));
        payment_assert($row['_primary'] !== '', 'The original checkout identifier is missing; reconcile against provider records.');
        if ($row['method'] === 'pesapal') {
            $response = pesapal_status($row['_primary']);
            $decision = payment_evaluate_pesapal($row,$row['_primary'],$response);
        } elseif ($row['method'] === 'paypal') {
            $response = paypal_get_order($row['_primary']);
            $decision = payment_evaluate_paypal($row,$row['_primary'],$response,payment_paypal_merchant($row));
        } else {
            $row['_merchant'] = payment_provider_identifier($row,'merchant');
            $response = mpesa_stk_query($row['_primary']);
            $callback = json_decode((string)($reconciliation['callback_payload'] ?? ''),true);
            $decision = payment_evaluate_mpesa($row,$response,is_array($callback) ? $callback : null);
        }
        payment_apply_verified($row,$decision,$response,'provider_query');
    } catch (Throwable $e) {
        payment_needs_review($row,$e->getMessage(),$e instanceof PaymentValidationException || $e instanceof InvalidArgumentException ? 'review' : 'retry');
        payment_event($row,'provider_query','unconfirmed',['reason'=>$e->getMessage(),'response'=>$response]);
        return false;
    } finally {
        db()->prepare('UPDATE payment_reconciliation SET lease_token=NULL,lease_until=NULL WHERE contribution_id=? AND lease_token=?')->execute([$id,$lease]);
    }
    return true;
}

function payment_record_mpesa_callback(array $payload, string $reference = '', string $state = ''): ?array {
    $cb = $payload['Body']['stkCallback'] ?? [];
    $checkout = (string)($cb['CheckoutRequestID'] ?? '');
    $row = payment_find('mpesa',$checkout);
    // A callback can arrive before initiation's response is stored. The private
    // callback URL correlates it, but does not authenticate the callback sender.
    if (!$row && $reference !== '' && hash_equals(payment_return_state($reference,'mpesa'),$state)) {
        $st = db()->prepare("SELECT * FROM contributions WHERE reference=? AND method='mpesa' AND status='initiated'"); $st->execute([$reference]); $row = $st->fetch() ?: null;
    }
    if (!$row) return null;
    $known = payment_provider_identifier($row,'checkout');
    payment_assert($checkout !== '' && strlen($checkout) <= 190 && ($known === '' || hash_equals($known,$checkout)), 'M-Pesa callback checkout does not match.');
    $merchant = payment_provider_identifier($row,'merchant');
    payment_assert($merchant === '' || ($cb['MerchantRequestID'] ?? '') === $merchant, 'M-Pesa callback merchant request does not match.');
    payment_event($row,'callback','unverified',$payload);
    db()->prepare("INSERT INTO payment_reconciliation(contribution_id,callback_payload,next_attempt_at) VALUES(?,?,NOW()) ON DUPLICATE KEY UPDATE callback_payload=IF(state='verified',callback_payload,VALUES(callback_payload)),next_attempt_at=IF(state='review' OR last_checked_at IS NULL,NOW(),next_attempt_at),state=IF(state='review','pending',state)")->execute([$row['id'],payment_payload_for_storage($payload)]);
    return $row;
}

/** Worker contract: bounded batch of provider status lookups, no capture calls. */
function payment_reconcile_due(int $limit = 10): array {
    $ids = db()->query("SELECT contribution_id FROM payment_reconciliation WHERE state IN ('pending','retry') AND (next_attempt_at IS NULL OR next_attempt_at<=NOW()) AND (lease_until IS NULL OR lease_until<NOW()) ORDER BY COALESCE(next_attempt_at,'1970-01-01') LIMIT ".max(1,min(50,$limit)))->fetchAll(PDO::FETCH_COLUMN);
    $result = ['checked'=>0,'confirmed'=>0];
    foreach ($ids as $id) { $result['checked']++; if (payment_reconcile((int)$id)) $result['confirmed']++; }
    return $result;
}
