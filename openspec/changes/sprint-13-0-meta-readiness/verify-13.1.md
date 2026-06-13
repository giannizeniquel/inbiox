# Verification — Sprint 13.1 Alpha-B: Frontend Provider Integration

> **Sprint**: 13.1 Alpha-B
> **Layer**: Frontend (Angular)
> **Tasks**: M-06 (WhatsAppCloudProvider), M-07 (Wire in app.config.ts)
> **Depends on**: Alpha-A (backend `POST /api/provider/send` endpoint exists)

---

## Manual E2E Verification Checklist

### Prerequisites

- [ ] Backend services running (`docker-compose up -d`)
- [ ] Frontend dev server running (`cd apps/web && ng serve`)
- [ ] Meta WhatsApp Cloud API configured (valid `META_ACCESS_TOKEN`, `META_PHONE_NUMBER_ID`)
- [ ] At least one contact with a valid phone number exists in the database

---

### Test Flow

#### 1. Open conversation with a contact that has a phone number

- [ ] Navigate to Inbox
- [ ] Select a ticket linked to a contact that has `phone` set
- [ ] Verify the ComposerComponent is visible at the bottom of the conversation panel

#### 2. Type and send a message

- [ ] Type a message in the Composer input field
- [ ] Press Enter (or click Send button)
- [ ] Verify the message appears immediately in the conversation with `deliveryStatus: 'sending'` (optimistic UI)

#### 3. Verify network request

- [ ] Open browser DevTools → Network tab
- [ ] Filter by `api/provider/send`
- [ ] Verify a `POST` request to `/api/provider/send` was made
- [ ] Inspect the request payload:
  ```json
  { "ticketId": "<ticket-id>", "content": "<message-text>" }
  ```
- [ ] Verify the response contains:
  ```json
  { "messageId": "wamid.<...>", "status": "sent" }
  ```

#### 4. Verify message status transition

- [ ] After the HTTP response, verify the optimistic message transitions from `sending` to `sent`
- [ ] The message bubble should show single check mark (or the configured "sent" indicator)

#### 5. Verify message arrives on real WhatsApp

- [ ] Open WhatsApp on the target phone
- [ ] Verify the message text appears in the conversation with the contact
- [ ] Verify the sender name/number matches the configured WhatsApp Business Account

#### 6. Verify backend persistence

- [ ] Query the database to confirm the Message was persisted:
  ```sql
  SELECT m.id, m.external_id, m.delivery_status, m.content
  FROM message m
  JOIN ticket t ON t.id = m.ticket_id
  WHERE t.id = <ticket-id>
  ORDER BY m.created_at DESC
  LIMIT 1;
  ```
- [ ] Verify `external_id` matches the WAMID from step 3 response
- [ ] Verify `delivery_status` is `'sent'`

---

### Error Scenarios

#### 7. Contact has no phone number

- [ ] Open a ticket whose contact has `phone = null`
- [ ] Try to send a message
- [ ] Verify the response is a 422 error with `PHONE_NOT_FOUND`
- [ ] Verify the message shows as `failed` in the UI (after retries exhausted)

#### 8. Backend is down

- [ ] Stop the backend service
- [ ] Try to send a message from the frontend
- [ ] Verify the message shows as `failed` with an error indicator after retry attempts
- [ ] Verify the retry button/action is available on the failed message

#### 9. Invalid ticket ID

- [ ] Open browser console
- [ ] Manually call `sendMessage('invalid-ticket', 'test')`
- [ ] Verify the backend returns 404
- [ ] Verify the observable errors appropriately

---

### Build Verification

- [ ] `cd apps/web && npx ng build` succeeds with zero errors
- [ ] No TypeScript compilation errors in `whatsapp-cloud.provider.ts`
- [ ] All `ProviderInterface` methods are implemented (no missing method errors)

---

### Regression Checks

- [ ] ComposerComponent still visible and functional
- [ ] Existing conversations render correctly
- [ ] Message bubbles display with correct alignment (agent right, customer left)
- [ ] Scroll behavior in conversation panel is unchanged
- [ ] Other application features (contacts, reports, etc.) are unaffected

---

### Mercure/SSE Integration (Post-Verification)

These steps require Sprint 13.2 (Bravo — Webhook Controller) to be complete:

- [ ] Send a message via the frontend
- [ ] Simulate a webhook status update (e.g., `sent → delivered`)
- [ ] Verify the Mercure event updates the message's `deliveryStatus` in the conversation store
- [ ] Verify the UI reflects the updated status without manual refresh

---

## Test Commands

```bash
# Build check
cd apps/web && npx ng build

# Zero errors check
cd apps/web && npx ng build 2>&1 | grep -i error

# Query backend for persisted message
docker exec -it inbiox_mysql mysql -u inbiox -p inbiox -e "
  SELECT m.id, m.external_id, m.delivery_status, m.content
  FROM message m
  JOIN ticket t ON t.id = m.ticket_id
  WHERE t.id = <ticket-id>
  ORDER BY m.created_at DESC
  LIMIT 1;
"
```

---

## Sign-off

| Check | Status | Notes |
|-------|--------|-------|
| Build passes | ⬜ | |
| Optimistic → sent flow works | ⬜ | |
| Message arrives on WhatsApp | ⬜ | |
| WAMID persisted in DB | ⬜ | |
| Error scenarios handled | ⬜ | |
| Regression: existing features work | ⬜ | |
