# Getting the customer's real email/phone: Checkatrade webhook and Yelp via Zapier

The notification emails from Yelp, Yell and Checkatrade do **not** contain the customer's email or phone
(verified on the whole mailbox). These two official routes do.

## 1. Checkatrade lead delivery webhook (real customer email + phone)

Docs: https://developers.checkatrade.com/reference/leads - available to "strategic accounts"; enabled by the
Checkatrade account manager.

Plugin side (already built): `POST https://<site>/wp-json/ppg/v1/checkatrade`

* wp-config.php: `define( 'PPG_ENABLE_CHECKATRADE_WEBHOOK', true );` (route is off by default)
* Authentication is by source IP only. Allowed: Checkatrade's published production IPs
  (35.246.15.126, 35.242.187.239, 34.39.18.129). The test IPs (34.105.162.121, 34.89.85.173, 35.234.151.94)
  are accepted only when `WP_ENVIRONMENT_TYPE` is not `production` (so set `staging` on the staging site).
* The live site is on Hostinger/LiteSpeed with no CDN in front, so the real client IP is `REMOTE_ADDR`.
  If a proxy is ever added, set `PPG_CLIENT_IP_HEADER` (e.g. `HTTP_CF_CONNECTING_IP`).
* Reply: HTTP 201 on success (also for a repeated delivery, so Checkatrade does not retry). Any HubSpot failure
  returns an error so Checkatrade can retry. Duplicates are prevented by `jobId`.
* Mapping: customerEmail -> email (contact matched on it), customerPhone -> phone, postcode -> zip,
  jobDescription -> "How can we help?", job details -> Message + timeline note, "Lead Source" = Checkatrade.

Schema (https://developers.checkatrade.com/reference/post_leads): Checkatrade POSTs JSON to our URL. Required
fields: jobId, category, title, jobDescription, customerName, **customerEmail, customerPhone**, dateCreated,
source (REQUEST_A_QUOTE | DIRECT_MESSAGE | QUOTE_REQUEST), postcode. Optional: preferredStart {option, date},
additionalInformation [{questionId, question, answerId, answer}], leadEnrichment {questions:[{title, format,
answers:[{key, value, url}]}]}, media [urls]. We must answer 201. Authentication, retries and timeouts are not
documented, and the page does not say outright that the listed IPs are the sending addresses: confirm both on
the first test delivery.

Steps: send Checkatrade the staging URL first, ask for a test payload, check it arrives in HubSpot, then give
them the production URL.

## 2. Yelp via Zapier (Yelp Leads trigger)

Yelp's Leads API is for reseller partners; for everyone else Yelp recommends Zapier
(https://docs.developer.yelp.com/docs/leads-api). Yelp covers the Zapier task cost for Yelp Leads workflows.
Needs a claimed Yelp business with "Request a Quote" on. Whether a UK account gets the trigger, and exactly
which fields it returns, is UNVERIFIED until the Zap is built.

Zap: **Trigger** Yelp Leads > New Lead. **Action** Webhooks by Zapier > POST
`https://<site>/wp-json/ppg/v1/lead`, payload type JSON, header `X-PPG-Secret: <PPG_LEAD_SECRET>`.

| JSON key | Value from the Yelp trigger |
|---|---|
| source | `yelp` (fixed) |
| lead_id | the Yelp lead id (the plugin adds the `yelp:` prefix) |
| name | the customer's display name |
| masked_email | Yelp's temporary (masked) email, if the trigger provides it |
| masked_email_expiry | its expiry (Yelp: `temporary_email_address_expiry`) |
| phone | only if the trigger provides one |
| message | the request text / service |
| description | the customer's own words |

What Yelp's Zapier integration provides (https://docs.developer.yelp.com/docs/leads-api-zapier-integration):
a masked email "always" for a lead ("functions much like a regular email address", mail to it reaches the
customer's Yelp Inbox); the customer's REAL email is never exposed ("There is no option or plans to expose
these"); a real, unmasked phone in `phone_number` (`temporary_phone_number` in the meantime) only if the customer
opted in and verified it, which can take a few minutes; the project questions and answers as individual fields.
Requirements: Yelp business account, Request a Quote enabled, Zapier account.

**Second Zap for the late phone number:** Trigger Yelp Leads > Phone Availability, Action Webhooks by Zapier POST
to the same `/lead` URL and header with JSON `source=yelp`, `update=true`, `lead_id=<same Yelp lead id>`,
`phone=<phone_number or temporary_phone_number>`. Update mode only adds to the existing contact (matched by
`ppg_lead_id`): it never creates a contact, never changes the name/message, adds no second note, and answers 404
if the lead is not in HubSpot yet (retry).

The masked email is a **relay that expires after about 30 days**. It is stored only in the HubSpot properties
`yelp_masked_email` and `yelp_masked_email_expires` (create them as single-line text), never in the Email field.
If those properties do not exist the lead is still imported without them.

If the Zap's lead id equals the id in Yelp's lead-page URL (`/leads_center/<business>/leads/<id>`), the email
poller and the Zap describe the same lead and HubSpot gets one contact. Check this on the first test lead.
