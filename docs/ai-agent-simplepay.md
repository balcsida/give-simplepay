# SimplePay Integration Guide for AI Agents

This guide condenses the official SimplePay documentation set (core v2.1 guide, card storage, auto payments, and payment info brochure) into an operational reference tailored for autonomous agents. Follow the referenced documents for legal terms, PCI-DSS obligations, and branding assets.

## 1. Platform Overview
- **Service operator:** SimplePay Plc. (OTP Group member). Contact: +36 1 3666 611, ugyfelszolgalat@simple.hu.
- **Supported payment instruments:** bank cards (incl. Maestro/Visa Electron per issuer approval), Simple account, Apple Pay, Google Pay, qvik QR, SZÉP card, and bank transfer.
- **System separation:** Independent sandbox and live clusters; identical merchant credentials, different base URLs. Switching environments is a matter of changing the hostname only.
- **Sandbox base endpoints:**
  - Merchant admin: `https://sandbox.simplepay.hu/admin/`
  - API root: `https://sandbox.simplepay.hu/payment/v2/`
  - Example start endpoint: `https://sandbox.simplepay.hu/payment/v2/start`
- **Live base endpoints:**
  - Merchant admin: `https://admin.simplepay.hu/admin/`
  - API root: `https://secure.simplepay.hu/payment/v2`
  - Example start endpoint: `https://secure.simplepay.hu/payment/v2/start`
- **Test limitations:** Sandbox simulates transactions (test cards/virtual flows only); no financial settlement or production services (Simple app cards, analytics, etc.). Live handles real money and production wallets.

## 2. API Fundamentals
- **Transport:** HTTPS POST with `Content-Type: application/json` for all API calls; redirects (back/challenge) use GET with query params.
- **Encoding & format:** UTF-8 JSON bodies; ISO 8601 timestamps; ISO 4217 currency; ISO 3166-1 alpha-2 countries; ISO 639-1 languages.
- **Signature:** HMAC-SHA384 over the raw JSON body, base64 encoded, sent/validated via the `Signature` HTTP header. All responses include signatures that must be verified.
- **Randomization:** Include a 32-character `salt` in every request and expect one in every response.
- **Error handling:** Failed calls return an `errorCodes` array; client must handle unknown future fields gracefully.

## 3. Core Transaction Flow
1. Merchant collects cart/customer data and calls `start` to create a transaction.
2. SimplePay responds with a `paymentUrl`; redirect the shopper (or display QR/transfer data).
3. Shopper completes authorization on SimplePay (or via selected wallet/transfer path).
4. Shopper returns to merchant via browser (`back` GET) carrying Base64 JSON (`r`) and signature (`s`); merchant informs shopper of the provisional result.
5. SimplePay performs fraud screening and posts an `ipn` notification to the merchant’s server confirming final status so the order can be fulfilled.

### 3.1 Transaction Status Model
- API-queryable statuses: `INIT`, `TIMEOUT`, `CANCELLED`, `NOTAUTHORISED`, `INPAYMENT`, `INFRAUD`, `AUTHORISED`, `REVERSED`, `FINISHED`.
- Browser events on return: `SUCCESS`, `FAIL`, `CANCEL`, `TIMEOUT`.
- Fulfillment must wait for IPN when the status becomes `FINISHED` (or `AUTHORISED` in two-step flows).

### 3.2 `start` Request Essentials
Required fields: `salt`, `merchant`, `orderRef`, `currency`, `customerEmail`, `language`, `sdkVersion`, `methods`, `total`, `timeout`, `url` (or `urls` map), and `invoice` object. Optional enrichments include `items`, `shippingCost`, `discount`, `customer`, `delivery`, `twoStep`, and `maySelect*` toggles for collecting email/invoice/delivery data on the payment page.
- For transfers use `"methods":["WIRE"]`; timeout defaults to admin configuration unless overridden.
- Include 3DS-ready data when available: billing details, `threeDSReqAuthMethod`, `maySelectEmail`, and `maySelectInvoice`. Strong Customer Authentication is triggered automatically when banks require it.

### 3.3 `start` Response
Response echoes identifiers and supplies `paymentUrl` plus expiry. Merchant can POST a simple HTML form or issue a redirect using this URL.

### 3.4 Browser Return (`back`)
SimplePay appends:
- `r`: Base64 JSON containing `r` (response code), `t` (transactionId), `e` (event), `m` (merchant), `o` (orderRef).
- `s`: Signature for the decoded payload.
Agents must decode and verify before presenting user-facing messaging tailored to `SUCCESS`, `FAIL`, `CANCEL`, or `TIMEOUT`. Avoid revealing sensitive diagnostics beyond recommended guidance.

