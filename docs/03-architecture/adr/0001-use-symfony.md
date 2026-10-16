# ADR 0001: Use Symfony for the backend

## Status

Accepted · 2026-10-16

## Context

BuyPool is a group-buying platform. The original implementation is a group project made of a vanilla JavaScript frontend and a PHP backend written without a framework, on top of MySQL, with Stripe for payments and Google for sign-in.

This repository is a personal rewrite with three goals:

- keep the product behavior, but build it on a structured, testable and secure-by-default foundation;
- follow the path from business need to cloud: analysis, architecture, implementation, CI/CD and Cloud;

## Decision

The backend is rebuilt with **Symfony** (current LTS release) on PHP 8.3, with:

- Doctrine ORM and migrations for data access and schema changes;
- the Security component and voters for authentication and per-resource authorization;
- the Validator and Serializer components for input and output;
- the Workflow component for the campaign, order and delivery states;
- Messenger for asynchronous work (notifications, emails);
- PHPUnit for unit, integration, functional and parity tests.

The frontend stays vanilla JavaScript and talks to the new API. Hosting and infrastructure choices are recorded in later ADRs.

## Alternatives considered

| Option | Why not |
|---|---|
| Keep plain PHP and improve it | Every cross-cutting concern (routing, security, validation, errors, tests) would have to be rebuilt by hand again. This is the situation the rewrite wants to leave. |
| Laravel | A good framework with a similar scope. Symfony was chosen for its explicit components (Security voters, Workflow, Messenger), its strict structure and its use in enterprise contexts. The difference is mostly a matter of preference and learning goals. |

## Consequences

Positive:

- Security, validation, errors and testing follow standard, documented patterns.
- Business rules can be isolated in services and covered by unit and parity tests.
- The PHP knowledge and the domain logic of the original project can be reused.
- A clear structure that is easy to explain and to deploy in containers on Cloud.

Negative:

- Small time to learn how Symfony and Doctrine works.
- More files and configuration than the original code for the same endpoint.

