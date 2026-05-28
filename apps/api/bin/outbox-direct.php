#!/usr/bin/env php
<?php
/**
 * Direct Outbox Publisher - Bypasses Doctrine to publish directly via CURL
 * 
 * This is a workaround for the Doctrine Ulid mapping issue.
 * Uses: DBAL connection with raw SQL + curl to Mercure
 */

require __DIR__.'/vendor/autoload.php';

use Doctrine\DBAL\DriverManager;

// Create simple connection
$conn = DriverManager::getConnection([
    'driver' => 'pdo_mysql',
    'host' => 'db',
    'dbname' => 'inbiox',
    'user' => 'root',
    'password' => 'password',
]);

// Fetch unpublished events
$sql = "SELECT id, topic, event_type, payload FROM outbox_events WHERE published_at IS NULL LIMIT 50";
$events = $conn->fetchAllAssociative($sql);

if (empty($events)) {
    echo "No pending events\n";
    exit(0);
}

echo sprintf("Found %d pending events\n", count($events));

// Publish each to Mercure via HTTP
$mercureUrl = getenv('MERCURE_URL') ?: 'http://mercure:3000/.well-known/mercure';
$jwtSecret = getenv('MERCURE_JWT_SECRET') ?: 'supersecret';

foreach ($events as $event) {
    $data = json_decode($event['payload'], true);
    
    // Build the update payload for Mercure
    $payload = json_encode([
        'id' => $event['id'],
        'type' => $event['event_type'],
        'data' => $data,
    ]);
    
    // Send to Mercure
    $ch = curl_init($mercureUrl);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $jwtSecret,
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    // Mark as published if successful
    if ($httpCode >= 200 && $httpCode < 300) {
        $conn->executeStatement(
            "UPDATE outbox_events SET published_at = NOW() WHERE id = ?",
            [$event['id']]
        );
        echo "Published: {$event['topic']}\n";
    } else {
        echo "Failed to publish: {$event['topic']} (HTTP $httpCode)\n";
    }
}