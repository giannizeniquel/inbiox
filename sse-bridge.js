const http = require('http');
const PORT = 4201;

http.createServer((req, res) => {
  if (!req.url?.startsWith('/.well-known/mercure')) {
    res.writeHead(404); res.end(); return;
  }
  
  console.log('Bridge: connected');
  res.writeHead(200, {
    'Content-Type': 'text/event-stream',
    'Cache-Control': 'no-cache',
    'Connection': 'keep-alive',
    'Access-Control-Allow-Origin': '*',
  });
  res.write(':\n\n'); // heartbeat
  res.flushHeaders();

  const client = http.request('http://localhost:3000' + req.url, (mercureRes) => {
    mercureRes.on('data', (chunk) => {
      const text = chunk.toString();
      if (text.trim()) {
        res.write(text);
        res.write('\n');
      }
    });
    mercureRes.on('end', () => res.end());
    mercureRes.on('error', () => res.end());
  });
  
  client.on('error', () => res.end());
  client.end();
  
  req.on('close', () => {
    console.log('Bridge: disconnected');
    client.destroy();
  });
}).listen(PORT, () => console.log('SSE Bridge on port', PORT));
