<?php

use Blockchain\Core\Cryptography\KeyPair;
use Blockchain\Core\Cryptography\Mnemonic;

require_once __DIR__ . '/vendor/autoload.php';

$mnemonic = Mnemonic::generate();
print_r($mnemonic);

// Create key pair from mnemonic
$keyPair = KeyPair::fromMnemonic(implode(' ', $mnemonic), '');
print_r($keyPair);

// All tests
echo 'address: ' . $keyPair->getAddress()."\n";
echo 'public_key: ' . $keyPair->getPublicKey()."\n";
echo 'private_key: ' . $keyPair->getPrivateKey()."\n";
echo 'mnemonic: ' . print_r($mnemonic)."\n";

