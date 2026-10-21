# MagizAI Live Chat for WHMCS

An AI live-chat assistant that knows your WHMCS.

**For everyone on your site** it answers from your knowledgebase, announcements, network status, products and domain prices.

**For clients signed in to the client area** it can also:

- look up their services, domains, invoices and tickets
- tell them why a service is suspended and link the overdue invoice
- read a domain's nameservers, expiry date, auto-renew and lock status
- send a password reset email to the signed-in user's own address (and tell visitors who can't sign in how to reset it)
- **change nameservers**, turn auto-renew on or off, or (1.10.0) turn the registrar lock on or off, after the client taps Confirm in the chat
- open a ticket, reply to one or (1.8.0) close one, after the client taps Confirm in the chat
- (1.6.0) say whether a payment arrived and what it paid for, where an order is, and when a service renews, with the invoice that renews it
- (1.6.0) list the emails sent to the account, and (off until you tick it) send a welcome email or an invoice email again
- (1.6.0) open a cPanel, Plesk, DirectAdmin, InterWorx or CyberPanel control panel in one click, through WHMCS's own single sign-on
- (off by default) submit a cancellation request, or send a domain transfer code, which is never shown in the chat: it is emailed to the address on the account, or the registrar sends it to the domain's registrant contact
- (1.10.0, off until you tick each one) change a domain's contact (WHOIS) details, pay an invoice from the account credit, and run a server command you list, such as reboot, on their own service

**For your staff** (1.10.0) it adds a MagizAI bar to each ticket in your admin area: draft a reply, improve or translate your own text, and summarise the ticket. See "MagizAI on the admin ticket page" below.

**For new clients who are not signed in** (1.8.0, off until you tick it) it can open their account and place their order, then sign them in to pay. See "New clients and tickets" below.

Each night it also syncs your public knowledgebase, announcements, products and prices, and company facts (nameservers, departments, domain prices) into the assistant.

## Requirements

- **WHMCS 8.0 or newer.** Renewing a service early from the chat needs **WHMCS 8.8 or newer**, because that is the first release whose ordering API takes service renewals. On older versions the assistant points the client to the client area instead.
- **PHP 7.4 or newer**, within the PHP versions your WHMCS release supports (for example 7.4 to 8.3 on WHMCS 8.13, and 8.2 or 8.3 on WHMCS 9.0).
- A MagizAI account.

We strongly recommend **WHMCS 8.13.7 (long-term support) or 9.0.8 or newer**. Earlier builds have a serious security flaw (CVE-2026-67399) that only a WHMCS update fixes. The module keeps working on any WHMCS from 8.0, and its admin page shows a reminder while your WHMCS is on an affected build.

---

## Install (about 10 minutes)

### 1. Upload and activate

1. Upload the `modules/addons/magizai` folder into your WHMCS root, so you end up with `modules/addons/magizai/magizai.php`.
2. In WHMCS go to **System Settings → Addon Modules**, find **MagizAI Live Chat** and click **Activate**.
3. Click **Configure**, tick the admin roles that may manage it, and save.
4. Open **Addons → MagizAI Live Chat**.

### 2. Connect with a code

One code sets up everything: the chat on your client area, signed-in clients, MagizAI's connection to WHMCS and the knowledge sync. Nobody copies keys between the two systems.

In MagizAI:

1. Open **Connectors → WHMCS**. The page starts with **Connect with a code (recommended)**.
2. Choose the bot under **Chatbot to connect** and press **Create a connection code**. The code starts with `mzp_`. It is shown once, works once and expires after 30 minutes, so copy it straight away.

In WHMCS, on the module's **Setup** tab, the first card is **Fastest: connect with one code**:

1. Paste the code into **Connection code**.
2. Leave **MagizAI address** as `https://magiz.ai` unless you use a white-label address.
3. Press **Connect**.

The module sends MagizAI the code, your WHMCS System URL and this install's bridge secret (it makes one first if there is none). MagizAI keeps the address and the bridge secret on its side, and sends back the bot's widget key, its identity secret (the one it already has, so existing sign-ins keep working) and an API token for the knowledge sync. The module saves all three; the identity secret and the token are stored encrypted, like the bridge secret. MagizAI then calls this WHMCS once to test the connection, and the message you see says whether it got through.

For the code to work:

- **Your WHMCS System URL must start with `https://`** (System Settings, General Settings). MagizAI only talks to WHMCS over https.
- **This server must be able to make outgoing https requests** to MagizAI, with PHP's cURL extension.
- If the message says MagizAI could not reach this WHMCS yet, a firewall, WAF or Cloudflare rule is probably blocking unknown POST requests. Allow POST requests to `/modules/addons/magizai/bridge.php` (the full address is on the Setup tab), then press **Test connection** in MagizAI.

A used or expired code changes nothing: create a new one and paste it again. Connecting again later with a new code is safe too: the module keeps its bridge secret and takes the bot's current keys and a new API token.

### 3. Choose where the chat shows

The module adds the chat to every client-area page. To show it only to signed-in clients, change **Show the chat** on the Setup tab and press **Save**.

