# Requirements — BuyPool

## Purpose and scope

BuyPool lowers prices by grouping customers into shared campaigns.
A campaign starts with a minimum quantity (MOQ) and tiered prices.
More participants lower the unit price according to price tiers.
Customers join with a deposit and complete a balance payment through Stripe.
Pickup uses a single-use QR code, or the order ships to the home address.
Users submit product proposals and vote before a campaign is published.
In scope: identity, campaigns, participation, payments, delivery and pickup, proposals, suppliers, admin, in-app notifications, privacy controls.
Out of scope: product likes, full product CRUD, automatic campaign creation, email or SMS delivery, demo seed data.

## Actors

### Guest (not registered)

Can:

* View public campaign list and campaign detail.
* View review lists for suppliers and campaigns.
* Register, log in, use Google and Microsoft login, request password reset.
* Verify a supplier invitation token and activate a supplier account.

Cannot:

* View login-required catalogs such as suppliers, products, or pickup points.
* Join a campaign, pay, vote, choose delivery, or use QR codes.

### Customer (role `customer`)

Can:

* Manage own profile, password, export, and deletion request.
* Join campaigns only through Stripe deposit checkout or payment intent.
* Withdraw an unpaid participation before the deadline.
* Choose pickup or home delivery when participation is confirmed.
* Pay the balance, retry a failed balance, view own QR code, confirm receipt.
* Create proposals, vote or withdraw a vote, submit eligible reviews.
* View own participations, wallet, notifications.

Cannot:

* Use admin routes or supplier routes.
* Join directly without payment, vote on own proposal, or review without an eligible purchase.
* Write when suspended, except withdrawing a vote, confirming receipt, or deleting an own review.

### Supplier (role `supplier` linked to a company)

Can:

* Do everything a customer can do.
* View and update own company profile.
* Submit product proposals with photo and price tiers.
* Monitor own proposals, campaigns, and supplier orders.
* Advance own supplier orders through the fixed fulfillment steps.

Cannot:

* Use admin routes.
* Operate without a linked company account.
* Submit proposals or advance orders when suspended.

### Admin (role `admin`)

Can:

* Create, edit, and delete campaigns, close campaigns, allocate quantities, and send orders to suppliers.
* Manage product images, QR pickup confirmation, shipments, deliveries supervision.
* Manage users, suppliers, invitations, company links, and password reset links.
* View other users wallets and aggregates, send notifications, publish proposals with automatic campaign creation.

Cannot:

* Use supplier routes, which require the supplier role.
* Modify own role, status, or delete own account.

## Functional requirements

### Identity

* FR-01 (Must, Guest): Guest can register with name, email, password of at least 8 characters, and mandatory privacy consent.
* FR-02 (Must, Guest): System rejects registration with missing consent, invalid email, weak password, or duplicate email with a specific error.
* FR-03 (Must, Customer): Customer can log in with email and password and receive an authenticated session.
* FR-04 (Must, Customer): Customer can log in with a Google identity token that is verified for signature, audience, and expiry.
* FR-05 (Should, Customer): Customer can log in with a Microsoft identity token that is verified for signature, audience, and expiry.
* FR-06 (Must, Customer): Authenticated user can retrieve own identity, and can log out and invalidate the session.
* FR-07 (Should, Customer): Customer can request a password reset and complete it with a single-use token valid for 24 hours.
* FR-08 (Should, Customer): Customer can update own profile fields with format validation and can change password after verifying the old password.
* FR-09 (Should, Admin): Admin can suspend and reactivate a user, but cannot change own status, role, or delete own account.
* FR-10 (Should, Customer): Suspended user can read own data but receives a forbidden error on write actions except withdrawing a vote, confirming receipt, or deleting an own review.

### Campaigns

* FR-11 (Must, Guest): Guest can list campaigns and view campaign detail with MOQ, current quantity, deadline, status, prices, and price tiers.
* FR-12 (Must, Admin): Admin can create a campaign with MOQ of at least 1, deadline, base and current price, and up to 10 price tiers with quantity threshold and positive price.
* FR-13 (Must, Admin): Admin can edit a campaign, but MOQ and price changes are rejected when participations exist.
* FR-14 (Must, Admin): Admin can delete a campaign only when it has no payments.
* FR-15 (Must, Admin): Admin can close expired campaigns, marking them failed with refunds below the minimum and successful with the best reached tier price otherwise.
* FR-16 (Must, Admin): Admin can allocate quantities for a successful campaign and send the supplier order exactly once.

### Participation

* FR-17 (Must, Customer): Customer can join a campaign only through a Stripe deposit checkout or payment intent for an open campaign.
* FR-18 (Must, Customer): Customer can withdraw an unpaid participation before the deadline and receives a refund with quantity decrement.
* FR-19 (Should, Customer): Customer can view own participations with quantity, status, and server-computed total.

### Payments

* FR-20 (Must, Customer): Customer can pay the campaign balance through Stripe checkout with a server-computed amount including commission and delivery cost.
* FR-21 (Must, Customer): Customer balance is confirmed only after a signature-verified Stripe webhook that is processed idempotently without duplicates.
* FR-22 (Must, Customer): Customer receives an automatic Stripe refund of the deposit on withdrawal or failed campaign closure.
* FR-23 (Could, Customer): Customer can retry a failed off-session balance payment with a newly created intent or session.
* FR-24 (Could, Customer): Customer can view own wallet detail and movements, while admin can view other users wallets and aggregates.

### Delivery and pickup

