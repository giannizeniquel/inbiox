#!/usr/bin/env node
/**
 * Realtime Validation Script for Inbiox
 * 
 * Validates the realtime pipeline: UseCase → Outbox → Publisher → Mercure → SSE
 * 
 * Usage: node scripts/realtime-validation.js
 */

const http = require('http');
const https = require('https');
const axios = require('axios');

// ============================================================================
// CONFIGURATION
// ============================================================================

const CONFIG = {
    // Mercure SSE endpoint
    MERCURE_URL: process.env.MERCURE_URL || 'http://localhost:3000/.well-known/mercure',
    // API base URL for load generation
    API_BASE_URL: process.env.API_BASE_URL || 'http://localhost:8000',
    // Ticket IDs to subscribe to
    TICKETS: parseInt(process.env.TICKETS || '10'),
    // Specific ticket IDs to test (comma-separated, or use TICKETS count to generate)
    TICKET_IDS: process.env.TICKET_IDS ? process.env.TICKET_IDS.split(',').map(Number) : null,
    // Test duration in seconds
    TEST_DURATION_SECONDS: parseInt(process.env.DURATION || '30'),
    // Requests per second for load generation
    REQUESTS_PER_SECOND: parseInt(process.env.RPS || '10'),
};

// ============================================================================
// STATE
// ============================================================================

const state = {
    // Per-ticket event tracking
    eventsPerTicket: new Map(),
    maxSeqPerTicket: new Map(),
    lastSeqPerTicket: new Map(),
    
    // Metrics
    totalEventsReceived: 0,
    invalidPayloadCount: 0,
    duplicatesCount: 0,
    outOfOrderCount: 0,
    disconnectCount: 0,
    reconnectCount: 0,
    streamErrors: 0,
    
    // Active connections
    connections: new Map(),
    
    // Traffic generation state
    activeRequests: 0,
    shutdown: false,
};

// Valid FSM transitions
const VALID_TRANSITIONS = {
    'new': ['active'],
    'active': ['waiting', 'resolved'],
    'waiting': ['active', 'resolved'],
    'resolved': ['closed'],
    'closed': [],
};

// ============================================================================
// SSE CLIENT (Native)
// ============================================================================

class SSIClient {
    constructor(url, options = {}) {
        this.url = url;
        this.options = options;
        this.buffer = '';
        this.running = false;
        this.client = null;
    }
    
    start() {
        return new Promise((resolve, reject) => {
            const parsed = new URL(this.url);
            const isHttps = parsed.protocol === 'https:';
            const lib = isHttps ? https : http;
            
            const opts = {
                hostname: parsed.hostname,
                port: parsed.port || (isHttps ? 443 : 80),
                path: parsed.pathname + parsed.search,
                method: 'GET',
                headers: {
                    'Accept': 'text/event-stream',
                    'Cache-Control': 'no-cache',
                    'Connection': 'keep-alive',
                },
            };
            
            this.client = lib.request(opts, (res) => {
                if (res.statusCode >= 400) {
                    reject(new Error(`HTTP ${res.statusCode}`));
                    return;
                }
                
                this.running = true;
                res.on('data', (chunk) => this.handleData(chunk));
                res.on('end', () => {
                    this.running = false;
                    if (this.options.onerror) {
                        this.options.onerror(new Error('Connection closed'));
                    }
                });
                res.on('error', (err) => {
                    this.running = false;
                    if (this.options.onerror) {
                        this.options.onerror(err);
                    }
                });
                
                resolve();
            });
            
            this.client.on('error', reject);
            this.client.end();
        });
    }
    
    handleData(chunk) {
        this.buffer += chunk.toString();
        
        // Process complete events
        const lines = this.buffer.split('\n\n');
        this.buffer = lines.pop(); // Keep incomplete line
        
        for (const block of lines) {
            if (block.startsWith('data:')) {
                const data = block.substring(5).trim();
                if (this.options.onmessage) {
                    this.options.onmessage({ data });
                }
            }
        }
    }
    
    close() {
        this.running = false;
        if (this.client) {
            this.client.destroy();
            this.client = null;
        }
    }
}

// ============================================================================
// VALIDATION FUNCTIONS
// ============================================================================

/**
 * Validate event payload structure
 */
function validatePayload(eventData, ticketId) {
    // Check for valid JSON
    let payload;
    try {
        payload = typeof eventData === 'string' ? JSON.parse(eventData) : eventData;
    } catch (e) {
        state.invalidPayloadCount++;
        console.error(`[${ticketId}] Invalid JSON payload:`, e.message);
        return null;
    }
    
    // Validate required fields
    if (!payload.event_id || !payload.type || !payload.ticket_id || payload.seq === undefined) {
        state.invalidPayloadCount++;
        console.error(`[${ticketId}] Missing required fields:`, payload);
        return null;
    }
    
    return payload;
}

