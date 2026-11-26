# Referral

Customer referral program for Thelia 3. A sponsor shares a personal code; a referee who registers with it
and places a first qualifying order triggers a reward for both, issued as a personal coupon.

## Installation

Requires Thelia 3.4.0 or later. Place the module in `local/modules/Referral`, then:

    php bin/console module:refresh
    php bin/console module:activate Referral

Tables `referral_code`, `referral` and `referral_reward` are created on first activation and dropped on uninstall.

## Settings

Stored in the module configuration; defaults are written on activation only when absent.

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
referee's first order is their earliest order that is neither cancelled nor refunded. When it reaches the
trigger status (`paid` counts paid, processing and sent; `sent` counts sent only), the sponsor gets a
personal coupon and the `referral_sponsor_reward` email. A later order never rewards again.

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