If you already pasted the MagizAI embed code into your WHMCS theme (or add it with a tag manager), either remove it and let the module add the widget, or keep it and set **Show the chat** to **I add the embed code myself**. Either way the module tells the assistant who is signed in. Do not choose **Nowhere** for a widget you embed yourself: Nowhere means the module adds nothing at all, so that widget treats every client as a guest.

### 4. Add the skills in MagizAI

In MagizAI, on **Connectors → WHMCS**, press **Add WHMCS skills**. This adds one skill for each thing the assistant can do:

- Answers and lookups go live straight away.
- Account changes (nameservers, auto-renew, tickets, cancellations, transfer codes) are added **switched off**. Turn on the ones you want under **Skills**.

**Test connection** on the same page names your WHMCS and module versions and imports your plans for the chat.

### 5. Teach it your business (recommended)

The code already saved an API token. On the module's **Knowledge sync** tab, choose what to sync, then click **Sync now**.

After that it syncs every night with the WHMCS daily cron. Only changed items are sent, and anything you delete in WHMCS is removed from the assistant.

### Without a code: enter the keys by hand

Use this if the code cannot work for you, for example while your System URL is not on https yet, or if this server cannot reach MagizAI. The Setup and Knowledge sync tabs hold the same settings the code fills in.

1. **The chat and signed-in clients.** In MagizAI open **Chatbots → your bot → Install**. Copy the `data-key` value from the embed code (it starts with `cv_`). On the same page, under **Signed visitor identity**, click **Generate secret**: it is shown once, so copy it now. In WHMCS, on the module's **Setup** tab, card **2. Chat widget and signed-in clients**, paste them into **Widget key** and **Identity secret**, then press **Save**.
2. **MagizAI's connection to WHMCS.** Card **1. Connect MagizAI to this WHMCS** shows a **WHMCS address** and a **Bridge secret** (press **Show** to see all of it). In MagizAI go to **Connectors → WHMCS**, paste both into the form on that page, then press **Save and test**.
3. **The knowledge sync.** In MagizAI open **Settings → API tokens** and create a token while signed in as the owner or an admin. Paste it on the module's **Knowledge sync** tab.

Then carry on from step 3 above.

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

A signed-in client can choose a plan, give a domain, and have the order placed there and then. The assistant reads back the exact plan, term, price and domain, the client taps Confirm, and the module creates the order and its invoice in WHMCS and hands back your payment link.

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

## MagizAI on the admin ticket page (1.10.0)

**A MagizAI bar on each ticket** in your admin area, from WHMCS's AdminAreaViewTicketPage hook, with four buttons:

- **Draft a reply**: a reply to the client's last message, from the ticket and your MagizAI knowledge.
- **Improve my text**: your text in the reply box made shorter, friendlier or more formal, or with its spelling and grammar fixed.
- **Translate my text**: your text in the client's language (from their WHMCS language setting, or the language of their own messages), or in one of 12 languages you pick.
- **Summarise**: a short summary of the ticket, for you to read.

A result shows under the buttons. **Apply** puts it in the reply box and **Undo** puts back what was there. Nothing is sent to the client until you send the reply yourself, and MagizAI posts nothing to the ticket from this page. Check every draft before you send it.

**Who can use it.** Staff signed in to this admin area, not disabled, and assigned to the ticket's support department. When WHMCS shows a ticket, the module gives the page a pass for that staff member and that ticket, good for four hours, so another site cannot make the request for them. At most 30 answers per staff member in ten minutes.

**What is sent, and when.** Only when a button is pressed, and only to the MagizAI workspace this module is connected to, signed with the bridge secret: the ticket's number, subject, department and status, its messages (whether the client or your team wrote each one, and the text), the client's language setting and the text in your reply box. Card numbers, private keys and anything labelled as a password, PIN, token, API key or transfer code are removed first, and MagizAI removes them again before anything reaches the AI. Staff notes are never sent, nor the name and email address WHMCS keeps with each message.

**The same AI as the MagizAI inbox.** The same prompts, the same knowledge and the same AI keys and models as the inbox's writing tools and suggested replies, and each use counts as AI usage in that workspace, as the inbox does. The workspace's plan must include connectors, as for everything WHMCS.

On for a new install and after an update. To hide it, untick **Show MagizAI on the ticket page in the admin area** on the Setup tab, under Tickets.

---

## Domains, credit and server commands (1.10.0)

Four new things a signed-in client can ask for, each confirmed with one tap on Confirm. An update switches none of them on.

