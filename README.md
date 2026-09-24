# MagizAI Live Chat for WHMCS

An AI live-chat assistant that knows your WHMCS.

**For everyone on your site** it answers from your knowledgebase, announcements, network status, products and domain prices.

**For clients signed in to the client area** it can also:

- look up their services, domains, invoices and tickets
- tell them why a service is suspended and link the overdue invoice
- read a domain's nameservers, expiry date, auto-renew and lock status
- send a password reset email to the signed-in user's own address (and tell visitors who can't sign in how to reset it)
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

## Your plans as cards in the chat (1.2.0)

When a visitor asks which plan suits them, the assistant shows your real plans as cards: the price for the best-value term, what that works out to per month, the saving against paying monthly, the main features, and an **Order now** button that opens that term in your cart.

It reads everything from WHMCS: product name and group, every billing term you have enabled, setup fees, stock, and the feature list from the product description. Hidden or retired products never appear. Features are taken from a bulleted list (`<li>`) or from one feature per line in the description, so a short, one-line-per-feature description gives the best cards.

MagizAI refreshes the plans once a day and whenever you save the WHMCS connection. Change a price in WHMCS and the cards follow by the next morning.

Upgrading from 1.1.0: upload the new files over the old ones. There are no settings to change.

---

## Site Doctor ownership check (1.3.0)

If you use MagizAI's Site Doctor (the agent that finds out why a website is broken), the module answers one more question: **which hosting accounts does the signed-in client own?**

MagizAI asks before it inspects anything. A client can only have their own websites checked, including addon domains and subdomains under their account. A domain on somebody else's account is refused, even when it sits on the same server.

The answer holds only the domain, the system username, the server and the status of each account. No passwords, no usage, no billing. It is never shown to the assistant or to the client: it only decides yes or no.

It appears as **Hosting accounts (Site Doctor ownership check)** in the actions tab and is on after the upgrade. Switch it off and site checks from chat stop.

Upgrading from 1.2.0: upload the new files over the old ones, then open the module once so the upgrade runs.

---

## Ordering from the chat (1.4.0)

A signed-in client can choose a plan, give a domain, and have the order placed there and then. The assistant reads back the exact plan, term, price and domain, the client confirms with a one-time code, and the module creates the order and its invoice in WHMCS and hands back your payment link.

**The assistant never takes money.** No card details are asked for or handled in the chat. The client pays your invoice on your own website with your own gateway, exactly as they would from the cart.

What the module checks before anything is created, every time:

- the plan is one you sell **right now**, at the price WHMCS holds right now — never a price quoted earlier in the conversation;
- a plan name that matches two products is a question back to the client, not a guess;
- a domain being registered is actually free, so nobody is invoiced for a name somebody else owns;
- a client who already owns their domain is not sold a registration they do not need;
- the invoice is raised against a payment method you have switched on.

The order is created exactly as the cart would create it, so your welcome emails, provisioning and automation run unchanged.

It appears as **Place an order for a plan and domain** in the actions tab and is **off after the upgrade**: an upgrade must never start creating invoices for a host who has not read what it does. Switch it on there, then switch the matching skill on in MagizAI under Skills.

Upgrading from 1.3.0: upload the new files over the old ones, then open the module once so the upgrade runs.

---

## Growing an account from the chat (1.4.0)

Alongside ordering, a signed-in client can now do the things that used to need a ticket:

| They ask | The assistant does |
|---|---|
| "what's the next plan up, and what would it cost?" | Lists the upgrades in the same product group with **WHMCS's own pro-rata figure** for changing today, and the new recurring price |
| "move me to Business" | Places the upgrade (or downgrade) and gives them the invoice |
| "I want to renew early" | Raises the renewal invoice for that service |
| "renew my domain for 2 years" | Raises the domain renewal invoice |
| "what extras can I add?" / "add a dedicated IP" | Lists the addons that apply to their plan, and adds the one they pick |
| "I can't get into cPanel" | Resets the service password and **emails it** to the address on the account |
| "where do I turn on two-factor?" | Links them straight to that page of the client area |

Three rules hold across all of them:

- **Prices always come from WHMCS at the moment the action runs.** An upgrade is priced, then priced again immediately before it is ordered, so the invoice can never disagree with the figure the client just agreed to.
- **A renewal is refused when an unpaid invoice already covers it**, and the client is pointed at that invoice instead. Raising a second one is a client paying twice for the same month, and nothing in the chat looks wrong when it happens.
- **A password is never shown in the chat.** It is generated on your server, set, and emailed to the address on the account — the same rule as the domain transfer code. A transcript is kept, is visible to every agent who opens it, and is still there a year later.

Plan changes keep the client's existing billing term. A plan that is not sold on that term is not offered as an upgrade, because moving a monthly client onto a yearly plan is a twelve-fold invoice nobody mentioned.

**Why there is no auto-login link.** WHMCS can mint a token that logs somebody straight into an account. It grants full access, skips two-factor, and anything put into a chat can be read by whoever is watching that chat. These actions only run for a client who is already signed in, so a plain link does the same job with none of that.

---

## What the assistant may do

The module's **What the assistant may do** tab has the final say. An action switched off there is refused, whatever MagizAI is set to do.

| Group | Actions | Default |
|---|---|---|
| Public | knowledgebase search, announcements, network status, products and prices, domain availability, domain prices | on |
| Signed-in client: look up | account overview, services, service details, domains, domain details, invoices, invoice details, tickets, ticket details, upgrade options, extras for a service, client-area links, hosting accounts (Site Doctor) | on |
| Signed-in client: change | change nameservers, turn auto-renew on/off, open a ticket, reply to a ticket | on in WHMCS, **off in MagizAI until you enable the skill** |
| Sensitive | cancellation request, email the transfer code, place an order, upgrade or downgrade, renew a service, renew a domain, add an extra, reset a hosting password | **off** |

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
