# Pixelforge Studio

An image generation app built on the (fictional) Pixelforge API. Users write a prompt, see what it will cost, and pay exactly what Pixelforge charges plus a 25% markup.

**Live:** https://pixelforge.florincabuz.com

Laravel 13 · Vue 3 · Inertia 3 · Tailwind 4 · Pest · MySQL 8.

## What's in it

- **Studio** (`/dashboard`): a prompt, size, quality and up to 4 reference image URLs, with a live cost estimate ("≈ $0.0722 · at most $0.1320") before you submit. Below it is your history, with live statuses, the estimated and actual cost side by side, and the image.
- **Credits**: a balance with fake "Buy credits" packs. You can only submit when your balance covers the most the generation could cost.
- **Spend report** (`/admin/report`, admins only): credit spend, provider cost and profit per UTC day, per user, and per day × user. Each day is reconciled against Pixelforge's `GET /v1/usage`.

## Running it locally

Requirements: PHP 8.3+ with `bcmath` and `pdo_mysql`, Composer, Node 22, MySQL 8.

Create two databases, `pixelforge` and `pixelforge_testing` (tests use the second one, with the same host and credentials).

```bash
composer setup            # install, .env, key, migrate, build
```

If your MySQL isn't `root` with no password on `127.0.0.1`, set the `DB_*` variables in `.env` and run `php artisan migrate`.

Add a Pixelforge API key **for local use only** to `.env`:

```env
PIXELFORGE_API_KEY=...
PIXELFORGE_WEBHOOK_SECRET=whsec_...   # from the same key, once its webhook URL is set
```

```bash
composer run dev          # web server, queue worker, scheduler, logs and Vite
```

Webhooks need a public URL. Run `ngrok http 8000` (or the port in `SERVER_PORT`) and set `https://<tunnel>/webhooks/pixelforge` as the key's webhook URL. Without a tunnel everything still works, just slower: the poller picks up results within a few minutes.

There's no public sign-up. Create an account (add `--admin` to see the report):

```bash
php artisan users:create you@example.com --credits=10 --admin
```

It prints a generated password unless you pass `--password=`. Use `users:make-admin <email>` to promote an existing user.

Tests and checks (the same ones CI runs):

```bash
php artisan test          # Pixelforge is always faked
composer ci:check         # lint, formatting, type check, tests
```

Tests, local development, CI and production all use MySQL 8. The money code relies on database guarantees (row locks, `INSERT IGNORE` on unique indexes, conditional `UPDATE`s) that other databases implement differently, so they're tested where they run.

## How a generation flows

```
POST /generations
  └─ StartGeneration         estimate cost, save as `pending`, hold the maximum price from the balance
       └─ SubmitGeneration   (queued) POST /v1/generations with Idempotency-Key = our uuid
                             retries on 429 / 503 / network errors, gives up on the rest

Pixelforge ──webhook──▶ PixelforgeWebhookController   verify signature, dedupe by event id
generations:sync (every minute) ── polls generations that have gone quiet

            all three ──▶ ApplyPixelforgeUpdate        lock row, move status forward only,
                                                       record cost once, settle credits
```

Every status change from Pixelforge, whether it comes from the POST response, a webhook or polling, goes through [`ApplyPixelforgeUpdate`](app/Actions/Generations/ApplyPixelforgeUpdate.php). That is the one place where state and money change.

## Decisions

The brief left some details open on purpose. Here is what I chose and why.

### Money

- **All money is stored as integer nanodollars** (1 USD = 10⁹), not floats or decimals. Pixelforge reports costs with exactly 9 decimal places, so a nanodollar is the smallest unit we ever need. With integers, sums never drift. Costs are computed with `bcmath` and converted once, at the end ([`CostCalculator`](app/Billing/CostCalculator.php)).
- **Users pay the exact cost, not a rounded one.** I don't round to cents: the history shows e.g. `$0.3818875`, which is exactly what was charged. Credits are dollars here, so there is nothing to round to.
- **The markup is rounded _up_ to the next nanodollar**, so we never undercharge by a fraction. At today's rates the 25% markup is always exact, because every per-token cost divides by 4. The rounding only matters if rates change.
- **The actual cost uses the rates stored on the generation**, not today's pricing. Pixelforge fixes rates when a generation is created.

