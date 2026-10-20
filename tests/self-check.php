<?php
// Run with: php tests/self-check.php
require_once __DIR__ . '/../includes/class-simplepay-api-client.php';

$failures = [];
$check = static function (bool $ok, string $message) use (&$failures) {
    if (!$ok) {
        $failures[] = $message;
    }
};

$key = 'test-secret-key';

$sig = SimplePayApiClient::sign('{"a":1}', $key);
$check(SimplePayApiClient::verify('{"a":1}', $sig, $key), 'verify accepts a valid signature');
$check(!SimplePayApiClient::verify('{"a":2}', $sig, $key), 'verify rejects a tampered body');

$json = json_encode(['r' => 0, 't' => 99310118, 'e' => 'SUCCESS', 'm' => 'PUBLICTESTHUF', 'o' => 'givewp-12']);
$r = base64_encode($json);
$s = SimplePayApiClient::sign($json, $key);
$payload = SimplePayApiClient::decodeBackPayload($r, $s, $key);
$check(is_array($payload) && $payload['e'] === 'SUCCESS', 'decodeBackPayload decodes a valid payload');
$damaged = SimplePayApiClient::decodeBackPayload(str_replace('+', ' ', $r), str_replace('+', ' ', $s), $key);
$check(is_array($damaged) && $damaged['e'] === 'SUCCESS', 'decodeBackPayload survives "+" turned into spaces');
$check(SimplePayApiClient::decodeBackPayload($r, $s, 'wrong-key') === null, 'decodeBackPayload rejects a wrong key');
$check(SimplePayApiClient::decodeBackPayload('!!!', 'garbage', $key) === null, 'decodeBackPayload rejects garbage');

$ipn = ['salt' => 'abc', 'orderRef' => 'givewp-12', 'status' => 'FINISHED'];
[$ackJson, $ackSig] = SimplePayApiClient::buildIpnAck($ipn, $key);
$ack = json_decode($ackJson, true);
$keys = array_keys($ack);
$check(array_slice($keys, 0, 3) === array_keys($ipn), 'ack keeps the IPN key order');
$check(end($keys) === 'receiveDate' && preg_match('/^\d{4}-\d{2}-\d{2}T/', $ack['receiveDate']) === 1, 'ack ends with an ISO 8601 receiveDate');
$check(SimplePayApiClient::verify($ackJson, $ackSig, $key), 'ack signature verifies against the ack body');

$check(SimplePayApiClient::formatAmount('2500.00', 'HUF') === '2500', 'HUF amount is an integer string');
$check(SimplePayApiClient::formatAmount('12.50', 'EUR') === '12.50', 'EUR amount keeps two decimals');
$check(SimplePayApiClient::formatAmount('0.015', 'USD') === '0.02', 'USD amount rounds to two decimals');
$check(json_encode(['total' => SimplePayApiClient::formatAmount('0.10', 'EUR')]) === '{"total":"0.10"}', 'amount encodes without float noise');

$check(SimplePayApiClient::normalizeStatus(' authorised ') === 'AUTHORIZED', 'AUTHORISED is normalised');
$check(SimplePayApiClient::normalizeStatus('NOTAUTHORIZED') === 'NOTAUTHORIZED', 'NOTAUTHORIZED is unchanged');
$check(SimplePayApiClient::normalizeStatus('finished') === 'FINISHED', 'status is upper-cased');

$check(SimplePayApiClient::language('hu_HU') === 'HU', 'hu_HU is HU');
$check(SimplePayApiClient::language('en_GB') === 'EN', 'en_GB is EN');
$check(SimplePayApiClient::language('xx') === 'EN', 'unknown language falls back to EN');

if ($failures) {
    fwrite(STDERR, "FAILED:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
echo "All checks passed\n";
