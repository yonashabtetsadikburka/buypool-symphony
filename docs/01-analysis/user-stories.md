# User stories — BuyPool

## Must (M1 MVP)

### US-01: View campaigns as guest
- Labels: user-story, must, campaigns
- Milestone: M1 MVP
- As a guest I want to list campaigns and open a campaign detail so that I can decide whether to register and join.
- Acceptance criteria
  - [ ] Campaign list returns HTTP 200 without login and shows MOQ, current quantity, deadline, status, and current price.
  - [ ] Campaign detail returns HTTP 200 without login and shows price tiers ordered by threshold.
  - [ ] Guest banner is visible on the detail page and join actions redirect to login.
  - [ ] Unknown campaign id returns HTTP 404.
- Priority: Must
- Links: FR-11, NFR-07, NFR-10

### US-02: Register with privacy consent
- Labels: user-story, must, identity
- Milestone: M1 MVP
- As a guest I want to register with privacy consent so that I can create an account.
- Acceptance criteria
  - [ ] Missing privacy flag returns HTTP 400 with privacy error.
  - [ ] Invalid email returns HTTP 400 and duplicate email returns HTTP 409.
  - [ ] Password shorter than 8 characters returns HTTP 400.
  - [ ] Valid registration returns HTTP 201 and stores consent timestamp.
- Priority: Must
- Links: FR-01, FR-02, FR-47, NFR-02, NFR-06

### US-03: Log in and manage session
- Labels: user-story, must, identity
- Milestone: M1 MVP
- As a customer I want to log in with email or Google so that I can access my account.
- Acceptance criteria
  - [ ] Valid email and password return HTTP 200 with id, name, role, and status.
  - [ ] Invalid credentials return HTTP 401 without revealing which field failed.
  - [ ] Valid Google identity token returns HTTP 200 and links or creates a customer account.
  - [ ] Logout returns success and protected routes return HTTP 401 afterwards.
- Priority: Must
- Links: FR-03, FR-04, FR-06, NFR-01, NFR-02

### US-04: Join campaign with deposit
- Labels: user-story, must, participation
- Milestone: M1 MVP
- As a customer I want to join a campaign through a Stripe deposit so that I reserve my quantity.
- Acceptance criteria
  - [ ] Direct join without payment returns HTTP 410 with disabled-flow error.
  - [ ] Deposit checkout requires an active user and a campaign in progress or successful state.
  - [ ] Deposit amount equals quantity times initial unit price times the configured percentage, rejected below 0.50 EUR with HTTP 422.
  - [ ] Successful payment creates one participation per user and campaign and increments current quantity.
- Priority: Must
- Links: FR-17, NFR-03

### US-05: Withdraw before deadline
- Labels: user-story, must, participation
- Milestone: M1 MVP
- As a customer I want to withdraw an unpaid participation before the deadline so that I am not locked in.
- Acceptance criteria
  - [ ] Withdrawal after the deadline returns HTTP 409.
  - [ ] Withdrawal of a paid participation returns HTTP 403.
  - [ ] Valid withdrawal issues a Stripe refund, deletes the participation, and decrements current quantity.
  - [ ] Withdrawing a non-existent participation returns HTTP 404.
- Priority: Must
- Links: FR-18, FR-22, NFR-03, NFR-09

### US-06: Choose delivery mode
- Labels: user-story, must, delivery
- Milestone: M1 MVP
- As a customer I want to choose free pickup or paid home delivery so that I control how I receive the order.
- Acceptance criteria
  - [ ] Choice is accepted only when participation is confirmed, otherwise HTTP 409.
  - [ ] Home delivery without a complete address returns HTTP 422 with address error.
  - [ ] Pickup choice stores zero delivery cost and home delivery stores the configured fixed cost.
  - [ ] Delivery cost endpoint returns the fixed cost value.
- Priority: Must
- Links: FR-25, FR-26, NFR-07

### US-07: Pay the balance
- Labels: user-story, must, payments
- Milestone: M1 MVP
- As a customer I want to pay the balance so that I complete my order.
- Acceptance criteria
  - [ ] Balance total equals quantity times final tier price plus commission plus delivery cost, computed on the server and rounded to 2 decimals.
  - [ ] Webhook without valid Stripe signature returns HTTP 400 and changes nothing.
  - [ ] The same Stripe event processed twice creates no duplicate payment or participation.
  - [ ] Successful balance marks the participation as paid.
- Priority: Must
- Links: FR-20, FR-21, NFR-03, NFR-08

