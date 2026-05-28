# Conversation UI Specification

## Purpose

Implement the conversation panel with message bubbles, delivery states, empty state, composer, and conversation header.

## Model Contracts

```typescript
type DeliveryStatus = 'sending' | 'sent' | 'read' | 'failed';

interface Message extends BaseModel {
  chatId: string;
  body: string;
  direction: 'inbound' | 'outbound';
  deliveryStatus: DeliveryStatus;
  tempId?: string;
  sentAt?: string;
  readAt?: string;
}
```

## Requirements

### Requirement: Conversation Container

The conversation panel MUST fill remaining horizontal space (flex-grow) and have `min-height: 0` to enable proper scrolling. Height MUST be `calc(100vh - 48px)` accounting for topbar.

#### Scenario: Container fills available space

- GIVEN viewport is ≥1200px
- WHEN the three-column layout renders
- THEN the conversation panel MUST grow to fill remaining width
- AND MUST NOT cause horizontal scroll

### Requirement: Conversation Header

The header MUST be 64px tall, sticky at top, with `--bg-sidebar` background and 1px bottom border. Left side MUST display: avatar (40px), contact name (semibold, `--font-lg`), assigned agent (secondary, small), connection name (secondary, small). Right side MUST display "Resolver" button.

#### Scenario: Header shows contact info

- GIVEN a chat is active
- WHEN the conversation renders
- THEN header MUST display avatar, name, assigned agent, and connection
- AND name MUST be semibold at `--font-lg`

#### Scenario: Resolve button renders correctly

- GIVEN the header renders
- WHEN viewport is light theme
- THEN resolve button MUST have `--accent-primary` background
- AND white text
- AND height 34px with border-radius 6px

- GIVEN the header renders
- WHEN viewport is dark theme
- THEN resolve button MUST have `--accent-green` background

### Requirement: Empty State

When no conversation is selected, the panel MUST display centered empty state with: logo (48px, opacity 0.25), "Seleccioná una conversación" (lg, secondary), "o iniciá una nueva con el botón +" (sm, secondary).

#### Scenario: Empty state displays correctly

- GIVEN no chat is selected (`activeChat === null`)
- WHEN the conversation panel renders
- THEN the empty state MUST be vertically centered
- AND show logo with opacity 0.25
- AND show both text messages

### Requirement: Message Skeleton

When loading messages, the panel MUST display 4 skeleton bubbles alternating left/right with varying widths and shimmer animation.

- GIVEN `loading` is true
- WHEN messages render
- THEN 4 placeholder bubbles MUST appear
- AND they MUST alternate left/right alignment
- AND have shimmer animation

### Requirement: Message Bubbles

Agent messages MUST be right-aligned with `--bg-bubble-agent` background. Client messages MUST be left-aligned with `--bg-bubble-client` background. Agent bubble border-radius: `12px 12px 2px 12px`. Client bubble border-radius: `12px 12px 12px 2px`. Max-width: 65%.

#### Scenario: Agent bubble renders correctly

- GIVEN a message with `direction: 'outbound'`
- WHEN the bubble renders
- THEN it MUST be right-aligned
- AND use `--bg-bubble-agent` background
- AND have correct border-radius

#### Scenario: Client bubble renders correctly

- GIVEN a message with `direction: 'inbound'`
- WHEN the bubble renders
- THEN it MUST be left-aligned
- AND use `--bg-bubble-client` background
- AND have correct border-radius

### Requirement: Delivery Status Icons

Each agent message MUST show delivery status icon: sending (⏳ with spin animation, `--msg-sending`), sent (✓, `--msg-sent`), read (✓✓, `--msg-read`), failed (⚠, `--msg-failed`, clickable for retry).

- GIVEN a message with `deliveryStatus: 'sending'`
- WHEN the bubble renders
- THEN hourglass icon MUST appear
- AND have spin animation

- GIVEN a message with `deliveryStatus: 'failed'`
- WHEN the bubble renders
- THEN warning icon MUST appear in `--msg-failed` color
- AND cursor MUST be pointer
- AND click MUST trigger retry

### Requirement: Message Timestamp

Messages MUST display timestamp at bottom-right in `--font-xs` size with `--text-secondary` color.

### Requirement: New Messages Indicator

When user is not at bottom and new messages arrive, a floating indicator MUST appear above the composer with text "↓ Nuevos mensajes", `--accent-primary` background, white text, `border-radius: 20px`, padding `6px 16px`.

- GIVEN `isAtBottom` is false and `hasNewMessages` is true
- WHEN new messages arrive
- THEN the indicator MUST appear
- AND be positioned above composer
- AND have z-index `--z-new-msg-indicator`

#### Scenario: Indicator click scrolls to bottom

- GIVEN the indicator is visible
- WHEN user clicks the indicator
- THEN the message area MUST scroll to bottom
- AND `hasNewMessages` MUST become false

### Requirement: Composer

The composer MUST be 56px tall, sticky at bottom, with `--bg-input` background and 1px top border. It MUST contain: emoji button, attachment button, input field (flex-grow), mic button. Input MUST have `aria-label="Escribe un mensaje"`.

#### Scenario: Composer sticks to bottom

- GIVEN messages have scrolled
- WHEN new messages are received
- THEN the composer MUST remain fixed at bottom
- AND NOT move from viewport

#### Scenario: Composer input is accessible

- GIVEN the composer renders
- WHEN screen reader focuses the input
- THEN it MUST announce "Escribe un mensaje"

### Requirement: Banner Warning (Optional)

A warning banner MAY appear above the composer with light theme: `#fef3c7` background, `#92400e` text; dark theme: `rgba(251,191,36,0.10)` background, `#fbbf24` text.

- GIVEN a contextual warning exists
- WHEN conversation renders
- THEN the banner MAY appear above composer
- AND display warning icon and message

### Requirement: System Messages

System messages MUST be centered with inline chip style: `border-radius: 12px`, padding `4px 12px`, background `rgba(0,0,0,0.06)` (light) or `rgba(255,255,255,0.06)` (dark), text at `--font-xs` italic with `--text-secondary` color.

## ADDED Requirements

### Requirement: Message Gap

Messages from the same sender MUST have 4px gap. Messages from different senders MUST have 12px gap.

- GIVEN consecutive messages from the same sender
- WHEN rendering
- THEN gap between them MUST be 4px

- GIVEN consecutive messages from different senders
- WHEN rendering
- THEN gap between them MUST be 12px

### Requirement: Background Wallpaper

The message area MUST have `--bg-chat` background with SVG wallpaper at 3% opacity.

- GIVEN the message area renders
- WHEN viewed
- THEN background MUST be `--bg-chat`
- AND wallpaper SVG MUST be visible at 3% opacity