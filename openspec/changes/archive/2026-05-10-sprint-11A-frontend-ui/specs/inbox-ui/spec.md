# Inbox UI Specification

## Purpose

Implement the chat list (inbox) component with virtual scroll, filtering, and visual indicators for SLA, FSM status, and automation badges.

## Model Contracts

```typescript
interface BaseModel {
  id: string;
  createdAt: string;
  updatedAt?: string;
}

type SlaStatus = 'ok' | 'warn' | 'breach';
type FsmStatus = 'new' | 'active' | 'waiting' | 'resolved' | 'closed';

interface Chat extends BaseModel {
  contactName: string;
  contactPhone: string;
  avatarUrl?: string;
  unreadCount: number;
  slaStatus: SlaStatus;
  fsmStatus: FsmStatus;
  isAutomated: boolean;
  lastMessage: string;
  lastMessageAt: string;
  connectionName: string;
  assignedAgent?: string;
}
```

## Requirements

### Requirement: Chat List Container

The chat list container MUST be exactly 320px wide with background `--bg-panel-list`. It MUST have `display: flex`, `flex-direction: column`, and `min-height: 0` to enable proper scrolling within flexbox.

#### Scenario: Container renders at correct width

- GIVEN the application viewport is ≥1200px wide
- WHEN the layout renders the three-column structure
- THEN the chat list column MUST be 320px wide
- AND the container MUST have `min-height: 0` to prevent overflow issues

#### Scenario: Container handles overflow correctly

- GIVEN there are more than the visible number of chats
- WHEN the chat list renders
- THEN the virtual scroll area MUST handle scrolling internally
- AND the outer container MUST NOT scroll

### Requirement: Search Input

The search input MUST be sticky at the top of the chat list with placeholder text "Nombre, número o email". It MUST use `--bg-input` background and `--font-base` font size.

#### Scenario: Search is visible and sticky

- GIVEN the chat list has scrolled down
- WHEN the user scrolls the list
- THEN the search input MUST remain fixed at the top
- AND maintain `z-index: var(--z-sticky-panels)`

#### Scenario: Search input has correct styling

- GIVEN the user focuses on the search input
- WHEN the input receives focus
- THEN it MUST show an outline of 2px solid `--accent-primary`
- AND have border-radius of 8px

### Requirement: Filter Tabs

The filter tabs MUST display three options: "Míos", "En espera", "Interno" with active state using `--tab-active-bg` and `--accent-primary`. Active tab MUST have `border-radius: 20px`.

#### Scenario: Tab displays badge count

- GIVEN the filter tab has associated count
- WHEN the tab renders
- THEN it MUST display a badge with `--badge-bg` background and white text
- AND use `--font-xs` size

#### Scenario: User switches tabs

- GIVEN the user taps on "En espera" tab
- WHEN the tab becomes active
- THEN the tab MUST show `--tab-active-bg` background
- AND `--accent-primary` text color
- AND border-radius of 20px

### Requirement: Virtual Scroll

The chat list MUST use CDK Virtual Scroll with fixed `itemSize: 64px`. The component MUST use `trackBy: chat.id` for efficient rendering.

#### Scenario: Virtual scroll renders efficiently

- GIVEN there are 100+ chats
- WHEN the chat list renders
- THEN ONLY the visible items plus buffer MUST be rendered
- AND scrolling MUST be smooth without jank

#### Scenario: Chat item has correct height

- GIVEN a chat card is rendered
- WHEN measuring the element
- THEN it MUST be exactly 64px tall

### Requirement: Chat Card Layout

Each chat card MUST display: SLA dot (8px), avatar (36px), name, timestamp, FSM chip, BOT badge (if automated), unread badge, and message preview. Unread chats MUST show name at weight 700 and preview at weight 600. Read chats MUST show name at weight 500 and preview at weight 400.

#### Scenario: SLA dot displays correct status

- GIVEN a chat has `slaStatus: 'warn'`
- WHEN the card renders
- THEN a red dot (8px) MUST appear on the left side
- AND the dot color MUST be `--sla-warn`
- AND it SHOULD have a tooltip showing "SLA: X min" or "SLA vencido"

#### Scenario: BOT badge appears for automated chats

- GIVEN a chat has `isAutomated: true`
- WHEN the card renders
- THEN a "BOT" badge MUST appear at top-right of avatar
- AND use `--accent-green` background with white text
- AND have border-radius of 3px

#### Scenario: FSM chip shows correct status

- GIVEN a chat has `fsmStatus: 'waiting'`
- WHEN the card renders
- THEN a chip MUST display "waiting" text
- AND use `--status-waiting` background color
- AND use `--font-xs` size with white text

#### Scenario: Unread hierarchy styling

- GIVEN a chat has `unreadCount: 3`
- WHEN the card renders
- THEN the contact name MUST have font-weight 700
- AND the message preview MUST have font-weight 600
- AND the timestamp MUST use `--accent-primary` color

#### Scenario: Read chat styling

- GIVEN a chat has `unreadCount: 0`
- WHEN the card renders
- THEN the contact name MUST have font-weight 500
- AND the message preview MUST have font-weight 400
- AND the timestamp MUST use `--text-secondary` color

### Requirement: Skeleton Loading

When loading, the chat list MUST display 5 skeleton cards with shimmer animation. Each skeleton MUST have a 36px circular avatar and two lines of text.

#### Scenario: Skeleton shows during initial load

- GIVEN `loading` signal is `true`
- WHEN the chat list renders
- THEN 5 placeholder cards MUST be displayed
- AND each MUST have shimmer animation using linear-gradient
- AND animation duration of 1.5s infinite

### Requirement: Keyboard Navigation

The chat list MUST support keyboard navigation with Up/Down arrows and Enter to open a chat. Each card MUST have correct `tabIndex`.

#### Scenario: Keyboard navigates between cards

- GIVEN the first chat card has focus
- WHEN user presses Down arrow
- THEN the focus MUST move to the second chat card
- AND show outline 2px solid `--accent-primary`

#### Scenario: Enter opens chat

- GIVEN a chat card has focus
- WHEN user presses Enter
- THEN the chat MUST become active
- AND the conversation panel MUST display the chat

### Requirement: Active Chat State

The active chat MUST show `--bg-card-active` background and a 3px left border with `--accent-primary` color.

#### Scenario: Active chat is highlighted

- GIVEN a chat is selected as `activeChat`
- WHEN the card renders
- THEN background MUST be `--bg-card-active`
- AND left border MUST be 3px solid `--accent-primary`

## ADDED Requirements

### Requirement: FAB New Conversation Button

The chat list MUST display a floating action button (FAB) at bottom-right for creating new conversations. The button MUST be 48px diameter, use `--accent-primary` background, white icon, and have shadow.

- GIVEN the chat list renders
- WHEN the user is on desktop viewport
- THEN a FAB button MUST appear at `bottom: 20px, right: 16px`
- AND have z-index `--z-fab`
- AND have shadow `0 4px 12px rgba(59,91,219,0.35)`

#### Scenario: FAB is accessible

- GIVEN the FAB is rendered
- WHEN screen reader accesses it
- THEN it MUST have `aria-label="Nueva conversación"`