### US-08: Pickup with QR code
- Labels: user-story, must, delivery
- Milestone: M1 MVP
- As a customer I want to show a QR code for pickup so that I can prove my right to collect the goods.
- Acceptance criteria
  - [ ] QR view by the owner returns HTTP 200 with token, assigned quantity, and status.
  - [ ] QR view by another user returns HTTP 404.
  - [ ] QR is available only for pickup orders, not for home delivery.
  - [ ] Admin scan marks the code as scanned once; reuse returns HTTP 409 and unknown token returns HTTP 404.
- Priority: Must
- Links: FR-27, FR-28, NFR-01, NFR-05

### US-09: Confirm receipt
- Labels: user-story, must, delivery
- Milestone: M1 MVP
- As a customer I want to confirm receipt of my home delivery so that the order is closed.
- Acceptance criteria
  - [ ] Confirmation by a non-owner returns HTTP 403.
  - [ ] Confirmation when delivery is not shipped returns HTTP 409.
  - [ ] Valid confirmation marks the delivery as delivered and sends a receipt notification.
  - [ ] Second confirmation returns HTTP 409.
- Priority: Must
- Links: FR-30, NFR-05, NFR-09

### US-10: Create and manage campaigns
- Labels: user-story, must, campaigns
- Milestone: M1 MVP
- As an admin I want to create and manage campaigns with MOQ and price tiers so that group orders can start.
- Acceptance criteria
  - [ ] Non-admin creation returns HTTP 403.
  - [ ] Creation with MOQ below 1 or more than 10 tiers returns HTTP 400.
  - [ ] Edit of MOQ or price with existing participations returns HTTP 409.
  - [ ] Delete with existing payments is rejected and delete without payments returns success.
- Priority: Must
- Links: FR-12, FR-13, FR-14, NFR-05, NFR-11

### US-11: Close campaign and send order
- Labels: user-story, must, campaigns
- Milestone: M1 MVP
- As an admin I want to close expired campaigns and send successful orders to suppliers so that customers are charged or refunded.
- Acceptance criteria
  - [ ] Closure selects only expired campaigns without a closure timestamp.
  - [ ] Campaign below the minimum is marked failed and deposits are refunded.
  - [ ] Campaign at or above the minimum uses the highest reached tier price for the balance.
  - [ ] Allocation and send-to-supplier run exactly once; repeat returns HTTP 409.
- Priority: Must
- Links: FR-15, FR-16, FR-22, NFR-03, NFR-08, NFR-09

### US-12: Supplier activation and fulfillment
- Labels: user-story, must, suppliers
- Milestone: M1 MVP
- As a supplier I want to activate my account from an invitation and advance orders in fixed steps so that I can fulfill group orders.
- Acceptance criteria
  - [ ] Activation with an expired or reused token returns HTTP 410 or 404.
  - [ ] Activation with an existing email returns HTTP 409.
  - [ ] Advance follows sent to received to preparing to fulfilled; skipping a step returns HTTP 400.
  - [ ] Fulfilled marks the campaign as delivered and notifies pickup customers.
- Priority: Must
- Links: FR-34, FR-38, NFR-01, NFR-05

## Should (M2 Complete)

### US-13: Vote on proposals
- Labels: user-story, should, proposals
- Milestone: M2 Complete
- As a customer I want to vote on voting proposals so that I can influence the catalog.
- Priority: Should
- Links: FR-31, FR-32, NFR-05

### US-14: Publish proposal to campaign
- Labels: user-story, should, proposals
- Milestone: M2 Complete
- As an admin I want to publish an approved proposal so that a campaign is created with broadcast.
- Priority: Should
- Links: FR-33, NFR-05, NFR-09

### US-15: Export personal data
- Labels: user-story, should, privacy
- Milestone: M2 Complete
- As a customer I want to export my personal data so that I can see what is stored.
- Priority: Should
- Links: FR-48, NFR-06

### US-16: Request account deletion
- Labels: user-story, should, privacy
- Milestone: M2 Complete
- As a customer I want to request account deletion so that I can exercise my right to erasure.
- Priority: Should
- Links: FR-49, NFR-06

### US-17: Reset password
- Labels: user-story, should, identity
- Milestone: M2 Complete
- As a customer I want to reset my password with a time-limited link so that I can recover access.
- Priority: Should
- Links: FR-07, FR-44, NFR-02

### US-18: Submit eligible reviews
- Labels: user-story, should, reviews
- Milestone: M2 Complete
- As a customer I want to review only suppliers and campaigns I purchased so that ratings stay trustworthy.
- Priority: Should
- Links: FR-54, FR-55, NFR-05

### US-19: Suspend abusive users
- Labels: user-story, should, admin
- Milestone: M2 Complete
- As an admin I want to suspend abusive users so that I can block misuse while keeping read access.
- Priority: Should
- Links: FR-09, FR-10, NFR-01

