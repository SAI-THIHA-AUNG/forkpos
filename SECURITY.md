# Security Policy

## Supported versions

Only the latest commit on the default branch (`main`) is actively maintained.
We do not backport security fixes to older tags.

| Version | Supported |
| --- | --- |
| `main` (latest) | ✅ |
| older tags / branches | ❌ |

## Reporting a vulnerability

Please **do not** open a public issue for a security problem.

Instead, report it privately through GitHub:

1. Go to **Security → Advisories → Report a vulnerability** (or use the
   "Private vulnerability reporting" option on the Security tab).
2. Describe the issue, the affected version, and (if possible) steps to
   reproduce or a proof of concept.
3. We will acknowledge within a few days and work with you on a fix and a
   coordinated disclosure.

If private reporting is not enabled on this repository, email the maintainer
directly (do not include the exploit details in a public channel).

## What counts as a security issue

- Authentication / authorization bypass (login, PIN, Sanctum tokens, roles).
- Exposure of customer data, sales, or ERPNext credentials.
- Remote code execution, SQL injection, or unsafe deserialization.
- Anything that lets a cashier exceed their POS profile permissions
  (rate changes, discounts, credit limits).

## Secrets

- Never commit `.env`, SQLite databases, API keys, or tokens. These are already
  gitignored (`.env`, `database/*.sqlite`, `storage/*.key`, `*.patch`).
- Secret scanning with push protection is enabled; pushes that contain a known
  secret format are blocked automatically.
- If you believe a secret was ever committed, treat it as compromised:
  rotate it in ERPNext / the deployment, then scrub it from history
  (see GitHub's "Removing sensitive data from a repository" guide).

## Disclosure

We follow a coordinated-disclosure model. Please give us a reasonable window to
release a fix before publishing details of a vulnerability.