/**
 * Validate sequence ordering
 */
function validateOrdering(ticketId, seq) {
    const lastSeq = state.lastSeqPerTicket.get(ticketId);
    
    // First event for this ticket
    if (lastSeq === undefined) {
        state.lastSeqPerTicket.set(ticketId, seq);
        return 'valid';
    }
    
    // Compare with last seq
    if (seq > lastSeq) {
        state.lastSeqPerTicket.set(ticketId, seq);
        return 'valid';
    } else if (seq === lastSeq) {
        state.duplicatesCount++;
        return 'duplicate';
    } else {
        state.outOfOrderCount++;
        return 'out_of_order';
    }
}

// ============================================================================
// SSE CONNECTION MANAGEMENT
// ============================================================================

/**
 * Create SSE connection for a ticket
 */
async function createConnection(ticketId) {
    const baseUrl = CONFIG.MERCURE_URL.includes('?') 
        ? CONFIG.MERCURE_URL 
        : CONFIG.MERCURE_URL + '?';
    const url = `${baseUrl}&topic=ticket/${ticketId}`;
    
    console.log(`[ticket/${ticketId}] Connecting to SSE...`);
    
    const client = new SSIClient(url, {
        onmessage: (event) => {
            const payload = validatePayload(event.data, ticketId);
            if (!payload) return;
            
            state.totalEventsReceived++;
            
            // Track per-ticket events
            const count = (state.eventsPerTicket.get(ticketId) || 0) + 1;
            state.eventsPerTicket.set(ticketId, count);
            
            // Track max seq
            const currentMax = state.maxSeqPerTicket.get(ticketId) || 0;
            if (payload.seq > currentMax) {
                state.maxSeqPerTicket.set(ticketId, payload.seq);
            }
            
            // Validate ordering
            const orderStatus = validateOrdering(ticketId, payload.seq);
            
            console.log(`[ticket/${ticketId}] Event: seq=${payload.seq}, type=${payload.type}, order=${orderStatus}`);
        },
        onerror: (error) => {
            state.streamErrors++;
            console.error(`[ticket/${ticketId}] Stream error:`, error.message);
            state.disconnectCount++;
            
            // Reconnect if not shutting down
            if (!state.shutdown) {
                setTimeout(() => {
                    state.reconnectCount++;
                    createConnection(ticketId);
                }, 1000);
            }
        },
    });
    
    try {
        await client.start();
        state.connections.set(ticketId, client);
        console.log(`[ticket/${ticketId}] Connected`);
    } catch (err) {
        console.error(`[ticket/${ticketId}] Connection failed:`, err.message);
    }
}

/**
 * Cleanup connections
 */
function cleanup() {
    console.log('\n[Cleanup] Closing all connections...');
    for (const [ticketId, client] of state.connections) {
        try {
            client.close();
        } catch (e) {
            // Ignore
        }
    }
    state.connections.clear();
}

// ============================================================================
// LOAD GENERATION
// ============================================================================

/**
 * Execute inbound message request
 */
async function sendInboundMessage(ticketId) {
    try {
        const response = await axios.post(`${CONFIG.API_BASE_URL}/debug/inbound/${ticketId}`, {
            content: `Test message ${Date.now()}`,
        }, {
            timeout: 5000,
            validateStatus: (status) => status < 500,
        });
        return { success: response.status < 400, status: response.status };
    } catch (error) {
        return { success: false, status: error.response?.status || 0, error: error.message };
    }
}

/**
 * Execute outbound message request
 */
async function sendOutboundMessage(ticketId) {
    try {
        const response = await axios.post(`${CONFIG.API_BASE_URL}/debug/outbound/${ticketId}`, {
            content: `Test outbound ${Date.now()}`,
        }, {
            timeout: 5000,
            validateStatus: (status) => status < 500,
        });
        return { success: response.status < 400, status: response.status };
    } catch (error) {
        return { success: false, status: error.response?.status || 0, error: error.message };
    }
}

/**
 * Execute status transition request
 */
async function sendStatusTransition(ticketId) {
    // Pick a random valid next state
    const states = VALID_TRANSITIONS['active'];
    const newState = states[Math.floor(Math.random() * states.length)];
    
    try {
        const response = await axios.post(`${CONFIG.API_BASE_URL}/debug/status/${ticketId}`, {
            status: newState,
        }, {
            timeout: 5000,
            validateStatus: (status) => status < 500,
        });
        return { success: response.status < 400, status: response.status };
    } catch (error) {
        return { success: false, status: error.response?.status || 0, error: error.message };
    }
}

/**
 * Traffic generator loop
 */
