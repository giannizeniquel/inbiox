<?php

namespace App\Application\Realtime;

/**
 * Backed enum of realtime event types for the RealtimeEventContract v1.
 * These string values are used on the wire (Mercure SSE) and must match
 * the frontend RealtimeEventType enum exactly.
 */
enum RealtimeEventType: string
{
    case TicketCreated         = 'ticket.created';
    case TicketStatusChanged   = 'ticket.status_changed';
    case TicketAssigned        = 'ticket.assigned';
    case MessageAdded          = 'message.added';
    case MessageUpdated        = 'message.updated';
    case ConversationUpdated   = 'conversation.updated';
}
