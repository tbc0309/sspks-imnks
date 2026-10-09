<?php

require dirname(__DIR__) . '/vendor/autoload.php';

use SSpkS\Package\SynologyKdf;

if (!function_exists('sodium_crypto_kdf_derive_from_key')) {
    fwrite(STDERR, "此测试需要 PHP ext-sodium 提供 KDF 支持。\n");
    exit(2);
}

$masterKey = hex2bin('CF0D8D6ECB95EF97D0AC6A7021D99124C699808CF2CC5157DFEA5EBF15C805E7');
$context = "SPKTEST\0";
$subkeyId = 5858862232513553547;
$idBytes = pack('P', $subkeyId);
$expected = sodium_crypto_kdf_derive_from_key(32, $subkeyId, $context, $masterKey);
$actual = SynologyKdf::derive($idBytes, $context, $masterKey);

if (!hash_equals($expected, $actual)) {
    throw new RuntimeException('纯 PHP 群晖 KDF 输出与 libsodium 不一致');
}

echo "群晖 KDF 测试通过。\n";
