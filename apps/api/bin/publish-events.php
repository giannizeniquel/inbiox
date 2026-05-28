#!/usr/bin/env php
<?php
/**
 * Simple Outbox Publisher - Pure PDO-based
 */

$pdo = new PDO('mysql:host=db;dbname=inbiox', 'root', 'root');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Find unpublished events
$stmt = $pdo->query("SELECT * FROM outbox_events WHERE published_at IS NULL ORDER BY created_at ASC LIMIT 100");
$events = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($events)) {
    echo "No pending events\n";
    exit(0);
}

echo sprintf("Found %d pending events\n", count($events));

$mercureUrl = 'http://host.docker.internal:3000/.well-known/mercure';
$jwtSecret = 'supersecret';

// Publish each to Mercure
foreach ($events as $event) {
    $data = json_decode($event['payload'], true);
    
    // Build SSE format for Mercure
    $payload = http_build_query([
        'topic' => $event['topic'],
        'data' => json_encode([
            'id' => $event['id'],
            'type' => $event['event_type'],
            'data' => $data,
        ]),
    ]);
    
    // Send to Mercure with JWT
    $ch = curl_init($mercureUrl);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/x-www-form-urlencoded',
        'Authorization: Bearer ' . $jwtSecret,
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    // Mark as published if successful
    if ($httpCode >= 200 && $httpCode < 400) {
        $stmt = $pdo->prepare("UPDATE outbox_events SET published_at = NOW() WHERE id = ?");
        $stmt->execute([$event['id']]);
        echo "✓ Published: {$event['topic']}\n";
    } else {
        echo "✗ Failed: {$event['topic']} (HTTP $httpCode)\n";
    }
}

echo "Done\n";