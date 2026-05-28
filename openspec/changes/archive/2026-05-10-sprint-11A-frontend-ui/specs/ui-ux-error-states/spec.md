# UI/UX Error States Specification

## Purpose

Implement per-zone error states with retry actions for inbox, conversation, SSE connection, and message failures.

## Requirements

### Requirement: Inbox Error State

When inbox (chat list) fails to load, it MUST display: warning icon (⚠), "No se pudo cargar la bandeja" message, and "Reintentar" button. Error state MUST NOT break the layout.

- GIVEN inbox loading fails
- WHEN rendering error state
- THEN icon ⚠ MUST appear
- AND message "No se pudo cargar la bandeja" MUST show
- AND "Reintentar" button MUST be displayed

#### Scenario: Retry loads inbox

- GIVEN error state shows
- WHEN user clicks "Reintentar"
- THEN inbox MUST attempt to reload
- AND loading state MUST show again
- AND on success, chat list MUST render normally

### Requirement: Conversation Error State

When conversation fails to load, it MUST display: warning icon (⚠), "No se pudo cargar la conversación" message, and "Reintentar" button.

- GIVEN conversation loading fails
- WHEN rendering error state
- THEN icon ⚠ MUST appear
- AND message "No se pudo cargar la conversación" MUST show
- AND "Reintentar" button MUST be displayed

#### Scenario: Retry loads conversation

- GIVEN error state shows
- WHEN user clicks "Reintentar"
- THEN conversation MUST attempt to reload
- AND on success, messages MUST render normally

### Requirement: SSE Offline Banner

When SSE disconnects, a banner MUST display "Sin conexión en tiempo real" in the topbar or sidebar with connection status. The banner MUST show reconnecting status during automatic reconnection attempts.

- GIVEN SSE disconnects
- WHEN status becomes 'offline'
- THEN banner MUST appear
- AND show "Sin conexión en tiempo real"

- GIVEN reconnecting begins
- WHEN status becomes 'reconnecting'
- THEN banner MUST show reconnection status
- AND NOT block the UI

### Requirement: Failed Message Display

Failed messages MUST show ⚠ icon in the bubble with `--msg-failed` color. The message MUST remain visible and NOT be deleted. The bubble MUST have `title="Error al enviar. Toca para reintentar."`

- GIVEN a message has deliveryStatus: 'failed'
- WHEN rendering
- THEN warning icon MUST appear in bubble
- AND color MUST be `--msg-failed`
- AND cursor MUST be pointer

#### Scenario: Tap to retry

- GIVEN a failed message is tapped
- WHEN click occurs
- THEN retry action MUST trigger
- AND message status becomes 'sending'

### Requirement: Non-Blocking Errors

All error states MUST be non-blocking. The rest of the application MUST remain functional. No modal dialogs for recoverable errors.

- GIVEN an error occurs in chat list
- WHEN viewing
- THEN conversation panel MUST still work
- AND sidebar MUST still work

### Requirement: Error Message Language

All error messages MUST be in Spanish.

- GIVEN an error renders
- WHEN viewed
- THEN text MUST be in Spanish
- AND match specified messages exactly

## ADDED Requirements

### Requirement: Error Styling

Error icons MUST use `--msg-failed` color (red). Buttons MUST use primary accent color.

- GIVEN error state renders
- WHEN viewed
- THEN warning icon MUST be red
- AND retry button MUST use `--accent-primary`

### Requirement: Error State Layout

Error states MUST be centered within their respective zones.

- GIVEN inbox has error
- WHEN rendering
- THEN error content MUST be vertically centered

- GIVEN conversation has error
- WHEN rendering
- THEN error content MUST be vertically centered

### Requirement: Loading Transitions

After retry, loading state MUST display before returning to content or error.

- GIVEN user clicks retry
- WHEN loading begins
- THEN loading indicator/spinner MUST show
- AND previous error message MUST disappear