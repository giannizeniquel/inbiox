#!/bin/bash
# SSE Stack: Mercure publisher + socat forwarder
# Keeps both processes alive for real-time SSE delivery

SOCAT_PORT=3099
MERCURE_PORT=3000
GATEWAY_IP=$(docker inspect -f '{{range.NetworkSettings.Networks}}{{.Gateway}}{{end}}' inbiox_php 2>/dev/null || echo "172.19.0.1")

echo "=== Starting SSE Stack ==="

# 1. Start socat forwarder (if not running)
if ! pgrep -f "socat.*$SOCAT_PORT" > /dev/null; then
  echo "Starting socat forwarder: $SOCAT_PORT → $MERCURE_PORT"
  nohup socat TCP-LISTEN:$SOCAT_PORT,fork,reuseaddr TCP:$GATEWAY_IP:$MERCURE_PORT > /tmp/socat-forwarder.log 2>&1 &
  echo "socat PID: $!"
fi

# 2. Start publisher (continuous loop)
echo "Starting publisher (host→Docker via socat)"
while true; do
  docker exec inbiox_php php /var/www/html/bin/console app:outbox:publish --status=pending 2>/dev/null
  sleep 2
done