### Estimates

- **Expected** cost uses `ceil(characters / 4)` text tokens and the middle of the quality's reasoning range. **Maximum** cost uses +15% text tokens (rounded up) and the top of the reasoning range. It matches the "most a generation can cost" example in the docs.
- Characters are Unicode code points (`mb_strlen`), as the docs specify. The browser estimate ([`estimate.ts`](resources/js/lib/estimate.ts)) mirrors [`CostEstimator.php`](app/Billing/CostEstimator.php) with integer maths, so the number you see while typing is the one the server records.
- Pricing comes from `GET /v1/pricing`, cached for 5 minutes, with the last known pricing as a fallback if Pixelforge is down.

### Credits

- **On submit we hold the maximum possible cost.** On completion we release the hold and charge the actual price; on failure we only release it. The worst case is held, so a balance can't go negative because of generations that are still running.
- The balance check and the debit are a single conditional `UPDATE … WHERE balance >= ?`, so two concurrent submissions can't spend the same credits.
- Every movement is a row in an append-only ledger (`credit_transactions`). `users.balance` is the sum of those rows, kept in step in the same transaction. A unique index on `(generation_id, type)` makes each movement happen at most once per generation, no matter how many times an event is delivered ([`CreditLedger`](app/Billing/CreditLedger.php)).

### Robustness

- **Idempotent submission.** Each generation has its own uuid, sent as the `Idempotency-Key` and in `metadata`. Retrying after a timeout can never create or bill a second generation, and a webhook that arrives before our POST returns can still be matched.
- **Retries** ([`SubmitGeneration`](app/Jobs/SubmitGeneration.php)):
    - `concurrency_limit_exceeded` means "wait for a slot". It doesn't use up attempts, and we wait up to 15 minutes.
    - `overloaded`, `rate_limit_exceeded`, 5xx and network errors: up to 5 attempts with exponential backoff, respecting `Retry-After`, plus jitter.
    - A 429 rate limit pauses **all** submissions, not just this one, so we don't burn the 60 requests a minute.
    - `content_policy_violation`, `daily_limit_exceeded` and `validation_failed` fail straight away, free of charge, with a message the user can act on.
    - While a generation is retrying, the UI says why ("Waiting for a free slot…").
- **Webhooks** are verified against the raw body in constant time, with a 5 minute replay window. Each event id is inserted with `insertOrIgnore` on a unique index, in the same transaction as applying it. A duplicate is acknowledged and ignored, even when two deliveries arrive at the same moment. If applying fails, nothing is recorded and Pixelforge's retry starts clean.
- **Out-of-order events.** Statuses only move forward (`pending → queued → processing → succeeded | failed`), and a final status never changes. A late `processing` after `succeeded` is ignored, and so is a second `succeeded`, apart from refreshing the image URL.
- **Lost webhooks.** `generations:sync` runs every minute and polls generations that haven't changed for 3 minutes. It stops at the first 429, so it never starves real submissions.
- **"We gave up, but Pixelforge didn't."** If submission times out and we mark a generation failed, Pixelforge may still have accepted it. It would bill us, so its first update for that generation takes precedence over our local failure, and the user is charged once.

### The report

- **Days are the UTC day a generation completed**, because that's the day Pixelforge bills it on, and the database connection is pinned to UTC whatever the server's timezone. This is what makes our provider cost match `/v1/usage` exactly. Each day shows ✓ Exact or the difference to the nanodollar.
- Use one Pixelforge API key per environment: `/v1/usage` is per key, so production numbers only reconcile if only production uses that key.

### Smaller choices

- **Queue:** the database driver. Pixelforge allows 3 concurrent generations and 60 requests a minute, so the bottleneck is the provider, not our workers. Redis or Horizon would add infrastructure without changing that.
- **Accounts are locked down.** They're handed out and created with `users:create`, and reviewers share them. So the app has no sign-up, password reset or change, two-factor setup, passkeys, profile editing or account deletion. Any of those would let one person lock others out, or wipe the demo data and the report's numbers. Only log in and log out remain.
- **No local image storage.** The brief says it's fine for images to stop loading when Pixelforge's URLs expire after 24 hours. Expired images show "Image link expired".
