# SimplePay Gateway for GiveWP

Accept bank card donations and recurring donations in GiveWP through SimplePay (v2.1).

SimplePay has no embeddable card form, so every donation is paid on the hosted SimplePay payment page. The donor is redirected there, returns to your site, and SimplePay's IPN (server-to-server notification) sets the final donation status.

## Features

- Hosted SimplePay payment page, donation statuses confirmed by the signed IPN
- Refunds from the GiveWP donation screen
- Recurring donations through SimplePay card-registration tokens, charged by an hourly WP-Cron job
- Sandbox while GiveWP Test Mode is enabled, live otherwise

## Requirements

- WordPress 6.0+
- PHP 7.4+
- GiveWP 3.20+ (written against the 4.13 gateway API; not yet certified by SimplePay, test in the sandbox first)
- GiveWP Recurring Donations add-on for subscriptions
- A SimplePay merchant account (one merchant ID and secret key per currency; HUF, EUR or USD)

## Installation

1. Copy this folder to `wp-content/plugins/give-simplepay`.
2. Activate **SimplePay Gateway for GiveWP**.

## Configuration

1. Go to Donations > Settings > Payment Gateways > SimplePay and enter the merchant ID and secret key.
2. Copy the IPN URL shown on that page into the SimplePay merchant admin (Technical details).
3. Enable **SimplePay** under Donations > Settings > Payment Gateways > Gateways.

GiveWP Test Mode selects the SimplePay sandbox; the same merchant ID and secret key work in both environments.

## How a donation flows

1. The donor submits the form and is redirected to SimplePay.
2. After paying, the donor returns to your site and the donation is marked Processing. A cancelled or timed-out payment is marked Cancelled, a declined one Failed.
3. SimplePay's IPN arrives and the donation becomes Complete (or Failed / Cancelled).

The page the donor lands on shows the result and the SimplePay transaction ID, as SimplePay requires. The data-transfer declaration shown on the form is also a SimplePay requirement.

SimplePay needs a billing address (city, postcode, street) for every charge. If the form does not collect one, the donor enters it on the SimplePay payment page.

## Recurring donations

The first payment registers the card and returns up to 24 tokens, one per renewal, valid until the last renewal they cover (at most 5 years, and never beyond the card's expiry). Due renewals are charged hourly, using one token each; a renewal is recorded as Processing until its IPN arrives. After 3 consecutive failures, or when the tokens run out, the subscription is marked Failing and the donor must start a new recurring donation. Cancelling a subscription cancels its remaining tokens.

Renewals are charged without a payment page, so add the billing address fields to recurring forms; SimplePay may reject token charges without billing details.

## Troubleshooting

Check Donations > Tools > Logs. Make sure the IPN URL is reachable from SimplePay's IP ranges: 80.249.162.112/28, 84.2.229.128/27, 195.228.18.224/29.

## License

GPL-2.0-or-later
