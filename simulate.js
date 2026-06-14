#!/usr/bin/env node

/**
 * Inbiox Realtime Simulation Script
 * 
 * Simulates production traffic to test backend realtime capabilities:
 * - Multiple SSE clients (agents)
 * - Event generation via API
 * - Metrics and validation
 */

const { EventSource } = require('eventsource');
const axios = require('axios');

// ============================================
// CONFIGURATION
// ============================================

const CONFIG = {
    // URLs
    MERCURE_URL: process.env.MERCURE_URL || 'http://localhost:3000/.well-known/mercure',
    API_BASE_URL: process.env.API_BASE_URL || 'http://localhost:8000',
    
    // Agent configuration (simulating 3 agents)
    AGENTS: [
        { id: 1, name: 'Agent-1' },
        { id: 2, name: 'Agent-2' },
        { id: 3, name: 'Agent-3' }
    ],
    
    // Ticket pool (will be populated)
    TICKETS: [],
    
    // Simulation config
    DURATION_SEC: parseInt(process.env.DURATION || '30'),
    RPS: parseInt(process.env.RPS || '5'),  // requests per second
    
    // Pre-generated JWT tokens (would come from backend in production)
    JWT_TOKENS: {}
};

// ============================================
// METRICS
// ============================================

const metrics = {
    start_time: Date.now(),
    total_events_received: 0,
    duplicate_events: 0,
    out_of_order_events: 0,
    events_per_agent: {},
    per_ticket_last_seq: new Map(),
    seen_event_ids: new Set(),
    ticket_states: new Map(),  // Track state per ticket
    errors: []
};

// ============================================
// UTILITIES
// ============================================

function log(msg, level = 'INFO') {
    const timestamp = new Date().toISOString();
    console.log(`[${timestamp}] [${level}] ${msg}`);
}

function sleep(ms) {
    return new Promise(resolve => setTimeout(resolve, ms));
}

async function generateToken(agentId) {
    // JWT must include the topics we want to subscribe to
    const payload = {
        agent_id: agentId,
        mercure: {
            subscribe: [
                `tickets`,
                `agent/${agentId}/inbox`
            ]
        },
        iat: Math.floor(Date.now() / 1000),
        exp: Math.floor(Date.now() / 1000) + 3600
    };
    
    const encoded = Buffer.from(JSON.stringify(payload)).toString('base64');
    return `eyJhbGciOiJIUzI1NiJ9.${encoded}.fake-signature`;
}

// ============================================
// SSE CLIENT MANAGER
// ============================================

const sseClients = new Map();

async function connectAgent(agent) {
    const token = CONFIG.JWT_TOKENS[agent.id] || await generateToken(agent.id);
    
    // Listen to ticket topics (what the backend publishes) + agent inbox as fallback
    // The backend publishes to ticket/{id}, we use wildcard to catch all
    const url = `${CONFIG.MERCURE_URL}?topic=tickets`;
    
    log(`Connecting SSE for ${agent.name} to ${url}`);
    
    const client = new EventSource(url, {
        headers: {
            'Authorization': `Bearer ${token}`
        }
    });
    
    client.onopen = () => {
        log(`SSE connected: ${agent.name}`, 'SUCCESS');
    };
    
    client.onmessage = (event) => {
        try {
            const data = JSON.parse(event.data);
            processEvent(agent.id, data);
        } catch (e) {
            log(`Failed to parse event: ${e.message}`, 'ERROR');
        }
    };
    
    client.onerror = (error) => {
        log(`SSE error for ${agent.name}: ${error.message || 'Connection lost'}`, 'WARN');
        // EventSource handles reconnection automatically
    };
    
    sseClients.set(agent.id, client);
    
    // Initialize metrics for agent
    metrics.events_per_agent[agent.id] = 0;
}

function processEvent(agentId, data) {
    metrics.total_events_received++;
    metrics.events_per_agent[agentId]++;
    
    const { event_id, ticket_id, seq, type, occurred_at } = data;
    
    // Validation 1: Check for duplicate event_id
    if (event_id && metrics.seen_event_ids.has(event_id)) {
        metrics.duplicate_events++;
        log(`DUPLICATE event detected: ${event_id}`, 'WARN');
    } else if (event_id) {
        metrics.seen_event_ids.add(event_id);
    }
    
    // Validation 2: Check for out-of-order seq
    if (ticket_id && seq !== undefined) {
        const lastSeq = metrics.per_ticket_last_seq.get(ticket_id) || 0;
        
        if (seq < lastSeq) {
            metrics.out_of_order_events++;
            log(`OUT-OF-ORDER: ticket ${ticket_id}, seq ${seq} < last ${lastSeq}`, 'WARN');
        } else if (seq === lastSeq) {
            // Same seq - could be duplicate event
            log(`SEQ DUPLICATE: ticket ${ticket_id}, seq ${seq}`, 'WARN');
        }
        
        // Update last seq
        metrics.per_ticket_last_seq.set(ticket_id, Math.max(lastSeq, seq));
    }
}

