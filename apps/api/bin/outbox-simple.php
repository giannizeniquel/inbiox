#!/usr/bin/env php
<?php
/**
 * Simple Outbox Publisher - Direct DB approach
 * 
 * This is a minimal version that doesn't rely on Symfony container.
 * Just fetches unpublished events and marks them as published.
 */

$db = new PDO('mysql:host=db;dbname=inbiox', 'root', 'password', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

// Find unpublished events
$stmt = $db->query("SELECT * FROM outbox_events WHERE published_at IS NULL LIMIT 100");
$events = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($events)) {
    echo "No pending events\n";
    exit(0);
}

echo sprintf("Found %d pending events\n", count($events));

// Mark as published
$now = (new DateTime())->format('Y-m-d H:i:s');
$ids = array_column($events, 'id');

$placeholders = implode(',', array_fill(0, count($ids), '?'));
$stmt = $db->prepare("UPDATE outbox_events SET published_at = ? WHERE id IN ($placeholders)");
$stmt->execute(array_merge([$now], $ids));

echo sprintf("Published %d events\n", count($ids));