* FR-25 (Must, Customer): Customer with confirmed participation can choose free pickup at a pickup point or paid home delivery with a complete address.
* FR-26 (Must, Customer): Customer can view the fixed delivery cost before choosing home delivery.
* FR-27 (Must, Customer): Customer can view the QR pickup code only for own participation and only for pickup orders.
* FR-28 (Must, Admin): Admin can confirm a QR pickup code exactly once and rejects reused, cancelled, or unknown tokens.
* FR-29 (Should, Admin): Admin can mark a home delivery as shipped only when its supplier order is fulfilled.
* FR-30 (Must, Customer): Customer can confirm receipt only for own shipped home delivery.

### Proposals

* FR-31 (Should, Customer): Authenticated user can create a product proposal that starts in pending state.
* FR-32 (Should, Customer): Customer can vote or withdraw a vote only on voting proposals, with one vote per user and no self-vote.
* FR-33 (Should, Admin): Admin can change proposal status and can publish only from voting or approved state with a mandatory supplier, creating product, campaign, tiers, and an in-app broadcast.

### Suppliers

* FR-34 (Must, Supplier): Supplier can activate an account from a single-use invitation token valid for 7 days.
* FR-35 (Should, Supplier): Supplier can view and update own company profile.
* FR-36 (Should, Supplier): Supplier can submit a product proposal with photo and optional price tiers.
* FR-37 (Should, Supplier): Supplier can monitor own proposals, campaigns with participation rate, and received orders.
* FR-38 (Must, Supplier): Supplier can advance only own orders strictly from sent to received to preparing to fulfilled, with fulfilled marking the campaign as delivered.

### Admin

* FR-39 (Should, Admin): Admin can list users, view detail and history, update role and status, and delete only users without order history.
* FR-40 (Should, Admin): Admin can create and update suppliers, generate invitations, and link a supplier company to an existing user.
* FR-41 (Should, Admin): Admin can list, add, and delete product images.
* FR-42 (Should, Admin): Admin can supervise supplier orders and all deliveries.
* FR-43 (Should, Admin): Admin can send an in-app notification to a specific user with a valid type, title, and message.
* FR-44 (Should, Admin): Admin can generate a password reset link that invalidates previous links for the same user.

### Notifications

* FR-45 (Should, Customer): Customer can list own recent notifications, count unread ones, and mark one or all as read.
* FR-46 (Should, Customer): Customer receives in-app notifications for order confirmation, MOQ reached, payment outcome, shipment, receipt, pickup readiness, and campaign publication.

### Privacy and GDPR

* FR-47 (Must, Customer): System records privacy consent with timestamp at registration and blocks registration without consent.
* FR-48 (Should, Customer): Customer can export own account, participations, payments, notifications, proposals, and votes without password hash.
* FR-49 (Should, Customer): Customer can submit a deletion request that creates at most one admin notification per day, with actual deletion performed manually by an admin.

### Reviews

* FR-54 (Should, Customer): Customer can submit a supplier review with vote from 1 to 5 only with a paid participation at that supplier.
* FR-55 (Should, Customer): Customer can submit a campaign review only for own paid participation in a delivered campaign, and can delete only own review while admin can delete any review.
* FR-56 (Should, Customer): Authenticated customer can view supplier, product, and pickup point catalogs.

## Non-functional requirements

* NFR-01 (Security): Sessions use HttpOnly cookies with SameSite protection, regenerate session identifiers on login, and enforce role and active-status checks on every protected action.
* NFR-02 (Security): Passwords use a strong adaptive hash, require at least 8 characters, and password reset tokens are random, single-use, and time-limited.
* NFR-03 (Security): All payment amounts are recalculated on the server in cents, Stripe webhooks require signature verification, and duplicate events never create duplicate participations or payments.
* NFR-04 (Security): Uploaded images are limited to 5 MB, verified as real images, restricted to JPEG, PNG, and WebP, stored under random names, and never executed as code.
* NFR-05 (Security): Every object identifier or token endpoint enforces ownership or role checks and returns not-found or forbidden without leaking other users data.
* NFR-06 (Privacy and GDPR): The system collects consent with timestamp, excludes password hashes from export, supports deletion requests, and avoids indefinite retention of sensitive webhook payloads.
* NFR-07 (Performance): Public campaign list and detail respond within the target page budget under normal load and do not block on payment provider callbacks.
* NFR-08 (Availability): Campaign closure and payment reconciliation tolerate provider delays through idempotent webhook handling and a status fallback check.
* NFR-09 (Observability): The system logs authentication failures, payment events, refunds, closure outcomes, shipment events, and QR confirmations with correlatable identifiers.
* NFR-10 (Accessibility): Public browsing, registration, joining, payment initiation, and delivery choice meet WCAG 2.2 AA for keyboard, contrast, focus, and form errors.
* NFR-11 (Maintainability): The Symfony codebase uses migrations, deterministic allocation logic covered by golden-case tests, and clear boundaries for identity, campaigns, payments, delivery, proposals, suppliers, admin, and notifications.
* NFR-12 (Cost): The production target remains within Azure for Students limits, preferring managed MySQL, minimal always-on compute, and low egress and storage for images.
* NFR-13 (Portability): The application runs from environment configuration on Azure and local development with MySQL, without hardcoded secrets, URLs, or filesystem paths.

## Constraints and assumptions

* Stack: Symfony for the backend, MySQL for persistence, Stripe for deposits and balances, Google and Microsoft for social login, Azure for hosting.
* Deadline: 19 November 2026.
* Currency is EUR with two-decimal rounding on all computed amounts.
* Default timezone is Europe/Rome for deadlines, countdowns, token expiry, and closure.
* Deposit is a percentage of the initial unit price, balance is the remaining amount at the final tier price plus commission and delivery cost where applicable.
* Home delivery has a fixed configured cost, pickup is free.
* Campaign closure may run as an admin action or a protected scheduled call.