| They ask | The assistant does | Default |
|---|---|---|
| "unlock my domain, I'm transferring it" | Turns the registrar lock on or off (WHMCS's DomainUpdateLockingStatus), then reads the lock back from the registrar and says what it reports, including when it still shows the old state. A lock already as asked is not sent again | On for a new install, like nameservers; off after an update |
| "change the owner email of my domain" | Changes the email, phone, name, organisation or address of the registrant, admin, tech or billing contact, or all of them (DomainGetWhoisInfo, then DomainUpdateWhoisInfo) | **Off** until you tick it (Sensitive) |
| "pay my invoice with my credit" | Applies the account credit to one of their own unpaid invoices (ApplyCredit) | **Off** until you tick it (Sensitive) |
| "reboot my VPS" | Runs a server command you list on their own active service (ModuleCustom) | **Off** until you tick it (Sensitive), and nothing is offered until you list commands |

**Contact details are the client's own words.** Only the details the client names change, written into the fields your registrar returns, and every other field goes back exactly as WHMCS read it. A field your registrar does not keep is refused, never invented. MagizAI shows every new value before the client taps Confirm, and refuses a value the client never typed in the chat. If your registrar emails the domain owner to approve a change, the change waits for that approval.

**Credit, never more than there is.** Never more than the credit on the account and never more than the invoice still owes, both read from WHMCS when it runs. MagizAI first asks the module for the amount, which changes nothing, so the client confirms that exact amount. Late fees, due dates and discounts are not changed from the chat.

**Server commands you choose.** List them in the **Server commands from the chat** card on the Actions tab, one a line: the server module's function name (`reboot`) or a module and its function (`virtualizor:reboot`). The chat can run any command you list that the service's module has, including one your module shows only to staff, so list only what you are happy for clients to run. WHMCS's standard provisioning functions, and any command that creates, suspends, terminates, deletes, reinstalls, rebuilds, wipes, restores, renews or changes a password, package or plan, are refused when you save the list and again when a command runs. A client can run at most 3 an hour by default (1 to 20, on the same card), counting the ones the server refused, and only on an active service. The chat says a command ran only when the server module reported success, and that WHMCS reports the command ran, not how the server is afterwards. Every run and every refusal is in the activity log here and in WHMCS's own.

---

## Tickets in the MagizAI inbox (1.9.0)

**Your team answers tickets from MagizAI's inbox.** Each ticket shows there as its own conversation, marked **Ticket** with its number, subject, department, status and priority, and a link to the ticket in your admin area. A reply written there is posted to the ticket as a staff reply and WHMCS emails it to the client as usual. A note is added to the ticket as a staff note. **Close** and **Reopen** change the ticket's status. If WHMCS refuses any of these, the inbox shows its reason on that message.

**On by default from 1.9.0.** "Show tickets in the MagizAI inbox" is on for a new install and for an upgrade from a version before 1.7.0. An install on 1.7.0 to 1.8.1 keeps what it stored, so if you never switched tickets on, tick the box on the Setup tab. Replies are posted as the admin chosen there, or the first active Full Administrator when none is chosen.

**Only your own workspace sees them.** Tickets are read only by the MagizAI workspace this module is connected to, over the same signed bridge as the chat, and never by the chat AI or a visitor. When MagizAI sees the setting on, it connects its inbox by itself in a mode where it posts nothing to any ticket. AI suggestions (left as private notes) and AI replies stay off until that workspace chooses them in MagizAI, Connectors, WHMCS.

**At once.** When a ticket is opened, answered, changes status or is closed, the module sends MagizAI a short signed message with the ticket number, and MagizAI reads the ticket through the bridge. It waits three seconds at most and never stops a ticket from saving: if MagizAI is down, the change is written to WHMCS's module log and MagizAI's five-minute check picks it up.

**Priority and department** (1.10.0). The inbox can also set a ticket's priority (Low, Medium, High) and move it to another of your support departments, by name; WHMCS's UpdateTicket makes the change, and a name that matches none of your departments is refused with the list.

**See whether updates get through** (1.10.0). The Setup tab shows when the last ticket update reached MagizAI, or when it did not and why (could not connect, timed out, a TLS or certificate problem, an HTTP answer such as a redirect, or an empty widget key, which stops them), with how many failed in a row. **Send a test ticket update** sends one signed test update and says what came back; no ticket is read or changed. MagizAI's connection check receives the same information. Updates need the widget key: it tells MagizAI which workspace they are for.

To switch tickets off, untick the box on the Setup tab. MagizAI can then no longer read or answer any ticket.

---

## New clients and tickets (1.8.0)

**A new client can buy in the chat.** Tick **Open an account for a new client and place their order** on the Actions tab (it is off until you do), then switch the matching skill on in MagizAI under Skills. A visitor who is not signed in and wants to buy is asked whether they already have an account. If they do, they sign in. If they are new, the assistant asks for their email address, their name and, when you charge tax, their country, shows the order, the price and your terms of service, and they tap Confirm. The module then opens the account and places the order on it.

- **Every order check runs before the account exists** (the plan is on sale, the term is sold, a domain to register is free, a payment method is on), so no account is left behind for an order that could never be placed.
- **Your own rules.** An account on its own only when "Allow Client Registration" is on; with an order, the way your cart lets a new client register at checkout. Your terms of service when "Enable TOS Acceptance" is on. Never a second account for an email address WHMCS already has: the visitor is told to sign in.
- **Their own password.** WHMCS sends its welcome email and its password reset email to the address they gave. No password is shown in the chat or sent to MagizAI.
- **Signed in once, to pay.** The new client has no password yet, so the chat shows a **Pay now** button. Tapping it asks the module for WHMCS's own single sign-on into that account, on its invoice, made at that moment (a WHMCS sign-on works once, for 60 seconds), and opens it. The button holds a token, never the sign-on itself; the sign-on is never in the transcript or seen by the AI. It works only for the account that same conversation opened, from that visitor's own chat, for two hours and five times at most.
- **Limits.** One account per conversation, and an hourly ceiling for the whole install on the Setup tab (default 5). A conversation that keeps trying addresses you already have gets a plain refusal after three tries in an hour.
- The account gets name, email, country and phone; the client completes the rest of their profile in the client area. Its notes say it was opened through live chat, and each one is recorded in its own table, which deactivating the module keeps.

**Tickets.** A signed-in client can now **close** one of their own tickets when it is sorted (tick **Close a support ticket**), never one your team marked "prevent client closure" (WHMCS 8.11 and later); a reply opens it again. And a ticket the client only described gets the first sentence as its subject, instead of being refused.

---

## Growing an account from the chat (1.4.0)

Alongside ordering, a signed-in client can now do the things that used to need a ticket:

| They ask | The assistant does |
|---|---|
| "what's the next plan up, and what would it cost?" | Lists the upgrades in the same product group with **WHMCS's own pro-rata figure** for changing today, and the new recurring price |
| "move me to Business" | Places the upgrade (or downgrade) and gives them the invoice |
| "I want to renew early" | Raises the renewal invoice for that service, when WHMCS allows an early renewal. Otherwise it gives the invoice that already covers it, or says when the next one comes |
| "renew my domain for 2 years" | Raises the domain renewal invoice |
| "what extras can I add?" / "add a dedicated IP" | Lists the addons that apply to their plan, and adds the one they pick |
| "I can't get into cPanel" | Resets the service password and **emails it** to the address on the account |
| "where do I turn on two-factor?" | Links them straight to that page of the client area |

Three rules hold across all of them:

- **Prices always come from WHMCS at the moment the action runs.** An upgrade is priced, then priced again immediately before it is ordered, so the invoice can never disagree with the figure the client just agreed to.
- **A renewal never raises a second invoice.** When an unpaid invoice already covers it, the client gets that invoice's link and nothing new is ordered. Raising a second one is a client paying twice for the same month, and nothing in the chat looks wrong when it happens. A payment that is still clearing is never offered for payment again.
- **A renewal is offered only when WHMCS will accept it**: an active service on a recurring term, on-demand renewal switched on, inside its window. A service that cannot renew early is told it renews through its next invoice, never to order the same plan again. If WHMCS refuses anyway, the renewal is not offered again for that due date.
- **A reset password is never shown in the chat.** It is generated on your server, set, and emailed to the address on the account. A domain transfer code is never shown in the chat either. A transcript is kept, is visible to every agent who opens it, and is still there a year later.

Plan changes keep the client's existing billing term. A plan that is not sold on that term is not offered as an upgrade, because moving a monthly client onto a yearly plan is a twelve-fold invoice nobody mentioned.

**Why these actions use no account auto-login link.** WHMCS can mint a token that logs somebody straight into an account. It grants full access, skips two-factor, and anything put into a chat can be read by whoever is watching that chat. These actions only run for a client who is already signed in, so a plain link does the same job with none of that. The one exception, from 1.8.0, is the new client who has no password yet: see "New clients and tickets" below for how that sign-in is made and who can use it. The one-click control panel link added in 1.6.0 is not such a token: it is WHMCS's own single sign-on into a service's control panel, it runs inside the client's existing WHMCS session in their own browser, and it does nothing for anyone else.

---

## Account answers (1.6.0)

| They ask | The assistant does |
|---|---|
| "I paid, so why does it still say unpaid?" | Reads the invoice: its status and balance, the payments recorded (date, amount, the payment method's name and only the end of its reference, at most the last six characters), and what each service or domain on it is doing now. A paid invoice whose service is still suspended is passed to your team, and another overdue invoice that may be the reason is named with its pay link |
| "my order is still pending" | Reads the client's five newest orders, or one by the number on its confirmation, and says what each is waiting for: payment (with the pay link), a payment still clearing, or setup |
| "when does it renew?" | Gives the next due date, about when WHMCS raises the next renewal invoice, the invoice that already renews it with its pay link, and whether it can be renewed early from the chat or why not. A one-time purchase is told it is never invoiced again, and a service that has ended is told no renewal invoice will come. For a domain whose hosting has ended, it answers for the domain registration |
| "I never got the welcome email" | Lists the date and subject of the emails sent to the account, and whether one failed or is waiting to go. With **Send an email again** ticked, it can send a service's welcome email or an invoice email again, to the address on the account |
| "log me into cPanel" | Gives one link that opens the control panel in the same browser, through WHMCS's own single sign-on, then the login details for whoever still needs them |
| "what do I have with you?" | Starts with the counts (open invoices, services, domains, tickets), then lists what needs attention first, and gives the credit balance to a user who may see invoices, $0.00 included |

Four rules hold across them:

- **Nothing private leaves WHMCS.** No card details, nothing a payment gateway writes about a payment, never a full payment reference, nothing from WHMCS's fraud checks (an order held for review is "being reviewed by the team"), never what an email says and never an email address it went to.
- **Only the client's own records.** Every invoice, order, service and email is read under the signed-in client, and a team member needs the same permission the client area asks for. An invoice's number and pay link, in an order, a renewal, an upgrade or an extra too, and in the answer to a change just made, go only to a team member who may see invoices.
- **Sending an email again is a change, and off until you tick it.** The client confirms it in the chat (from 1.8.1 with one tap on Confirm; in 1.6.0 with a code), WHMCS's answer is checked (a template that is switched off, or a mail server that refuses it, is never reported as sent), and it goes out at most three times an hour for an account.
- **Answers are never cut short silently.** Every list starts with its counts and stays short enough that MagizAI never has to cut it. When it leaves some out, it ends with "... and 3 more, ask about a specific one".

---

## Verification codes by email (1.7.0)

Before the chat shows a service's password, the client types a 6-digit code. (In 1.7.0 the code also confirmed every change, such as nameservers, an order or a cancellation. From 1.8.1 a change needs one tap on Confirm, and the code is only for showing a password in the chat.) Before 1.7.0 MagizAI showed that code in the chat, which proves the client meant it, not that they can read the account's mailbox. With **Send chat verification codes by email** on (the Setup tab), WHMCS emails the code instead:

- **It goes to the address on the account**, through WHMCS's own email system: the client the sign-in names, never an address typed in the chat. It is an ordinary WHMCS general email, so WHMCS also sends a copy to any contact the client set to receive general emails, as it does for every general email.
- **It uses your own email template**, "MagizAI verification code", under System Settings, Email Templates, General Messages. The module creates it in English, French and Arabic, and WHMCS adds your usual header, footer, signature and mail settings. Change the wording there. The merge fields are `{$magizai_code}` (the code), `{$magizai_expires_minutes}`, `{$magizai_action}` (what the client asked for, for example "Change the nameservers of example.com"), `{$magizai_purpose}`, `{$magizai_locale}` (the chat's language) and WHMCS's own, such as `{$client_first_name}` and `{$signature}`.
- **Your edits are never overwritten.** The template is created when it is missing: on activation, on every upgrade, by the daily cron, and by the next code sent. A template you changed is kept exactly as it is, and so is one you deleted and made again yourself. Deactivating the module leaves it in place; delete it under Email Templates if you remove the module for good.
- **Keep the code out of the subject.** The shipped subject does not carry it. If you add `{$magizai_code}` to the subject anyway, the chat's list of emails sent to the client masks every 6-digit number in a subject, so the code never comes back into the chat that way.
- **MagizAI makes the code, and keeps only a scrambled copy of it.** It travels to WHMCS inside the signed request and leaves in the email. This module never stores it, and never writes it to its own activity log, to WHMCS's activity log or to WHMCS's module log, not even when a send fails. The reply to MagizAI says only where the email went, masked (`j***@example.com`). **WHMCS's email log keeps the sent email, code included, as it keeps every email WHMCS sends**, so staff who can read the email log can read the code. The code works once and expires after 10 minutes.
- **Limits.** At most 5 codes an hour for an account, and 5 for a user. Refused in staff view: a staff member testing the chat never emails the client.
- **When the email cannot go.** If the template is switched off under Email Templates, or WHMCS does not send the email, nothing is sent and the password is not shown in the chat; the client can still have their login details emailed. The reason is written to this module's activity log and to WHMCS's activity log for staff, without the code.

With the box unticked, no code is emailed, and from 1.8.1 a password is only ever sent by email, never shown in the chat. The box is on for a new install and after an update. MagizAI uses email only once it sees a module that can send it.

---

## Signed-in clients: how the chat knows who is asking (1.6.0)

Every client-area page tells the chat who is signed in, or why nobody is. It carries a signed token for the client, or no token and a reason. The reason is a short code, never a name, an email or an account number:

| The page says | What it means | What the assistant does |
|---|---|---|
| client | A client is signed in, and the token names the account and the user | Answers about their account |
| signed_out | Nobody is signed in to the client area | Asks them to sign in for account questions |
| staff | A staff session: Login as Owner, or a WHMCS admin area session in the same browser, with staff view off | Explains that account answers need a client's own sign-in |
| staff_view | Staff view is on, and a staff member is looking at a client's account | Answers look-ups, labelled as staff view, and changes nothing |
| no_account | The user is signed in but has not chosen an account | Asks them to choose the account at the top of the page |
| account_closed | The account is closed | Points them to support |
| module_error | The module could not decide or sign (see the WHMCS module log) | Offers the team |
| not_configured | No identity secret is set in the module | Offers the team; nobody can be recognised |

The page reason only changes how the assistant words its answer. It is never treated as proof of anything: only a valid token opens an account.

### Staff sessions in WHMCS

WHMCS keeps the admin area and the client area in one browser session. Two situations count as staff:

- **Login as Owner.** An admin opens a client's profile and clicks Login as Owner.
- **An admin session in the same browser.** You are signed in to the admin area and then sign in to the client area in the same browser, even through the normal sign-in form.

In both, the chat gets no client token unless staff view is on. This is on purpose: the chat cannot tell a staff member testing an account from the client, and the client's own sign-in is what account answers and changes are tied to.

**To test account answers as a client**, open a private (incognito) window, sign in to the client area as the client, and ask there. A private window has no admin session. If the assistant still cannot see the account there, check the identity secret, and that **Show the chat** is not set to **Nowhere**.

### Staff view (off unless you switch it on)

**Staff view** on the Setup tab lets staff in either situation above see a client's services, domains, invoices and tickets through the chat on that client's pages:

- Look-ups only. Every change is refused, and so is anything that would send the client an email, such as a password reset. Login details give the username only; the password stays in the admin area.
- Every request made in staff view is recorded against the staff member, in this module's activity log and in WHMCS's own activity log.
- MagizAI treats these chats as a staff member looking at the account, not as the client.

Leave it off unless your team tests the chat this way. An upgrade never switches it on, and it is off on every new install.

### Where the chat appears

**Show the chat** on the Setup tab:

- **On every client-area page**: the module adds the widget and the sign-in.
- **Only to signed-in clients**: the same, on pages where a client is signed in with an account chosen that is not closed. This does not depend on the sign-in token: a signed-in client still gets the chat when no identity secret is set yet, or when a token could not be made, and the page says why. A staff session gets the chat here only with staff view on.
- **I add the embed code myself**: the module adds only the sign-in, for a widget in your theme or tag manager. No widget key is needed in the module for this.
- **Nowhere**: MagizAI is not used on WHMCS pages, and the module adds nothing at all, not even the sign-in.

The first two need the **Widget key**. Without one the module adds nothing to your pages, and the fresh sign-in address below answers "not available".

### Fresh sign-ins

The chat fetches a fresh token from `index.php?m=magizai&action=identity` before the one it has runs out, in the client's own browser session. It asks the address the page itself was opened on, at your WHMCS's path, so `www.` or not, and http or https, never sends it somewhere the client's session does not reach. The address answers only your own pages (no cross-site access), is never cached, and gives a token only to someone the page itself would have given one. If something on your WHMCS has already started sending the page before the module answers, it gives no token at all, because it can no longer stop the answer being cached, and the WHMCS module log says where that output came from. If a firewall or Cloudflare rule blocks it, the chat still works, and the client is asked to refresh the page when a token runs out.

### Sign-out

When a user signs out of the client area, every token issued to them before that moment is refused, in every tab and on every device. Other users of the same account stay signed in. Before WHMCS 8, which has no users, the whole account is signed out.

**Login as Owner is not the client signing out.** If WHMCS reports the end of a Login as Owner session as a sign-out, only that staff member's staff view tokens end. The client's own sign-ins on their own devices are not touched. This relies on WHMCS still saying, at that moment, that the session was Login as Owner. If a WHMCS version no longer says so by then, the owner's tokens end as for a normal sign-out, and the chat on the owner's open pages asks them to refresh the page. Before WHMCS 8 the module cannot tell a staff member's Login as Client session from the client's own, so if WHMCS reports its end as a sign-out, the account is signed out as for any other sign-out.

### This install's own signing key

Once MagizAI has said it can check one, the module makes an Ed25519 key pair that never leaves your WHMCS, and sends MagizAI the public half with each connection check (the nightly one, or **Test connection** on MagizAI's Connectors page). It starts signing tokens with that key once the public half has gone out in two connection checks at least an hour apart, so one answer lost on the way never leaves MagizAI unable to check your clients' sign-ins. An answer the module can tell will arrive broken (another module's output in front of it) does not count. Only a connection check that says it can check the key counts. Two hours after the switch the bridge stops accepting tokens signed with the shared identity secret, so a token MagizAI could have made no longer opens an account. That holds while MagizAI keeps checking in: if it has not said for 30 days that it can check the key, or no connection check has carried the key for 29 days, sign-ins go back to the shared identity secret and the bridge accepts those again, until the key has gone out twice more. **How sign-ins are signed** on the Setup tab says when this has happened. If WHMCS cannot decrypt the key for a moment, that page gets no sign-in and the key is kept; it is replaced only when WHMCS decrypts it to something that is not the key, after its encryption key changed. If you set an identity token lifetime longer than two hours, that wait is one token lifetime instead, so a page opened just before the switch keeps its sign-in until its token runs out. The key stops MagizAI making a token, not using one it was given: see **Who can sign a token** under [How it stays safe](#how-it-stays-safe). This needs PHP's sodium extension, which most PHP builds include. **How sign-ins are signed** on the Setup tab says which key is in use and why, and lets you keep the shared secret instead. **Rotate all secrets** replaces the key too.

---

## What the assistant may do

The module's **What the assistant may do** tab has the final say. An action switched off there is refused, whatever MagizAI is set to do.

| Group | Actions | Default |
|---|---|---|
| Public | knowledgebase search, announcements, network status, products and prices, domain availability, domain prices | on |
| Signed-in client: look up | account overview, services, service details, domains, domain details, invoices, invoice details, tickets, ticket details, upgrade options, extras for a service, client-area links (including the one-click control panel link), hosting accounts (Site Doctor), payment status, order status, renewal status, emails sent to the client | on |
| Signed-in client: change | change nameservers, turn auto-renew on/off, turn the registrar lock on/off (1.10.0), open a ticket, reply to a ticket, close a ticket | on in WHMCS for a new install, **off in MagizAI until you enable the skill**. An update never switches on a new change |
| Signed-in client: change, off until ticked | send a welcome email or an invoice email again | **off** in WHMCS and in MagizAI |
| Signed-in client: verification code (1.7.0) | email the client the 6-digit code they type in the chat to see a service password there (in 1.7.0 also to confirm a change) | **on**, switched on the Setup tab (**Send chat verification codes by email**), not on this tab |
| Sensitive | cancellation request, email the transfer code, place an order, upgrade or downgrade, renew a service, renew a domain, add an extra, reset a hosting password, open an account for a new client (1.8.0), change a domain's contact details, pay an invoice from the account credit, run a server command you list (1.10.0) | **off** |

**Saving the tab** (1.10.1). After **Save**, the module reads the list back from WHMCS's database and says what changed: which actions were switched on, which off, and how many are on. If what WHMCS stored is not what you ticked, a red message names the actions it did not keep. A box that did not reach WHMCS with the save is left as it was and named in a red message too. MagizAI's connection check receives the last save (`actions_last_save`: action names and counts only).

**After an update.** A new look-up action is switched on for you, however you update: by uploading over the old files (MagizAI's next request does it, even if nobody opens the admin page) or by deactivating, uploading and activating. Anything that changes an account or raises an invoice stays off until you tick it, and an action you switched off stays off.

---

## How it stays safe

**No admin credentials.** MagizAI never gets a WHMCS API key or admin login. It can only call this module's bridge, and the bridge only does the actions listed above.

**Every request is signed.** Requests carry an HMAC-SHA256 signature made with your bridge secret, plus a timestamp and a one-time nonce. Unsigned, old or replayed requests are refused. Replies are signed too, so MagizAI can tell a real answer from anything else.

**WHMCS checks who is asking.** When a client loads a client-area page, the module signs a short-lived identity token for the client account they are using and the user they signed in as. The bridge verifies that token itself before it touches any account, so a request whose sign-in is forged, expired, made for another account, or issued before the user signed out is refused. A token must name a positive client number and, on WHMCS 8 and later, a user of that account; one that does not is refused, never treated as the account owner. A token lasts 15 minutes on a new install (an install upgraded from an earlier version keeps the 60 minutes it had), and the chat fetches a fresh one before it runs out for as long as the client is still signed in. You can change **Identity token lifetime** on the Setup tab (5 to 240 minutes).

**Who can sign a token.** Until MagizAI can check this install's own key, tokens are signed with the identity secret, which MagizAI also holds: that guards against forged and expired sign-ins, not against MagizAI itself. From 1.6.0 the module makes an Ed25519 key pair of its own and gives MagizAI only the public half, which can check a token and never make one. That stops MagizAI making sign-ins, not using the ones it is given. It also holds only while MagizAI keeps checking in: after about a month without a connection check that can carry the key, sign-ins go back to the identity secret, which MagizAI holds, and the Setup tab says so. MagizAI's chat runs on your client-area pages, so it receives the token of every signed-in client who opens a page there, and can fetch a fresh one while that page stays open. A token is not used up by a request: it works until it expires (15 minutes on a new install) or the client signs out. So what you switch on under **What the assistant may do** is what MagizAI could do for a signed-in client, with this key or without it. See [Signed-in clients](#signed-in-clients-how-the-chat-knows-who-is-asking-160) above. Keep the identity secret private and use **Rotate all secrets** if it may have leaked.

**Signing out ends the chat's sign-in.** When a user signs out of the client area, every token issued to them before that moment is refused, in every tab and on every device.

**Team members see only what the owner allows.** WHMCS 8 lets an account owner give other users limited access, and the chat follows the same permissions as the client area. Viewing services needs "View Products & Services"; a service password also needs "View & Modify Product Passwords" (without it the user gets the username only); ordering, upgrades, renewals, extras, cancellations and order status need "Place New Orders/Upgrades/Cancellations"; domain changes, the registrar lock and a domain's contact details need "Manage Domain Settings"; a server command needs "View Products & Services" and "View & Modify Product Passwords"; invoices, payment status, the account credit, paying an invoice from the credit, and an invoice's number and pay link inside any other answer (an order, a renewal, an upgrade, an extra, a suspended service, or the invoice a change from the chat has just raised) need "View & Pay Invoices"; tickets need "View & Open Support Tickets"; the emails sent to the account need "View Email History"; the one-click control panel link needs "Perform Single Sign-On".

**Only their own things.** Every domain, service, invoice and ticket is looked up under the signed-in client. "Not found" and "not yours" get the same answer, so the chat can't be used to discover other clients' domains. Tickets are found by their client-facing number only.

**Changes need the client's confirmation.** The assistant can't change anything until the client taps Confirm under what it will do, within 10 minutes. Showing a password in the chat also needs a one-time code: from 1.7.0 WHMCS emails that code to the address on the account (see [Verification codes by email](#verification-codes-by-email-170)); with that switched off, a password is only ever sent by email. On top of that:

- the client's sign-in must be recent, confirmed by a client-area page or a fresh sign-in the chat fetched while they were still signed in: within 15 minutes on a new install, set on the Setup tab (an install upgraded from an earlier version keeps the 60 minutes it had)
- a client can make at most 10 changes per hour through chat (a renewal that an open invoice already covers orders nothing, so it does not count)
- the client is emailed whenever their account changes
- every request is written to the module's activity log, and every change also goes to the WHMCS activity log
- deactivating the module keeps the activity log

**Passwords are emailed unless you choose otherwise.** From 1.8.1, a client who asks for their login details is shown the username and the control panel first, with nothing sent; the password follows only when they choose Show password (after a code emailed to the address on the account) or Send by email. When a client asks for a service password by email, it is emailed to the address on the account. If you switch on showing passwords in the chat, the client can open it behind a button in their own browser, once, and only within 15 minutes of their sign-in being confirmed by a client-area page or a fresh sign-in (you can change that on the Setup tab); otherwise it is emailed instead. Login details are sent at most three times an hour for an account, and three times an hour for a user; only a request that actually emailed or showed a password counts. If WHMCS cannot send the email, the chat says so instead of claiming it was sent. Payment details are never handled, and a transfer code is never shown in the chat: it is emailed to the address on the account, or the registrar sends it to the domain's registrant contact, and the chat says which.

**Your staff-only notes stay yours.** A custom field marked "Admin Only" is never read out as a client's username or password, and only the custom fields of the client's own product are read. Hidden support departments are never chosen from a name a client types, and their names are kept out of the knowledge sync.

**Domain lookups are limited.** Each availability check asks the domain registries from your server. Checks from the chat are limited to 12 an hour in one conversation and 150 an hour across your install, and a visitor who is not signed in gets at most two other extensions checked, so a script cannot get your server's address throttled by the registries. Past a limit, the client is pointed at your store's own domain search.

**Replacing secrets.** Deactivating the module keeps a copy of its settings, secrets included, so updating by deactivating and activating again changes nothing. That also means deactivating does not replace the secrets. Use **Rotate all secrets** on the Setup tab for that, and **Forget the kept copy** if you are removing the module for good. After rotating, also revoke the old API token in MagizAI (Settings, API tokens): clearing it in WHMCS does not stop it working in MagizAI.

**Staff logged in as a client** (Login as Owner, or an admin session in the same browser) get no account answers in the chat unless you switch on staff view, and even then they can look things up and change nothing. Changes made for a client happen in the admin area, attributed to the staff member.

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
First test from a private window signed in as a client: a browser that is also signed in to the WHMCS admin area, or that used Login as Owner, is a staff session and gets no account answers unless staff view is on. Then check the identity secret is entered in the module, and that it matches the one on the bot's Install page in MagizAI. The module reports a fingerprint of its secret (never the secret itself), so MagizAI can compare the two without either being shown. If you rotated the secret in MagizAI, paste the new one here. If the Setup tab says a secret "can no longer be read", WHMCS's own encryption key has changed (after a server move, for example): enter the secret again. If the widget is in your theme, set **Show the chat** to **I add the embed code myself**, not **Nowhere**.

**"WHMCS could not record this request"**
The module could not write to its own replay-protection table. Open **Addons → MagizAI Live Chat** once so its tables are checked, and check the database is healthy.

**Sync says HTTP 401 or 403**
The API token is wrong, or the user who created it can't manage content. Create a new token as the owner or an admin.

**Sync says HTTP 404**
The widget key doesn't match a chatbot in the account that owns the API token.

---

## Files

```
modules/addons/magizai/
  magizai.php        WHMCS's entry points: activation, upgrade, the admin page, the sign-in route
  hooks.php          registers the chat widget, the sign-out hooks and the nightly jobs
  bridge.php         the signed endpoint MagizAI calls
  lib/Action/        one file for each thing the chat can do (lib/Action/<Area>/<Name>.php), the
                     list of them, and the checks every one passes first: sign-in, permissions,
                     hourly limits
  lib/Domain/        the rules the actions share: whose service, domain, invoice or order it is,
                     prices, renewals, upgrades, login details, the control panel
  lib/Support/       reading what the chat sends, dates, money, billing terms, WHMCS's API,
                     support departments
  lib/Http/          the bridge from request to signed reply, the ping, fresh sign-ins for the chat,
                     ticket events, and the ticket page panel's requests
  lib/Identity/      who is signed in on a page, how sign-ins are signed and checked, sign-outs
  lib/Hooks/         the chat widget on client-area pages, sign-outs, the nightly jobs, ticket
                     changes, and the MagizAI panel on the admin ticket page
  lib/Staff/         tickets in the MagizAI inbox, and the ticket page panel: who may use it, what
                     is sent, and what is removed first
  lib/Admin/         the admin page and its tabs
  lib/Install/       the module's tables, the verification code email template, activation,
                     deactivation and the upgrade step
  lib/Persistence/   replay protection, the activity log, the copy of your settings kept through
                     deactivation
  lib/Settings.php   encrypted settings
  lib/Crypto.php     request signing and identity tokens
  lib/Sync.php       knowledge sync
  lib/Rollout.php    switches on new look-up actions after an update, once
  lib/Updates.php    the daily "new version available" check
  lib/Compat.php, Mailer.php, Schema.php, Summary.php, Text.php
                     WHMCS versions and prices, emails to clients, which tables exist, short
                     answers, escaping
  lib/Actions.php, Store.php, Widget.php
                     the names earlier versions used, kept so nothing that calls them breaks
```

Upload the whole `magizai` folder, with every file and folder inside it. The module is made of many
small files under `lib/`, and an action whose file is missing cannot answer. Upload the `lib` folder
first, then `bridge.php`, `hooks.php` and `magizai.php`: once `lib` is in place, the earlier version's
three files keep working with it until they are replaced. If an upload stops part way, upload the
whole folder again.

Deactivating the module drops its replay-protection, sync and identity-counter tables but keeps `mod_magizai_audit` (the activity log) and `mod_magizai_revocations` (recent sign-outs, kept for a day).
