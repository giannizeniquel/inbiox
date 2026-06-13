// SSE Bridge: Forwards Mercure SSE to avoid cross-origin issues
const http = require('http');
const https = require('http');

const MERCURE_URL = 'http://localhost:3000';
const PORT = 4201;

http.createServer((req, res) => {
  if (req.url?.startsWith('/.well-known/mercure')) {
    const targetUrl = MERCURE_URL + req.url;
    console.log('SSE Bridge:', req.method, targetUrl);
    
    res.setHeader('Access-Control-Allow-Origin', '*');
    res.setHeader('Content-Type', 'text/event-stream');
    res.setHeader('Cache-Control', 'no-cache');
    res.setHeader('Connection', 'keep-alive');
    
    const proxyReq = http.get(targetUrl, (proxyRes) => {
      proxyRes.pipe(res);
    });
    
    proxyReq.on('error', (err) => {
      console.error('SSE Bridge error:', err.message);
      res.end();
    });
    
    req.on('close', () => proxyReq.destroy());
    return;
  }
  res.writeHead(404);
  res.end();
}).listen(PORT, () => console.log(`SSE Bridge: localhost:${PORT} -> ${MERCURE_URL}`));
