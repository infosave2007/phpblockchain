<?php

use Blockchain\Core\Cryptography\KeyPair;
use Blockchain\Core\Cryptography\Mnemonic;

require_once __DIR__ . '/vendor/autoload.php';

// Creates a correct EVM wallet, the original code in the project created what looked like a EVM compat,
// but when imported to services to trustwallet/MetaMask whould show a different address,

// Generate a 12-word mnemonic phrase
$mnemonic = Mnemonic::generate();
print_r($mnemonic);

// Generate the keypair
$keyPair = KeyPair::fromMnemonic(implode(' ', $mnemonic), '');
print_r($keyPair);

// All correct - can now be imported into meta mask or others
echo 'address:'. $keyPair->getAddress(). "\n";
echo 'public_key:'. $keyPair->getPublicKey(). "\n";
echo 'private_key:'. $keyPair->getPrivateKey(). "\n";
echo 'mnemonic:'. print_r($mnemonic). "\n";

