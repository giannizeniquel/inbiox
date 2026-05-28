#!/usr/bin/env node
/**
 * Simple Realtime Validation - subscribe FIRST, then generate traffic
 */

const http = require('http');
const axios = require('axios');

const MERCURE_URL = 'http://localhost:3000/.well-known/mercure';
const API_URL = 'http://localhost:8000';
const TICKETS = [137, 146, 135, 139, 67]; // Tickets with existing events

const state = {
    eventsReceived: 0,
    errors: 0,
};

const connections = [];

// Simple SSE client
function createSSE(ticketId) {
    return new Promise((resolve, reject) => {
        const url = `${MERCURE_URL}&topic=ticket/${ticketId}`;
        console.log(`[${ticketId}] Subscribing...`);
        
        const req = http.get(url, (res) => {
            console.log(`[${ticketId}] Connected (${res.statusCode})`);
            connections.push({ ticketId, req });
            
            let buffer = '';
            res.on('data', (chunk) => {
                buffer += chunk.toString();
                const events = buffer.split('\n\n');
                buffer = events.pop();
                
                for (const eventBlock of events) {
                    if (eventBlock.startsWith('data:')) {
                        const data = eventBlock.substring(5).trim();
                        try {
                            const payload = JSON.parse(data);
                            state.eventsReceived++;
                            console.log(`[${ticketId}] Event: seq=${payload.seq}, type=${payload.type}`);
                        } catch (e) {
                            // Ignore parse errors
                        }
                    }
                }
            });
            
            res.on('end', () => {
                console.log(`[${ticketId}] Connection ended`);
            });
            
            resolve();
        });
        
        req.on('error', (e) => {
            console.error(`[${ticketId}] Error:`, e.message);
            state.errors++;
            reject(e);
        });
        
        // Timeout
        setTimeout(() => {
            req.destroy();
            resolve();
        }, 5000);
    });
}

// Generate traffic
async function generateTraffic() {
    console.log('\n[Traffic] Generating requests...');
    for (let i = 0; i < 50; i++) {
        const ticketId = TICKETS[Math.floor(Math.random() * TICKETS.length)];
        try {
            await axios.post(`${API_URL}/debug/inbound/${ticketId}`, {
                content: `Test ${i}`,
            }, { timeout: 2000 });
        } catch (e) {
            // Ignore errors
        }
        await new Promise(r => setTimeout(r, 50));
    }
    console.log('[Traffic] Done generating');
}

async function main() {
    console.log('=== Realtime Validation ===\n');
    console.log('Step 1: Opening SSE subscriptions...');
    
    // Open all connections in parallel
    await Promise.all(TICKETS.map(t => createSSE(t).catch(e => null)));
    
    await new Promise(r => setTimeout(r, 2000));
    
    console.log('\nStep 2: Generating traffic...');
    await generateTraffic();
    
    // Wait for events
    await new Promise(r => setTimeout(r, 5000));
    
    console.log('\n=== RESULTS ===');
    console.log(`Events received: ${state.eventsReceived}`);
    console.log(`Errors: ${state.errors}`);
    console.log(`Status: ${state.eventsReceived > 0 ? 'PASS ✓' : 'FAIL ✗'}`);
    
    // Cleanup
    for (const { req } of connections) {
        req.destroy();
    }
    
    process.exit(state.eventsReceived > 0 ? 0 : 1);
}

main().catch(e => {
    console.error('Fatal:', e);
    process.exit(1);
});