# Referral

Customer referral program for Thelia 3. A sponsor shares a personal code; a referee who registers with it
and places a first qualifying order triggers a reward for both, issued as a personal coupon.

## Installation

Requires Thelia 3.4.0 or later. Place the module in `local/modules/Referral`, then:

    php bin/console module:refresh
    php bin/console module:activate Referral

Tables `referral_code`, `referral` and `referral_reward` are created on activation when they are missing, and
dropped on deletion when the merchant ticks "Also delete the data created by this module" (see Uninstall).

## Settings

Stored in the module configuration; defaults are written on activation only when absent. The merchant edits
them on the module page (`/admin/module/Referral`): values must be 0 or more, a percentage at most 100, the
coupon validity at least 1 day, the trigger status `paid` or `sent`. An invalid form writes nothing.

| Key | Default | Meaning |
|-----|---------|---------|
| `enabled` | 1 | Program on/off |
| `referee_value_type` | amount | `amount` or `percent` |
| `referee_value` | 10 | Referee coupon value |
| `referee_minimum_amount` | 30 | Minimum cart for the referee coupon |
| `sponsor_value_type` | amount | `amount` or `percent` |
| `sponsor_value` | 10 | Sponsor coupon value |
| `sponsor_minimum_amount` | 0 | Minimum cart for the sponsor coupon |
| `coupon_validity_days` | 90 | Coupon lifetime |
| `trigger_status` | paid | Order status that triggers rewards (`paid` or `sent`) |
| `monthly_cap` | 5 | Rewards a sponsor can earn per month |
| `cancel_on_refund` | 1 | Cancel rewards when the trigger order is refunded |
| `same_address_check` | 0 | Refuse referrals sharing the sponsor's address |

## Sponsor reward

Each status change of a referee's order (back office, API, payment module) reviews the referral. The
sponsor is rewarded for the referee's first order that reaches the trigger status (`paid` counts paid,
processing and sent; `sent` counts sent only): an order that never reached it (failed payment, abandoned,
still pending) or that was cancelled or refunded is ignored and does not block the next one. The sponsor then
gets a personal coupon and the `referral_sponsor_reward` email. One reward per referral at most: a later order
never rewards again.

| Referral status | Reason | Meaning |
|-----------------|--------|---------|
| `registered` | | Waiting for the first order |
| `rewarded` | | The sponsor's reward is issued |
| `capped` | `monthly_cap` | The sponsor reached the monthly cap (calendar month, `0` = no cap); final |
| `cancelled` | `sponsor_gone` | The sponsor's account was deleted |
| `cancelled` | `sponsor_inactive` | The sponsor's code is inactive |
| `cancelled` | `same_address` | Delivered to one of the sponsor's addresses (`same_address_check`) |
| `cancelled` | `order_cancelled`, `order_refunded` | The trigger order was cancelled or refunded (`cancel_on_refund`) |

A cancelled reward keeps its row (`status` `cancelled`, `cancel_reason`, `cancelled_at`); its coupon is
disabled unless it was already used. `Referral\Reward\RewardCanceller::cancel($reward, $reason)` does the
same for a cancellation decided by hand.

The monthly cap holds under concurrent payments: the review locks the sponsor's code row, then the referral
row, before counting. `Tests/Integration/Concurrency` proves it with two processes paying at once.

## Back office

- **Settings**: the card of the module configuration page (`/admin/module/Referral`).
- **Referrals** (`/admin/module/Referral/referrals`, entry of the Tools menu): counters (referrals, rewards
  issued, rewards cancelled), filter by status, 20 per page. Each row gives the sponsor and the referee (linked to
  their customer sheet), the sign-up date, the status with its reason in words (the monthly-cap reason quotes the
  current cap), the trigger order and the rewards (code, value, status). The page reads its rows in a fixed number
  of queries (counters, total, rows with their joins, rewards), whatever the page size.
- **Manual cancellation**: an issued reward is cancelled from the list with a reason of 1 to 255 characters
  (POST, CSRF token), through `RewardCanceller`.
- **Rights**: the VIEW right on the module opens the screens; saving the settings and cancelling a reward need
  UPDATE (a viewer sees neither the save button nor the cancel forms, and the routes answer 403).

Translations: domain `referral.bo.default` (`I18n/backOffice/default`).

## Personal data (GDPR)

`Referral\Privacy\ReferralPersonalDataProvider` adds a `referral` section to the customer export: the code, the
friends they sponsored (first name, sign-up date, status), their own sign-up as a referee and their rewards.
On anonymization a sponsor's code is deactivated (nobody can sign up with it again; the rewards of the friends
already referred stay valid). A referee's referral row stays: it holds ids, a status and dates only, and is what the
sponsor's statistics are made of.

## Purge of expired coupons

    php bin/console referral:purge-expired-coupons [--dry-run]

Deletes, in batches, the coupons issued by the module that are expired and were never used (no
`coupon_customer_count` row, not flagged used). The reward keeps its `coupon_code`; `coupon_id` becomes null.
`--dry-run` only counts. The command is declared on the `thelia` schedule (`0 3 * * *`), run by
`messenger:consume scheduler_thelia`.

## Uninstall

The message is always removed. With the "delete the data" box ticked, the coupons issued by the module that were
never used are deleted (used ones stay: orders carry their code), then the settings (`is_initialized` included),
then the tables: a reinstall starts from scratch. Without the box, tables and coupons stay; the core removes the
module settings with the module, and activating the module again keeps the existing tables (their presence, not the
`is_initialized` flag, decides whether `Config/thelia.sql` runs) and restores the default settings.