### 3.5 Instant Payment Notification (`ipn`)
- Origin IP ranges: `80.249.162.112/28`, `84.2.229.128/27`, `195.228.18.224/29`.
- Delivered as POST with signed JSON containing `orderRef`, `method`, `transactionId`, `status`, `paymentDate/finishDate`. Respond with a signed acknowledgement including `receiveDate`.
- Optional configuration allows IPN for unsuccessful terminal statuses; default covers settlement-relevant states (`FINISHED`, `AUTHORISED`, `NOTAUTHORISED`, `REVERSED`, `CANCELLED`, `TIMEOUT`).

### 3.6 Two-Step Capture (`finish`)
- Endpoint: `/finish`.
- Supply `orderRef` or `transactionId`, plus `originalTotal` and desired `approveTotal`.
- Charging scenarios: full capture, partial capture (>0 & < original), or release (`approveTotal = 0`).
- `finish` must occur within 21 calendar days or the hold auto-reverses.

### 3.7 Refunds (`/refund`) and Queries (`/query`)
- Refund supports card and instant transfer payments; send amount and identifiers similar to finish.
- Use `query` to poll transaction status if IPN is unavailable or delayed.

## 4. Card Storage & Tokenization
### 4.1 Key Concepts
- **Card registration:** Only successful authorizations can yield stored credentials; data remain in PCI-DSS audited SimplePay systems.
- **cardSecret:** Shopper-defined credential proving presence for OneClick; merchant must never persist it locally.
- **Token:** Issued during registration for recurring use; constrained by merchant-defined count, amount cap, and expiry.

### 4.2 OneClick (Cardholder Present)
1. Shopper opts to register a card and defines `cardSecret`; merchant passes it in `start`.
2. Shopper completes first authorization on SimplePay; IPN reveals masked PAN and expiry.
3. Subsequent purchases call `/do` with `cardSecret`; SimplePay may require 3DS challenge via `redirectUrl`. Final result returns synchronously, but merchant must still await IPN.

### 4.3 Recurring (Cardholder Absent)
- Registration variants:
  - **One-step:** Real purchase plus token request; tokens activated after successful IPN.
  - **Two-step:** Symbolic authorization + manual `finish` (or automatic release via `onlyCardReg`) to register without charging.
- Tokens store counts, amount caps, and expiry; only unused, in-limit, in-date tokens may trigger `/dorecurring`.
- Two-step accounts can mix immediate and deferred captures using the `twoStep` flag on payment initiation.

### 4.4 Maintenance APIs
- `/cardquery`: Retrieve stored card metadata.
- `/cardcancel`: Remove stored card.
- `/tokenquery` & `/tokencancel`: Inspect or deactivate recurring tokens.
- IPN for registration events mirrors the core payment format and includes masked PAN + expiry details.

## 5. Auto Payments (`/auto`)
Auto payments let merchants collect card data directly (subject to PCI-DSS) and transmit them via API.
- **Use cases:** Customer Initiated Transactions (CIT, buyer present), Merchant Initiated Transactions (MIT, buyer absent), Recurring (REC).
- **3DS context:** Provide `type` flag (`CIT`, `MIT`, `REC`) plus `threeDSReqAuthMethod`. CIT flows require a `browser` object and `url/urls` for potential 3DS challenges (`redirectUrl` in response). MIT/REC omit browser data and never return a challenge URL.
- **Card data:** Send within `cardData` object (`number`, `expiry`, `cvc`, `holder`). Optional `3DSecExternal` group transmits merchant-side MPI results (`xid`, `eci`, `cavv`).
- **Responses:** Successful calls mirror `start` responses; challenge responses include `redirectUrl` for shopper authentication before resuming.
- **Compliance:** Merchant must demonstrate PCI-DSS AOC, deploy SSL, display SimplePay logo, and surface clear success/failure messaging. IPN handling matches standard flows.

## 6. Shopper Communication Patterns
Provide templated messaging per outcome:
- **Success:** Display transaction ID; confirm fulfillment steps.
- **Failure:** Show transaction ID and advise customer to check entered data or consult issuer—avoid speculating on issuer decisions.
- **Cancel/Timeout:** Clarify that no charge occurred. Prevent exposing sensitive codes to shoppers.

## 7. Testing & Go-Live Checklist
- Use sandbox test cards and scenarios (including 3DS challenges using codes `1234` success, `1111` fail) before requesting certification.
- SimplePay requires advance notice (5–8 business days) to schedule final tests post-development.
- Mandatory verifications: SSL in place, Data Transmission Declaration accepted before payment start, SimplePay logo visible, successful and failed payment messaging, IPN receipt/acknowledgement, card storage testing where applicable.

## 8. Reference Materials
- Core v2.1 Guide: `https://simplepartner.hu/download.php?target=v21docen`
- Card Storage Guide: `https://simplepartner.hu/download.php?target=v21cardstoragedocen`
- Auto Payments Guide: `https://simplepartner.hu/download.php?target=v21autodocen`
- Shopper Info Brochure: `https://simplepartner.hu/download.php?target=paymentinfoen`

Always cross-check live implementations with the latest PDFs and merchant portal updates before deployment.