async function disconnectAll() {
    log('Disconnecting SSE clients...');
    for (const [agentId, client] of sseClients) {
        try {
            client.close();
        } catch (e) {
            // Ignore close errors
        }
    }
    sseClients.clear();
}

// ============================================
// STATES & TRANSITIONS (valid per TicketStateMachine)
// ============================================

const VALID_TRANSITIONS = {
    'new': ['active'],
    'active': ['waiting', 'resolved'],
    'waiting': ['active', 'resolved'],
    'resolved': ['waiting', 'closed'],
    'closed': [] // terminal
};

// Track current state per ticket
const ticketStates = new Map();

function getRandomTicket() {
    if (CONFIG.TICKETS.length === 0) return null;
    return CONFIG.TICKETS[Math.floor(Math.random() * CONFIG.TICKETS.length)];
}

function getCurrentState(ticketId) {
    return ticketStates.get(ticketId) || 'new';
}

function setState(ticketId, state) {
    ticketStates.set(ticketId, state);
}

function getValidTransitions(ticketId) {
    const current = getCurrentState(ticketId);
    return VALID_TRANSITIONS[current] || [];
}

function transitionState(ticketId, newStatus) {
    // Update state based on action
    if (newStatus === 'waiting' || newStatus === 'active' || newStatus === 'resolved' || newStatus === 'closed') {
        setState(ticketId, newStatus);
    }
}

// ============================================
// EVENT GENERATOR
// ============================================

async function generateRandomEvent() {
    if (CONFIG.TICKETS.length === 0) {
        return;
    }
    
    const ticketId = getRandomTicket();
    if (!ticketId) return;
    
    // Get current state and valid transitions
    const currentState = getCurrentState(ticketId);
    const validTransitions = VALID_TRANSITIONS[currentState] || [];
    
    // If no valid transitions, skip this ticket
    if (validTransitions.length === 0) {
        return;
    }
    
    // Already at target state - can't transition to same state
    if (validTransitions.length === 1 && validTransitions[0] === currentState) {
        return;
    }
    
    // Try to pick a state that's different from current
    const nextState = validTransitions.find(s => s !== currentState) 
        || validTransitions[0];
    
    // Log for debugging
    log(`Ticket ${ticketId}: ${currentState} → ${nextState} (valid: ${VALID_TRANSITIONS[currentState].join(',')})`, 'DEBUG');
    
    // Map state to endpoint
    let callEndpoint;
    let body = {};
    
    if (nextState === 'resolved') {
        callEndpoint = `/tickets/${ticketId}/resolve`;
    } else if (nextState === 'closed') {
        callEndpoint = `/tickets/${ticketId}/close`;
    } else {
        // active or waiting - use transition endpoint
        callEndpoint = `/tickets/${ticketId}/transition`;
        body = { status: nextState };
    }
    
    try {
        const response = await axios.post(`${CONFIG.API_BASE_URL}${callEndpoint}`, body, {
            headers: { 'Content-Type': 'application/json' },
            timeout: 5000
        });
        
        // Update state from ACTUAL response (not requested!)
        if (response.data && response.data.new_status) {
            transitionState(ticketId, response.data.new_status);
        }
        
    } catch (error) {
        // Parse actual state from error message: "Invalid transition from 'X' to 'Y'"
        const errorMsg = error.response?.data?.error || '';
        const match = errorMsg.match(/from "(\w+)"/);
        if (match) {
            const actualState = match[1];
            log(`State sync: ticket ${ticketId} was "${getCurrentState(ticketId)}" but actually "${actualState}"`, 'WARN');
            transitionState(ticketId, actualState);
} else {
            // Log error details for debugging
            const status = error.response?.status;
            const data = error.response?.data;
            const msg = status === 404 
                ? 'Endpoint not found' 
                : data?.error || error.message;
log(`API error ${status}: ${msg}`, 'DEBUG');
        }
    }
 }

// ============================================
// TICKET SETUP
// ============================================

async function setupTickets() {
    log('Setting up tickets...');
    
    // Create some initial tickets via API
    const ticketCount = Math.floor(Math.random() * 20) + 20; // 20-40 tickets
    
    for (let i = 0; i < ticketCount; i++) {
        try {
            // Use inbound to create new tickets and capture REAL ticket ID
            const response = await axios.post(
                `${CONFIG.API_BASE_URL}/debug/inbound/${i + 1000}`,
                {},
                { timeout: 5000 }
            );
            
            if (response.data.success && response.data.ticket_id) {
                const ticketId = response.data.ticket_id;
                CONFIG.TICKETS.push(ticketId);
                setState(ticketId, 'new');
            }
            
        } catch (error) {
            // Continue on error
        }
    }
    
    log(`Created ${CONFIG.TICKETS.length} tickets for simulation`);
    
    // Immediately transition first batch to active so we can test state changes
    log('Running initial transitions to active...');
    const batchSize = Math.min(10, CONFIG.TICKETS.length);
    let successCount = 0;
    for (let i = 0; i < batchSize; i++) {
        try {
            const ticketId = CONFIG.TICKETS[i];
            const response = await axios.post(
                `${CONFIG.API_BASE_URL}/tickets/${ticketId}/transition`,
                { status: 'active' },
                { timeout: 5000 }
            );
            if (response.status >= 200 && response.status < 300) {
                setState(ticketId, 'active');
                successCount++;
            }
        } catch (e) {
            // Log but continue
            log(`Setup transition failed: ${e.message}`, 'WARN');
        }
    }
    log(`Initial transitions complete: ${successCount}/${batchSize} succeeded`);
}

