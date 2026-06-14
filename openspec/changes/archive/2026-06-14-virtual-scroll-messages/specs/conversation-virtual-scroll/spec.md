# Conversation Virtual Scroll Specification

## Purpose

Message list integration with variable-height virtual scroll — bottom-anchored growth, auto-scroll on new messages, polling compatibility, and 200-message cap.

## Requirements

### Requirement: Message List Virtual Scroll

The conversation panel MUST render messages using VirtualScrollViewport in `mode='variable'`, supporting up to 200 messages with bottom-anchored auto-scroll behavior.

#### Scenario: Initial load scrolls to bottom

- GIVEN messages are loaded for the first time in a conversation
- WHEN the viewport renders
- THEN it MUST scroll to the bottom showing the latest message
- AND `isAtBottom` tracking MUST be set to true

#### Scenario: New message auto-scroll when at bottom

- GIVEN the user is at the bottom (`isAtBottom = true`)
- WHEN a new message arrives via polling or realtime
- THEN the viewport MUST auto-scroll to show the new message
- AND `isAtBottom` MUST remain true

#### Scenario: Scroll position preserved when not at bottom

- GIVEN the user has scrolled up to read older messages (`isAtBottom = false`)
- WHEN a polling refresh adds new messages
- THEN the viewport MUST NOT change the user's scroll position
- AND the user's currently visible messages MUST remain in view

#### Scenario: Polling compatibility

- GIVEN ConversationStore polls every 3 seconds
- WHEN a refresh occurs with updated messages
- THEN the viewport MUST re-derive visible range without layout shift
- AND scroll position MUST remain stable regardless of `isAtBottom` state

#### Scenario: Maximum message cap

- GIVEN the message list has reached 200 messages
- WHEN older messages would exceed the cap
- THEN the viewport MUST maintain the 200-message cap
- AND scroll position MUST adjust to preserve the user's visible context

#### Scenario: Scroll to bottom command

- GIVEN the user triggers a scroll-to-bottom action
- WHEN the viewport receives the command
- THEN it MUST set `scrollTop = scrollHeight` to show the latest message
- AND `isAtBottom` MUST become true
