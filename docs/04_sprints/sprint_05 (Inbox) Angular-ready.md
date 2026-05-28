You are a senior Symfony 7.4 developer working on a production-grade system called "Inbiox".

Your task is to implement ONLY Sprint 5 (Inbox API).

This is a STRICT execution task. Follow all rules exactly.

---

# 🎯 SPRINT 5 GOAL

Expose a stable, performant API for an Angular frontend to:

- list tickets (inbox)
- filter/sort tickets
- fetch ticket detail
- fetch messages (chat view with cursor pagination)

NO UI. Backend only.

---

# 🧠 DESIGN PRINCIPLES (MANDATORY)

1. Stable response contracts (no shape changes per request)
2. DTO-only responses (never expose entities)
3. Deterministic sorting
4. No N+1 queries
5. Pagination required everywhere
6. Minimize roundtrips (compose data server-side)
7. Backward/forward cursor for messages

---

# 🔧 TECHNICAL REQUIREMENTS

- Symfony 7.4
- Doctrine ORM
- MySQL 8+
- QueryBuilder or native SQL when needed
- DateTimeImmutable (UTC)
- Read layer separated (Query services)

---

# 📁 PROJECT STRUCTURE

src/
  Application/
    DTO/
    Query/
  Infrastructure/
    Http/
  Domain/
    Ticket/
    Message/

---

# 📦 ENDPOINTS

----------------------------------------
## 1) GET /tickets
----------------------------------------

### Query Params

- page (int, default=1, min=1)
- limit (int, default=20, max=100)

- filter (string, default="all"):
    - all
    - unassigned
    - assigned
    - mine

- agent_id (required if filter=mine)

- status (optional, comma-separated):
    e.g. status=new,active

- search (optional string):
    → matches contact.phone or contact.name (LIKE, prefix allowed)

- sort (optional):
    allowed values:
    - "last_message_desc" (default)
    - "updated_desc"

---

### Response

{
  "data": [
    {
      "ticket_id": 10,
      "status": "active",
      "priority": "medium",

      "contact": {
        "id": 5,
        "phone": "+5437...",
        "name": null
      },

      "last_message": {
        "id": 100,
        "content": "Hola",
        "direction": "inbound",
        "created_at": "2026-01-01T10:00:00Z"
      },

      "owner": {
        "id": 3,
        "name": "Juan"
      },

      "metrics": {
        "unread_count": 0   // placeholder (always 0 in this sprint)
      },

      "timestamps": {
        "updated_at": "2026-01-01T10:00:00Z",
        "opened_at": "2026-01-01T09:00:00Z"
      }
    }
  ],
  "meta": {
    "page": 1,
    "limit": 20,
    "total": 120,
    "has_next": true
  }
}

---

### QUERY REQUIREMENTS

- MUST fetch:
  Ticket + Conversation + Contact + Owner + Last Message

- Last Message strategy (MANDATORY):
  Use ONE of:
  A) subquery (MAX(message.created_at))
  B) window function (ROW_NUMBER PARTITION BY ticket_id)

- ORDER:
  - default: last_message.created_at DESC
  - tie-breaker: ticket.id DESC

- Filtering:

  IF filter = "unassigned":
      owner_agent_id IS NULL

  IF filter = "assigned":
      owner_agent_id IS NOT NULL

  IF filter = "mine":
      owner_agent_id = :agent_id

- Status filter:
  WHERE ticket.status IN (...)

- Search:
  LOWER(contact.phone) LIKE
  OR LOWER(contact.name) LIKE

---

### PERFORMANCE REQUIREMENTS

- NO SELECT *
- SELECT only needed columns
- NO N+1
- Use proper indexes:
    - ticket(conversation_id, status)
    - ticket(owner_agent_id)
    - message(ticket_id, created_at)
    - contact(phone)

---

----------------------------------------
## 2) GET /tickets/{id}
----------------------------------------

### Response

{
  "ticket_id": 10,
  "status": "active",
  "priority": "medium",

  "contact": {
    "id": 5,
    "phone": "+5437...",
    "name": null
  },

  "owner": {
    "id": 3,
    "name": "Juan"
  },

  "timestamps": {
    "opened_at": "...",
    "assigned_at": "...",
    "resolved_at": null,
    "closed_at": null
  }
}

---

### RULES

- MUST validate ticket exists
- MUST include minimal info only (no messages here)

---

----------------------------------------
## 3) GET /tickets/{id}/messages
----------------------------------------

### Query Params

- cursor (optional string)
- limit (default=50, max=100)
- direction (optional):
    - "forward" (default)
    - "backward"

---

### Cursor Format (MANDATORY)

Base64 encoded:

{
  "created_at": "...",
  "id": 123
}

---

### Response

{
  "data": [
    {
      "message_id": 100,
      "direction": "inbound",
      "content": "Hola",
      "created_at": "2026-01-01T10:00:00Z"
    }
  ],
  "meta": {
    "has_more": true,
    "next_cursor": "opaque_string"
  }
}

---

### QUERY RULES

- ORDER BY created_at ASC, id ASC

- Forward pagination:
  WHERE (created_at, id) > (cursor.created_at, cursor.id)

- Backward pagination:
  WHERE (created_at, id) < (...)

- Always return consistent ordering

---

# 🔄 APPLICATION LAYER

Create Query Services:

- ListTicketsQuery
- GetTicketDetailQuery
- GetTicketMessagesQuery

Rules:

- MUST return DTOs
- MUST be read-only
- MUST NOT use entities in response

---

# 📦 DTO DEFINITIONS (MANDATORY)

- TicketListItemDTO
- TicketDetailDTO
- MessageDTO
- PaginationMetaDTO

DTOs must match response EXACTLY.

---

# 🧪 TEST CASES (MANDATORY)

1. Pagination:
   → page 1 / page 2 works

2. Filter mine:
   → only agent tickets

3. Filter unassigned:
   → correct

4. Status filter:
   → multiple statuses supported

5. Search:
   → matches phone/name

6. Messages:
   → correct order
   → cursor works forward/backward

7. Performance:
   → verify no N+1

---

# 🧼 CODE QUALITY RULES

- No logic in controllers
- Query services only for reads
- DTO mapping layer required
- Avoid duplicated queries

---

# 🚫 STRICT PROHIBITIONS

DO NOT implement:

- Angular frontend
- WebSockets
- Realtime updates
- Assignment logic
- Ticket updates

---

# 📦 OUTPUT REQUIRED

Generate:

1. Controllers
2. Query services
3. DTOs
4. Optimized queries (DQL or SQL)
5. Tests

---

# 🧪 FINAL VALIDATION CHECKLIST

- Contracts respected 100%
- Filters correct
- Sorting deterministic
- Cursor pagination stable
- No N+1 queries
- API ready for Angular consumption

---

Execute Sprint 5 now.