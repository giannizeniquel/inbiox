#!/usr/bin/env php
<?php
/**
 * Generate a JWT for Mercure publishing
 */
$secret = 'b907a3fc288a0f1ecd7da752103cd6439812e98eb03b3e6af14fdb41f6af44e5';

// Simple JWT header
$header = base64_encode(json_encode(['typ' => 'JWT', 'alg' => 'HS256']));

// JWT payload with mercure claims
$payload = base64_encode(json_encode([
    'mercure' => [
        'publish' => ['*']  // Allow publishing to all topics
    ],
    'exp' => time() + 3600,  // 1 hour expiry
]));

// Create signature
$signature = hash_hmac('sha256', "$header.$payload", $secret);
$signature = rtrim(strtr(base64_encode hex2bin($signature), '+/', '-_');

$token = "$header.$payload.$signature";

echo $token . PHP_EOL;