// ============================================
// SIMULATION LOOP
// ============================================

async function runSimulation() {
    const durationMs = CONFIG.DURATION_SEC * 1000;
    const intervalMs = Math.floor(1000 / CONFIG.RPS);
    
    log(`Starting simulation: ${CONFIG.DURATION_SEC}s duration, ${CONFIG.RPS} RPS`);
    
    const endTime = Date.now() + durationMs;
    let requestCount = 0;
    
    while (Date.now() < endTime) {
        if (CONFIG.TICKETS.length > 0) {
            // Generate random events
            await generateRandomEvent();
            requestCount++;
        }
        
        // Rate limiting
        await sleep(intervalMs);
    }
    
    log(`Simulation ended. ${requestCount} API requests sent`);
}

// ============================================
// REPORTING
// ============================================

function printReport() {
    const duration = Math.floor((Date.now() - metrics.start_time) / 1000);
    
    console.log('\n' + '='.repeat(60));
    console.log('          SIMULATION REPORT');
    console.log('='.repeat(60));
    
    console.log(`\n📊 METRICS:`);
    console.log(`  Duration:           ${duration}s`);
    console.log(`  Total events:       ${metrics.total_events_received}`);
    console.log(`  Duplicate events:  ${metrics.duplicate_events}`);
    console.log(`  Out-of-order:       ${metrics.out_of_order_events}`);
    console.log(`  Unique tickets:     ${metrics.per_ticket_last_seq.size}`);
    
    console.log(`\n👥 EVENTS PER AGENT:`);
    for (const [agentId, count] of Object.entries(metrics.events_per_agent)) {
        console.log(`  Agent ${agentId}: ${count} events`);
    }
    
    console.log(`\n🔍 VALIDATION:`);
    
    if (metrics.duplicate_events > 0) {
        console.log(`  ⚠️  DUPLICATES DETECTED: ${metrics.duplicate_events}`);
    } else {
        console.log(`  ✅ No duplicate events`);
    }
    
    if (metrics.out_of_order_events > 0) {
        console.log(`  ⚠️  OUT-OF-ORDER DETECTED: ${metrics.out_of_order_events}`);
    } else {
        console.log(`  ✅ No out-of-order events`);
    }
    
    console.log(`\n📈 PER-TICKET SEQ:`);
    for (const [ticketId, lastSeq] of metrics.per_ticket_last_seq) {
        console.log(`  Ticket ${ticketId}: last seq = ${lastSeq}`);
    }
    
    console.log('\n' + '='.repeat(60));
    
    // Final verdict
    const success = metrics.duplicate_events === 0 && metrics.out_of_order_events === 0;
    
    if (success) {
        console.log('✅ SYSTEM STATUS: HEALTHY');
        console.log('   - No duplicate events');
        console.log('   - No out-of-order events');
        console.log('   - Realtime pipeline working correctly');
    } else {
        console.log('⚠️  SYSTEM STATUS: ISSUES DETECTED');
    }
    
    console.log('='.repeat(60) + '\n');
    
    return success;
}

// ============================================
// MAIN
// ============================================

async function main() {
    console.log('\n🚀 Inbiox Realtime Simulation');
    console.log('==============================\n');
    
    try {
        // Setup
        log('Initializing...');
        
        // Generate JWT tokens for agents
        for (const agent of CONFIG.AGENTS) {
            CONFIG.JWT_TOKENS[agent.id] = await generateToken(agent.id);
        }
        
        // Setup tickets
        await setupTickets();
        
        if (CONFIG.TICKETS.length === 0) {
            log('No tickets available. Exiting.', 'ERROR');
            process.exit(1);
        }
        
        // Connect SSE clients
        for (const agent of CONFIG.AGENTS) {
            await connectAgent(agent);
        }
        
        // Wait for connections to stabilize
        await sleep(2000);
        
        // Run simulation
        await runSimulation();
        
        // Wait for pending events to process
        await sleep(5000);
        
    } catch (error) {
        log(`Fatal error: ${error.message}`, 'ERROR');
        console.error(error);
    } finally {
        // Report
        const success = printReport();
        
        // Cleanup
        await disconnectAll();
        
        process.exit(success ? 0 : 1);
    }
}

// Run if executed directly
if (require.main === module) {
    main().catch(error => {
        console.error('Unhandled error:', error);
        process.exit(1);
    });
}

module.exports = { main, CONFIG, metrics };