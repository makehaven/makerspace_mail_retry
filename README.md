# Makerspace Mail Retry

Retries transactional email that failed to leave the building, so a transient
SMTP connection failure stops being a permanently lost message.

## The problem it solves

Measured on live, 2026-09-11. From 2026-09-02 onward — zero in the five days
before — Drupal's outgoing mail started failing with
`SMTP Error: Could not connect to SMTP host.` against `smtp.postmarkapp.com`.
26 failures against roughly 771 send attempts in fourteen days, every day since
onset: 2, 4, 0, 0, 4, 1, 3, 9, 3.

Each one was gone for good. `_smtp_mailer_send()` in contrib `smtp` 8.x-1.4
catches the exception, writes a watchdog line and returns `FALSE`. Nothing in
the path retries.

What was being lost, recovered by matching each failure to the info-level row
that precedes it:

- `Reminder: you are booked at MakeHaven tomorrow at 12:00 PM`
- `Your Borrowed Tool is Due Soon`
- `Tool Checkout Confirmation` / `Tool Return Confirmation: Pop Rivet Gun`
- `Appointment Canceled: Badge Checkout: Centrifuge …`
- `Feedback on your facilitator appointment`

A dropped "Your Borrowed Tool is Due Soon" is the one that bites: the member is
never warned and then collects a late fee for it.

## How it works

`RetryingMailSystem` is a mail plugin that does not send. It delegates to the
plugin named in `makerspace_mail_retry.settings:inner_plugin` (live:
`SMTPMailSystem`) and only gets involved when that delegate returns `FALSE`:
the message goes on the `makerspace_mail_retry` queue and `MailRetryWorker`
re-attempts it on cron with a lengthening backoff — 5 minutes, 15 minutes,
1 hour, then 6 hours — before giving up and logging exactly what was lost.

Wiring lives in `settings.php`, not in exported config, because the mail
interface is already set there per environment:

```php
$config['system.mail']['interface']['default'] = 'makerspace_mail_retry';
```

Setting that back to `'SMTPMailSystem'` removes the wrapper from the path
entirely. Setting `max_retries` to `0` keeps it in the path but makes it a pure
pass-through. Dev and test are untouched — they route to `maillog` and never
deliver.

## Two decisions worth knowing about

**A queued message is reported to the caller as success.** Delivery is
deferred, not abandoned, and returning `FALSE` would have callers show the
member an error for a message that is about to arrive. The trade is real: if
every retry fails, the caller has already claimed success, so the give-up path
logs at error level with the recipient and subject rather than a bare count.

**Params are stripped before queueing.** A `$message` reaching `mail()` has
already been through `format()`, so the body is a finished string. Callers
routinely park whole entities in `params`, and queueing those means storing a
snapshot that is stale by the time it is read. Only `from_name`, `from_mail`
and `attachments` — what the SMTP delegate actually reads — survive into the
queue. A delegate that reads other params would need this list extended.

## Verification

The kernel tests cover the retry decision, the backoff schedule, the
disable switch and param stripping. The loop itself was proven end to end
against the real mail stack rather than mocks: stopping the local MailHog
container reproduces the exact live error, the message is queued instead of
lost, and after restarting the container and running the queue the same
message — matched by subject — arrives in the inbox.

## What it does not do

- It does not distinguish a transient connection failure from a permanent
  rejection; both are retried until `max_retries`. The failure being addressed
  is a connect failure, where the delegate has no per-recipient verdict to
  report anyway.
- It does not touch CiviMail. CiviCRM sends broadcast mail through its own
  path; bounces from that side are handled by `makerspace_postmark`.
- It is not a queue for all outgoing mail. Successful sends never touch the
  queue, so normal delivery timing is unchanged.
