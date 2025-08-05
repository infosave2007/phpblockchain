<?php

use Blockchain\Core\Cryptography\KeyPair;
use Blockchain\Core\Cryptography\Mnemonic;

require_once __DIR__ . '/vendor/autoload.php';

$mnemonic = Mnemonic::generate();

// Create key pair from mnemonic
$keyPair = KeyPair::fromMnemonic($mnemonic, '');

// All tests
echo 'address: ' . $keyPair->getAddress()."\n";
echo 'public_key: ' . $keyPair->getPublicKey()."\n";
echo 'private_key: ' . $keyPair->getPrivateKey()."\n";
echo 'mnemonic: ' . $mnemonic."\n";


echo "\n\nSanity Check: ";
$mnemonic = "profit business cause evoke onion speed bean economy nephew edit balcony trophy";
$keyPair = KeyPair::fromMnemonic($mnemonic, '');
$address = $keyPair->getAddress();
echo 'address: ' . $address."\n";
echo 'public_key: ' . $keyPair->getPublicKey() . "\n";
echo 'private_key: ' . $keyPair->getPrivateKey() . "\n";
echo 'mnemonic: ' . $mnemonic . "\n";
