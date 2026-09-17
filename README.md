# MagizAI Live Chat for WHMCS

An AI live-chat assistant that knows your WHMCS.

**For everyone on your site** it answers from your knowledgebase, announcements, network status, products and domain prices.

**For clients signed in to the client area** it can also:

- look up their services, domains, invoices and tickets
- tell them why a service is suspended and link the overdue invoice
- read a domain's nameservers, expiry date, auto-renew and lock status
- **change nameservers**, turn auto-renew on or off, open a ticket or reply to one — after the client confirms in chat with a one-time code
- (off by default) submit a cancellation request, or email a domain transfer code to the address on the account

Each night it also syncs your public knowledgebase, announcements, products and prices, and company facts (nameservers, departments, domain prices) into the assistant.

Requirements: WHMCS 8.0 or newer, PHP 7.4 or newer, and a MagizAI account.

---

## Install (about 10 minutes)

### 1. Upload and activate

1. Upload the `modules/addons/magizai` folder into your WHMCS root, so you end up with `modules/addons/magizai/magizai.php`.
2. In WHMCS go to **System Settings → Addon Modules**, find **MagizAI Live Chat** and click **Activate**.
3. Click **Configure**, tick the admin roles that may manage it, and save.
4. Open **Addons → MagizAI Live Chat**.

### 2. Put the chat on your client area

In MagizAI:

1. **Chatbots → your bot → Install**. Copy the `data-key` value from the embed code (it starts with `cv_`).
2. On the same page, under **Signed visitor identity**, click **Generate secret**. It is shown once, so copy it now.

In WHMCS, on the module's **Setup** tab, paste the **Widget key** and **Identity secret**, then save.

If you already pasted the MagizAI embed code into your WHMCS theme, remove it. The module adds the widget itself, and it also tells the assistant who is signed in.

### 3. Let MagizAI talk to WHMCS

On the module's **Setup** tab you'll see a **WHMCS address** and a **Bridge secret**.

In MagizAI go to **Connectors → WHMCS**, paste both values, then click **Save and test**. You should see "Connected to WHMCS 8.x (module 1.0.0)".

Then click **Add WHMCS skills to the assistant**. This adds one skill for each thing the assistant can do:

- Answers and lookups go live straight away.
- Account changes (nameservers, auto-renew, tickets, cancellations, transfer codes) are added **switched off**. Turn on the ones you want under **Skills**.

### 4. Teach it your business (recommended)

1. In MagizAI: **Settings → API tokens**. Create a token while signed in as the owner or an admin.
2. In WHMCS, on the module's **Knowledge sync** tab, paste the token, choose what to sync, then click **Sync now**.

After that it syncs every night with the WHMCS daily cron. Only changed items are sent, and anything you delete in WHMCS is removed from the assistant.

---

## What the assistant may do

The module's **What the assistant may do** tab has the final say. An action switched off there is refused, whatever MagizAI is set to do.

| Group | Actions | Default |
|---|---|---|
| Public | knowledgebase search, announcements, network status, products and prices, domain availability, domain prices | on |
| Signed-in client: look up | account overview, services, service details, domains, domain details, invoices, invoice details, tickets, ticket details | on |
| Signed-in client: change | change nameservers, turn auto-renew on/off, open a ticket, reply to a ticket | on in WHMCS, **off in MagizAI until you enable the skill** |
| Sensitive | cancellation request, email the transfer code | **off** |

---

## How it stays safe

**No admin credentials.** MagizAI never gets a WHMCS API key or admin login. It can only call this module's bridge, and the bridge only does the actions listed above.

**Every request is signed.** Requests carry an HMAC-SHA256 signature made with your bridge secret, plus a timestamp and a one-time nonce. Unsigned, old or replayed requests are refused. Replies are signed too, so MagizAI can tell a real answer from anything else.

**WHMCS checks who is asking, not MagizAI.** When a client loads a client-area page, the module signs a short-lived identity token (HS256, default 60 minutes) for the client account they are using. The bridge verifies that token itself before it touches any account. A compromised MagizAI still couldn't act for a client who hasn't signed in to your WHMCS.

**Only their own things.** Every domain, service, invoice and ticket is looked up under the signed-in client. "Not found" and "not yours" get the same answer, so the chat can't be used to discover other clients' domains. Tickets are found by their client-facing number only.

**Changes need the client's confirmation.** The assistant can't change anything until the client types a one-time code from the chat, within 10 minutes. On top of that:

- the client must have loaded a client-area page recently (default 60 minutes)
- a client can make at most 10 changes per hour through chat
- the client is emailed whenever their account changes
- every request is written to the module's activity log, and every change also goes to the WHMCS activity log
- deactivating the module keeps the activity log

**Nothing secret goes into the chat.** No passwords, service usernames, payment details or transfer codes. A transfer code is only ever emailed to the address on the account.

**Staff logged in as a client** get the widget without an identity, so changes made that way happen in the admin area and are attributed to the staff member.

Secrets (bridge secret, identity secret, API token) are stored encrypted with WHMCS's own encryption.

---

## Troubleshooting

**"The MagizAI module was not found on that WHMCS"**
Check that `modules/addons/magizai/bridge.php` exists and the module is activated. If WHMCS is in a subfolder, include the subfolder in the WHMCS address in MagizAI.

**"WHMCS answered, but not with a signed reply from the MagizAI module"**
A firewall, WAF or Cloudflare rule is probably answering instead of WHMCS. Allow POST requests to `/modules/addons/magizai/bridge.php`. Also check that the bridge secret matches in both places.

**"The request timestamp is too far from this server's clock"**
The WHMCS server's clock is wrong. Turn on NTP.

**The assistant treats signed-in clients as guests**
Check the identity secret is entered in the module, and that it matches the one on the bot's Install page in MagizAI. If you rotated the secret in MagizAI, paste the new one here.

**Sync says HTTP 401 or 403**
The API token is wrong, or the user who created it can't manage content. Create a new token as the owner or an admin.

**Sync says HTTP 404**
The widget key doesn't match a chatbot in the account that owns the API token.

---

## Files

```
modules/addons/magizai/
  magizai.php      activation, upgrade, admin page
  hooks.php        chat widget + identity on client-area pages; nightly sync
  bridge.php       the signed endpoint MagizAI calls
  lib/Actions.php  every action and its ownership checks
  lib/Crypto.php   request signing and identity tokens
  lib/Settings.php encrypted settings
  lib/Store.php    replay protection, activity log, sync state
  lib/Sync.php     knowledge sync
  lib/Widget.php   widget embed and signed identity
```

Deactivating the module drops its replay-protection and sync tables but keeps `mod_magizai_audit` (the activity log).