async function trafficGenerator() {
    const ticketIds = CONFIG.TICKET_IDS || Array.from({length: CONFIG.TICKETS}, (_, i) => i + 1);
    
    while (!state.shutdown) {
        const promises = [];
        
        for (let i = 0; i < CONFIG.REQUESTS_PER_SECOND; i++) {
            const ticketId = ticketIds[Math.floor(Math.random() * ticketIds.length)];
            const op = Math.random();
            let promise;
            
            if (op < 0.4) {
                promise = sendInboundMessage(ticketId);
            } else if (op < 0.8) {
                promise = sendOutboundMessage(ticketId);
            } else {
                promise = sendStatusTransition(ticketId);
            }
            
            promises.push(
                promise
                    .then(result => {
                        if (!result.success && result.status === 422) {
                            // Sync local state would happen here
                        }
                    })
                    .catch(err => {
                        // Network errors expected under load
                    })
            );
        }
        
        state.activeRequests = promises.length;
        await Promise.all(promises);
        await new Promise(resolve => setTimeout(resolve, 1000));
    }
}

// ============================================================================
// REPORTING
// ============================================================================

function printReport() {
    const duration = CONFIG.TEST_DURATION_SECONDS;
    const tickets = CONFIG.TICKETS;
    const rps = CONFIG.REQUESTS_PER_SECOND;
    
    console.log('\n==================================');
    console.log('REALTIME VALIDATION REPORT');
    console.log('==================================');
    console.log(`Duration: ${duration}s`);
    console.log(`Tickets: ${tickets}`);
    console.log(`RPS: ${rps}`);
    console.log('');
    console.log(`Events received: ${state.totalEventsReceived}`);
    console.log(`Invalid payloads: ${state.invalidPayloadCount}`);
    console.log(`Duplicates: ${state.duplicatesCount}`);
    console.log(`Out-of-order: ${state.outOfOrderCount}`);
    console.log(`Disconnects: ${state.disconnectCount}`);
    console.log(`Reconnects: ${state.reconnectCount}`);
    console.log(`Stream errors: ${state.streamErrors}`);
    console.log('');
    console.log('Per-ticket seq summary:');
    
    const ticketList = Array.from(state.eventsPerTicket.keys()).sort((a, b) => a - b);
    for (const ticketId of ticketList) {
        const events = state.eventsPerTicket.get(ticketId);
        const maxSeq = state.maxSeqPerTicket.get(ticketId);
        console.log(`  ticket ${ticketId} → ${events} events, max seq ${maxSeq}`);
    }
    
    console.log('');
    
    const pass = state.totalEventsReceived > 0 &&
                state.duplicatesCount === 0 &&
                state.outOfOrderCount === 0;
    
    console.log('FINAL STATUS:');
    console.log(pass ? 'PASS ✓' : 'FAIL ✗');
    console.log('==================================\n');
    
    return pass ? 0 : 1;
}

// ============================================================================
// MAIN
// ============================================================================

async function main() {
    console.log('Inbiox Realtime Validation');
    console.log('------------------------');
    console.log(`Mercure URL: ${CONFIG.MERCURE_URL}`);
    console.log(`API URL: ${CONFIG.API_BASE_URL}`);
    console.log(`Tickets: ${CONFIG.TICKETS}`);
    console.log(`Duration: ${CONFIG.TEST_DURATION_SECONDS}s`);
    console.log(`RPS: ${CONFIG.REQUESTS_PER_SECOND}`);
    console.log('');
    
    process.on('SIGINT', () => {
        console.log('\n[Shutdown] Received SIGINT');
        state.shutdown = true;
        cleanup();
    });
    
    process.on('SIGTERM', () => {
        console.log('\n[Shutdown] Received SIGTERM');
        state.shutdown = true;
        cleanup();
    });
    
    console.log('[Setup] Creating SSE connections...');
    
    // Get ticket IDs to test
    const ticketIds = CONFIG.TICKET_IDS || Array.from({length: CONFIG.TICKETS}, (_, i) => i + 1);
    for (const ticketId of ticketIds) {
        createConnection(ticketId);
    }
    
    await new Promise(resolve => setTimeout(resolve, 2000));
    
    console.log('[Load] Starting traffic generator...');
    const loadPromise = trafficGenerator();
    
    console.log(`[Test] Running for ${CONFIG.TEST_DURATION_SECONDS}s...`);
    await new Promise(resolve => setTimeout(resolve, CONFIG.TEST_DURATION_SECONDS * 1000));
    
    state.shutdown = true;
    cleanup();
    
    await new Promise(resolve => setTimeout(resolve, 2000));
    
    console.log('\n[Complete] Validation finished');
    
    const exitCode = printReport();
    process.exit(exitCode);
}

main().catch(error => {
    console.error('[Fatal Error]:', error);
    process.exit(1);
});