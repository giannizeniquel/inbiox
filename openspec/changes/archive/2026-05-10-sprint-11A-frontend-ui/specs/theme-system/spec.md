# Theme System Specification

## Purpose

Implement light/dark theme toggle with CSS custom properties, localStorage persistence, and smooth 200ms transitions.

## Requirements

### Requirement: CSS Custom Properties

The application MUST define all CSS custom properties (tokens) in `:root` for light theme and `:root.dark` for dark theme as specified in design spec Section 12.

#### Scenario: Light theme tokens are defined

- GIVEN the application loads
- WHEN light theme is active
- THEN all tokens MUST be defined in `:root`
- INCLUDING: --bg-app, --bg-sidebar, --bg-panel-list, --bg-chat, --bg-card, --bg-card-active, --bg-topbar, --bg-input, --bg-bubble-agent, --bg-bubble-client, --text-primary, --text-secondary, --text-on-accent, --border-subtle, --accent-primary, --accent-green, --badge-bg, --nav-active-bg, --tab-active-bg, --sla-ok, --sla-warn, --sla-breach, --status-new, --status-active, --status-waiting, --status-resolved, --status-closed, --conn-online, --conn-reconnect, --conn-offline, --msg-sending, --msg-sent, --msg-read, --msg-failed

#### Scenario: Dark theme tokens are defined

- GIVEN the application loads
- WHEN dark theme is active
- THEN all tokens MUST be defined in `:root.dark`
- AND override all light theme values

### Requirement: ThemeStore Signal

The application MUST provide a ThemeStore signal with `theme: signal<'light'|'dark'>` that manages the current theme state.

- GIVEN ThemeStore exists
- WHEN the application initializes
- THEN `theme` signal MUST be available
- AND reflect current theme state

### Requirement: localStorage Persistence

The theme MUST persist across page reloads using localStorage key "crm-theme".

- GIVEN user toggles to dark theme
- WHEN the page reloads
- THEN dark theme MUST be restored
- AND localStorage MUST contain "crm-theme": "dark"

- GIVEN localStorage has "crm-theme": "dark"
- WHEN application initializes
- THEN theme signal MUST default to "dark"

#### Scenario: First visit defaults to light

- GIVEN user visits for the first time
- WHEN application loads
- THEN localStorage has no "crm-theme"
- AND theme MUST default to "light"

### Requirement: Theme Toggle Transition

When theme changes, the transition MUST be controlled at 200ms with specific properties transitioning (background-color, color, border-color).

#### Scenario: Theme transition is smooth

- GIVEN theme is "light"
- WHEN user toggles to "dark"
- THEN transition animation MUST occur over 200ms
- AND ONLY background-color, color, border-color properties SHOULD transition
- AND other properties MUST NOT transition

#### Scenario: Theme transition implementation

- GIVEN the code executes theme toggle
- WHEN changing theme class
- THEN the implementation MUST:
  1. Add "theme-transition" class to html element
  2. Toggle "dark" class on html element
  3. Set localStorage with new theme
  4. setTimeout(220ms) to remove "theme-transition" class

### Requirement: Theme Toggle in Sidebar

The sidebar footer MUST display a theme toggle with sun/moon icon, label, and pill-style switch. ON state: #25d366, OFF state: #d1d5db.

- GIVEN the sidebar renders
- WHEN viewed
- THEN toggle MUST show sun (☀️) for light, moon (🌙) for dark
- AND label text
- AND switch pill

#### Scenario: Toggle changes theme

- GIVEN toggle is in OFF position (light theme)
- WHEN user clicks toggle
- THEN theme MUST change to "dark"
- AND toggle moves to ON position (#25d366)

### Requirement: CSS Variable Application

All components MUST use CSS variables for colors, NOT hardcoded values.

- GIVEN a component uses background color
- WHEN writing styles
- THEN it MUST use var(--bg-app) or similar variable
- AND NOT use hex values directly

## ADDED Requirements

### Requirement: Typography Tokens

The theme MUST define typography tokens: --font-xs (11px), --font-sm (12px), --font-md (13px), --font-base (14px), --font-lg (15px).

- GIVEN theme loads
- WHEN typography is applied
- THEN all font-size values MUST use tokens
- AND font-family MUST be "Inter, system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif"

### Requirement: Z-Index Tokens

The theme MUST define z-index tokens: --z-topbar (100), --z-sticky-panels (90), --z-fab (80), --z-new-msg-indicator (85), --z-overlay-mobile (190), --z-drawer-mobile (200), --z-tooltips (300), --z-notifications (400).

- GIVEN components need z-index
- WHEN positioning
- THEN they MUST use z-index tokens
- AND NOT hardcoded values