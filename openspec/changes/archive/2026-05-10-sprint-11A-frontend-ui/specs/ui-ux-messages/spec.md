# UI/UX Messages Specification

## Purpose

Implement optimistic UI for outgoing messages with delivery states, retry on failure, auto-scroll, and new messages indicator.

## Requirements

### Requirement: Optimistic Message Sending

When user sends a message, it MUST appear IMMEDIATELY with `deliveryStatus: 'sending'` and a generated `tempId` (crypto.randomUUID()).

- GIVEN user types and sends a message
- WHEN the send action triggers
- THEN the message MUST appear in the conversation immediately
- AND have `deliveryStatus: 'sending'`
- AND have a generated `tempId`
- AND NOT wait for server confirmation

#### Scenario: Optimistic message renders

- GIVEN message has `deliveryStatus: 'sending'`
- WHEN bubble renders
- THEN hourglass icon MUST show
- AND spin animation MUST play
- AND message is visible in the list

### Requirement: Message Confirmation

When SSE or REST confirms the message, the optimistic message MUST be replaced by the real message using `tempId` as the key. The `deliveryStatus` MUST update to 'sent'.

- GIVEN an optimistic message with tempId exists
- WHEN server confirmation arrives
- THEN the optimistic message MUST be replaced by confirmed message
- AND tempId is removed
- AND deliveryStatus becomes 'sent'

### Requirement: Read Confirmation

When the server indicates the message was read, the `deliveryStatus` MUST update to 'read'.

- GIVEN a sent message exists
- WHEN read confirmation arrives
- THEN deliveryStatus MUST change to 'read'
- AND double checkmark icon MUST show (✓✓)

### Requirement: Failed Message Retry

If sending fails, the message MUST remain visible with `deliveryStatus: 'failed'`. The bubble MUST show ⚠ icon, have red color (`--msg-failed`), and be clickable with title "Error al enviar. Toca para reintentar."

- GIVEN message sending fails
- WHEN the failure is detected
- THEN the message MUST remain visible
- AND deliveryStatus MUST be 'failed'
- AND warning icon MUST appear
- AND clicking MUST trigger retry

#### Scenario: Retry failed message

- GIVEN a message has deliveryStatus: 'failed'
- WHEN user taps the message
- THEN the message MUST attempt to resend
- AND status MUST become 'sending' again

### Requirement: Message Memory Cap

The ConversationStore MUST NOT exceed 200 messages in memory per conversation.

- GIVEN a conversation has 200 messages loaded
- WHEN a new message arrives
- THEN the oldest message MUST be removed
- AND total remains at 200

### Requirement: Auto-Scroll Policy

The UIStore MUST track `isAtBottom` via formula: `scrollTop + clientHeight >= scrollHeight - 40px`. When user is at bottom, new messages MUST auto-scroll smoothly. When user is reading history (not at bottom), NO auto-scroll MUST occur.

- GIVEN user is at bottom (isAtBottom: true)
- WHEN a new message arrives
- THEN the message area MUST scroll smoothly to bottom

- GIVEN user is reading history (isAtBottom: false)
- WHEN a new message arrives
- THEN NO auto-scroll MUST occur
- AND new messages indicator MUST appear

### Requirement: New Messages Indicator

When `isAtBottom: false` and new messages arrive, a floating indicator MUST show "↓ Nuevos mensajes" above the composer. Clicking it MUST scroll to bottom and hide the indicator.

- GIVEN isAtBottom is false and new messages arrive
- WHEN rendering
- THEN indicator MUST appear
- AND be positioned above composer

- GIVEN indicator is visible
- WHEN user clicks indicator
- THEN messages MUST scroll to bottom
- AND indicator MUST hide (hasNewMessages: false)

### Requirement: Message Timestamps

All messages MUST display timestamp in `--font-xs` size with `--text-secondary` color at bottom-right of bubble.

### Requirement: Message Direction Styling

Outbound messages (agent) MUST be right-aligned with agent bubble styling. Inbound messages (client) MUST be left-aligned with client bubble styling.

- GIVEN message direction is 'outbound'
- WHEN rendering
- THEN bubble MUST be right-aligned
- AND use agent bubble colors and border-radius

- GIVEN message direction is 'inbound'
- WHEN rendering
- THEN bubble MUST be left-aligned
- AND use client bubble colors and border-radius

## ADDED Requirements

### Requirement: Message Gap

Messages from the same sender MUST have 4px vertical gap. Messages from different senders MUST have 12px vertical gap.

- GIVEN consecutive messages from same sender
- WHEN rendering
- THEN gap MUST be 4px

- GIVEN consecutive messages from different senders
- WHEN rendering
- THEN gap MUST be 12px

### Requirement: System Messages

System messages (FSM transitions, etc.) MUST be centered with inline chip styling.

- GIVEN a system message exists
- WHEN rendering
- THEN it MUST be centered
- AND have chip styling (border-radius 12px, italic text)
- AND use `--text-secondary` color