### US-20: Ship home deliveries
- Labels: user-story, should, delivery
- Milestone: M2 Complete
- As an admin I want to ship fulfilled home deliveries so that customers are notified.
- Priority: Should
- Links: FR-29, FR-42, FR-46, NFR-09

### US-21: View participations and catalog
- Labels: user-story, should, participation
- Milestone: M2 Complete
- As a customer I want to view my participations and the supplier catalog so that I can track orders and discover products.
- Priority: Should
- Links: FR-19, FR-56, NFR-07

### US-22: Update profile
- Labels: user-story, should, identity
- Milestone: M2 Complete
- As a customer I want to update my profile and password so that my data stays correct and secure.
- Priority: Should
- Links: FR-08, NFR-02

### US-23: Manage users, suppliers, and notifications
- Labels: user-story, should, admin
- Milestone: M2 Complete
- As an admin I want to manage users, suppliers, images, orders, and notifications so that I can operate the platform.
- Priority: Should
- Links: FR-39, FR-40, FR-41, FR-42, FR-43, NFR-05, NFR-11

### US-24: Secure Microsoft login
- Labels: user-story, should, identity
- Milestone: M2 Complete
- As a customer I want to log in with Microsoft through verified tokens so that company login is secure.
- Priority: Should
- Links: FR-05, NFR-01

### US-25: Fix threshold and concurrency handling
- Labels: user-story, should, robustness
- Milestone: M2 Complete
- As a customer I want correct threshold notifications and safe concurrent withdrawal so that balances and quantities stay consistent.
- Priority: Should
- Links: FR-18, FR-46, NFR-09, NFR-11

## Could (M3 Enhancements)

### US-26: Retry failed balance
- Labels: user-story, could, payments
- Milestone: M3 Enhancements
- As a customer I want to retry a failed balance payment so that I do not lose my order.
- Priority: Could
- Links: FR-23, NFR-03

### US-27: View wallet history
- Labels: user-story, could, payments
- Milestone: M3 Enhancements
- As a customer I want to view my wallet detail and movements so that I can control my spending.
- Priority: Could
- Links: FR-24, NFR-07

## Summary

| ID | Title | Priority | Milestone | Linked FR |
|----|-------|----------|-----------|-----------|
| US-01 | View campaigns as guest | Must | M1 MVP | FR-11 |
| US-02 | Register with privacy consent | Must | M1 MVP | FR-01, FR-02, FR-47 |
| US-03 | Log in and manage session | Must | M1 MVP | FR-03, FR-04, FR-06 |
| US-04 | Join campaign with deposit | Must | M1 MVP | FR-17 |
| US-05 | Withdraw before deadline | Must | M1 MVP | FR-18, FR-22 |
| US-06 | Choose delivery mode | Must | M1 MVP | FR-25, FR-26 |
| US-07 | Pay the balance | Must | M1 MVP | FR-20, FR-21 |
| US-08 | Pickup with QR code | Must | M1 MVP | FR-27, FR-28 |
| US-09 | Confirm receipt | Must | M1 MVP | FR-30 |
| US-10 | Create and manage campaigns | Must | M1 MVP | FR-12, FR-13, FR-14 |
| US-11 | Close campaign and send order | Must | M1 MVP | FR-15, FR-16, FR-22 |
| US-12 | Supplier activation and fulfillment | Must | M1 MVP | FR-34, FR-38 |
| US-13 | Vote on proposals | Should | M2 Complete | FR-31, FR-32 |
| US-14 | Publish proposal to campaign | Should | M2 Complete | FR-33 |
| US-15 | Export personal data | Should | M2 Complete | FR-48 |
| US-16 | Request account deletion | Should | M2 Complete | FR-49 |
| US-17 | Reset password | Should | M2 Complete | FR-07, FR-44 |
| US-18 | Submit eligible reviews | Should | M2 Complete | FR-54, FR-55 |
| US-19 | Suspend abusive users | Should | M2 Complete | FR-09, FR-10 |
| US-20 | Ship home deliveries | Should | M2 Complete | FR-29, FR-42, FR-46 |
| US-21 | View participations and catalog | Should | M2 Complete | FR-19, FR-56 |
| US-22 | Update profile | Should | M2 Complete | FR-08 |
| US-23 | Manage users, suppliers, and notifications | Should | M2 Complete | FR-39, FR-40, FR-41, FR-42, FR-43 |
| US-24 | Secure Microsoft login | Should | M2 Complete | FR-05 |
| US-25 | Fix threshold and concurrency handling | Should | M2 Complete | FR-18, FR-46 |
| US-26 | Retry failed balance | Could | M3 Enhancements | FR-23 |
| US-27 | View wallet history | Could | M3 Enhancements | FR-24 |
