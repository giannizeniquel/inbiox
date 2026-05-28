# UI Layout Shell Specification

## Purpose

Implement the three-column responsive layout with topbar, sidebar, chat list, and conversation panel following the global layout specifications.

## Requirements

### Requirement: Three-Column Layout

The layout MUST render three columns: sidebar (220px), chat list (320px), conversation (flex-grow) at viewport ≥1200px.

- GIVEN viewport is 1200px or wider
- WHEN layout renders
- THEN three columns MUST be visible
- AND sidebar is 220px, chat list 320px, conversation flex-grow

### Requirement: Topbar

The topbar MUST be 48px tall, sticky at top, with z-index `--z-topbar` (100). It MUST have `--bg-topbar` background and 1px bottom border. Left side: hamburger (only <768px) + logo + app name. Right side: help icon + notifications icon.

- GIVEN viewport is ≥768px
- WHEN topbar renders
- THEN hamburger menu MUST be hidden

- GIVEN viewport is <768px
- WHEN topbar renders
- THEN hamburger menu MUST be visible

#### Scenario: Notifications badge

- GIVEN there are alerts
- WHEN notifications icon renders
- THEN red badge MUST appear on the icon

### Requirement: Sidebar

The sidebar MUST be 220px wide at ≥1200px, 48px (icons only) at 768-1199px, and hidden as drawer at <768px. It MUST have `--bg-sidebar` background and 1px right border. Height: `calc(100vh - 48px)`.

- GIVEN viewport is 768-1199px
- WHEN sidebar renders
- THEN it MUST be 48px wide
- AND show icons only
- AND show tooltip on hover

#### Scenario: Mobile drawer

- GIVEN viewport is <768px
- WHEN sidebar is closed
- THEN sidebar MUST be hidden

- GIVEN viewport is <768px AND user opens sidebar
- WHEN drawer opens
- THEN overlay MUST appear at z-index `--z-overlay-mobile` (190)
- AND drawer MUST slide from left at z-index `--z-drawer-mobile` (200)
- AND animation MUST be translateX(-220px)→0 over 250ms ease
- AND clicking overlay MUST close drawer

### Requirement: Sidebar Navigation Items

The sidebar MUST display navigation items: Chats, Contactos, Informes, Configuración (active). Future items with opacity 0.4 and "not-allowed" cursor.

- GIVEN a nav item is active
- WHEN rendering
- THEN it MUST show `--nav-active-bg` background
- AND 3px left border in `--accent-primary`

- GIVEN a future nav item renders
- WHEN viewed
- THEN it MUST have opacity 0.4
- AND cursor "not-allowed"
- AND show "Próximamente" tooltip on hover

#### Scenario: Nav item hover

- GIVEN user hovers over active nav item
- WHEN mouse enters
- THEN background MUST lighten to rgba(accent, 0.05)
- AND transition 150ms

#### Scenario: Nav item focus

- GIVEN user tabs to nav item
- WHEN focused
- THEN outline MUST be 2px solid `--accent-primary`

### Requirement: Sidebar Footer

The sidebar footer MUST display: user avatar (32px), "Mi account" label, and theme toggle.

- GIVEN sidebar renders
- WHEN viewed
- THEN footer MUST be at bottom
- AND show avatar + account text + theme toggle

### Requirement: Chat List Panel

The chat list panel MUST be 320px wide with `--bg-panel-list` background and 1px right border. Height: `calc(100vh - 48px)`.

- GIVEN viewport is ≥1200px
- WHEN layout renders
- THEN chat list MUST be exactly 320px

### Requirement: Conversation Panel

The conversation panel MUST fill remaining space (flex-grow) with height `calc(100vh - 48px)`. It MUST have `display: flex`, `flex-direction: column`, `min-height: 0`.

- GIVEN viewport is ≥1200px
- WHEN layout renders
- THEN conversation panel MUST grow to fill space
- AND NOT cause horizontal scroll

### Requirement: Flexbox Scroll Zones

All flex containers with internal scrolling MUST have `min-height: 0` to prevent overflow bugs.

- GIVEN a flex column has scrollable children
- WHEN container renders
- THEN it MUST have `min-height: 0`
- AND NOT rely on default stretch behavior

### Requirement: Responsive Breakpoints

The layout MUST respond to three breakpoints: <768px (single panel), 768-1199px (collapsed sidebar), ≥1200px (full three columns).

- GIVEN viewport is <768px
- WHEN layout renders
- THEN ONLY one panel shows (chat list OR conversation)
- AND sidebar is drawer

- GIVEN viewport is 768-1199px
- WHEN layout renders
- THEN sidebar shows as 48px icons
- AND three columns visible

### Requirement: Mobile Panel Switching

At <768px, tapping a chat card MUST slide the conversation panel in from right. The conversation header MUST show a back arrow to return to chat list.

- GIVEN viewport is <768px and chat list is visible
- WHEN user taps a chat
- THEN conversation MUST slide in from right (translateX 100%→0, 250ms ease)

- GIVEN viewport is <768px and conversation is visible
- WHEN user taps back arrow
- THEN conversation MUST slide out (translateX 0→100%)
- AND chat list MUST reappear

### Requirement: No Body Scroll

The html, body, and app-root MUST have height 100vh, overflow hidden, margin 0. Only internal zones MAY scroll.

- GIVEN the application loads
- WHEN rendered
- THEN body MUST NOT scroll
- AND only designated scroll zones (chat list, messages, sidebar) scroll

## ADDED Requirements

### Requirement: Z-Index Layering

All components MUST use defined z-index tokens for proper layering.

- GIVEN components overlap
- WHEN rendering
- THEN topbar MUST be above other content (z: 100)
- AND sticky panels below topbar (z: 90)
- AND mobile overlays highest (z: 190-200)