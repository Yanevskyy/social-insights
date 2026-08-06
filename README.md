# Social Insights

A WordPress plugin that collects reach and engagement from Instagram, Facebook
and LinkedIn into the site, keeps the history, and produces the quarterly
report.

Built for tender **WCCC26/432** as a demonstration of bespoke WordPress
development. It is not a past client project.

Answers this line of the brief directly:

> "The contractor will provide detailed quarterly reports to show the reach and
> engagement of social media activities."

---

## What it does

**Collects daily.** A scheduled job captures yesterday's figures from every
configured channel and stores them as snapshots, one row per channel per metric
per day.

**Keeps the history.** Platforms only expose recent data. A quarterly report
written in October has to cover July, and by then the API may no longer offer
that window. Storing snapshots as they happen is the only way the report stays
reproducible.

**Builds the report.** Current quarter against the previous one, per channel,
with a plain text version ready to paste into a document.

---

## The decision that shapes everything

**Channels are never added together.**

Instagram reports *reach* (unique accounts), Facebook reports
*page_impressions_unique*, LinkedIn reports *impressionCount* per share. These
are three different measurements. Summing them produces a larger number that
means less than any of its parts, and a public body reporting it would be
publishing a figure nobody can defend.

So each channel is reported with the platform's own metric names, side by side.
Where a single figure is genuinely useful, it says what it actually counts.

## The second decision

**A gap in the data is shown as a gap.**

If a channel stopped reporting for three weeks, the report says "62 of 92 days
have no data for this channel" rather than presenting the remaining weeks as
though they were the whole quarter. A quarter with genuinely no activity and a
quarter where the API refused our token look identical in a total, and treating
the second as the first puts a false figure in front of the client.

For the same reason, a change against the previous period is only shown when
there is a previous figure. Reporting "+100%" because the earlier quarter is
empty would be a fabricated improvement.

---

## Configuration

Each channel needs an account identifier and an access token, entered in
**Social Insights** in the WordPress admin.

| Channel | Identifier | API |
|---|---|---|
| Instagram | Instagram Business account id | Meta Graph API v21.0 |
| Facebook | Page id | Meta Graph API v21.0 |
| LinkedIn | Organisation id | LinkedIn Marketing API 202411 |

Endpoints can be overridden to point at a test environment. Left blank, the
official APIs are used.

Tokens are stored write-only in the interface: leaving the field blank keeps the
stored value, so saving an unrelated setting cannot silently disconnect a
channel.

---

## Error handling

Real API failures, handled as distinct outcomes rather than one generic error:

| What happened | What the plugin does |
|---|---|
| Token expired | Says so, quoting the platform's own message. An expired token and a missing permission need different fixes |
| Rate limited or quota exhausted | Reported as a quota problem, not as zero activity |
| One channel fails | The other channels still collect. The failure is recorded and shown on the dashboard |
| Follower count unavailable | Metrics already collected are kept. A nice-to-have must not cost the data that matters |
| Collection run twice for one day | A unique key turns the second run into an update, so a report cannot be inflated by re-running a job |

---

## Verified behaviour

Checked against a running instance with all three channels connected:

- three channels authenticated and returned follower counts
- 55 days of snapshots collected across two quarters
- quarterly report produced per channel, with comparison against the previous
  quarter
- data gaps reported explicitly (62 of 92 days), not hidden
- metrics from different platforms kept separate, never summed
- re-running collection for the same day updated rather than duplicated

## Requirements

WordPress 6.5+, PHP 8.2+. No external dependencies, no build step, no vendor
directory.

## Licence

GPL-2.0-or-later. Built by [ClarityWeb](https://clarityweb.ie).
