# Changelog

MagizAI Live Chat for WHMCS. Newest first.

Upgrading is always the same: upload the new files over the old ones, then open
**Addons → MagizAI Live Chat** once so the upgrade step runs. Your settings, your bridge secret and
your action choices are kept.

Two rules hold across every release. **An upgrade never switches on anything that changes a
client's account or creates an invoice.** Those stay off until you tick them yourself. And **the
module never holds a WHMCS admin API key.** It answers a narrow signed endpoint that refuses old and
replayed requests, and checks each client's sign-in token itself, so a forged or expired sign-in is
refused. While sign-ins are signed with the shared identity secret, that is not a guard against
MagizAI itself: MagizAI holds the same secret. From 1.6.0, once MagizAI can check it, sign-ins are
signed with a key only your WHMCS holds, so MagizAI can no longer make one while it keeps checking in.
It can still use a sign-in it was given until that sign-in expires. Either way, keep the identity secret private and use
**Rotate all secrets** if it may have leaked.

---

## 1.10.0

Upload the new files over the old ones, then open **Addons → MagizAI Live Chat** once. The four new
actions are changes, so the update switches none of them on: tick each one on the Actions tab when you
want it. The ticket page panel is on after the update; untick it on the Setup tab to hide it.

### MagizAI on the admin ticket page

- **A MagizAI bar on each ticket in your admin area**, from WHMCS's AdminAreaViewTicketPage hook, with
  four buttons: **Draft a reply** (from the ticket and your MagizAI knowledge), **Improve my text**
  (shorter, friendlier, more formal, or fix spelling and grammar), **Translate my text** (into the
  client's language, from their WHMCS language setting or their own messages, or into one of 12
  languages) and **Summarise**.
- **You stay in charge.** A result shows under the buttons. **Apply** puts it in the reply box and
  **Undo** puts back what was there. Nothing is sent to the client until you send the reply yourself,
  and nothing is posted to the ticket by MagizAI from this page.
- **Who can use it.** Staff signed in to this admin area, not disabled, who are assigned to the ticket's
  support department. The page hands the panel a pass for that staff member and that ticket, good for
  four hours, so another site cannot make the request for them. At most 30 answers per staff member in
  ten minutes.
- **What is sent, and only when a button is pressed.** To the MagizAI workspace this module is
  connected to, signed with the bridge secret: the ticket's number, subject, department and status, its
  messages (client or staff, and the text), the client's language setting and your draft. Card numbers,
  private keys and anything labelled as a password, PIN, token, API key or transfer code are removed
  first. Staff notes are never sent, nor the name and email address WHMCS keeps with each message.
  MagizAI uses the same AI as its own inbox, and each use counts as AI usage in that workspace, as the
  inbox does.
- The Setup tab has the switch: **Show MagizAI on the ticket page in the admin area** (on).

### Ticket updates you can see

- **The Setup tab says how ticket updates are getting on.** Under Tickets: when the last update reached
  MagizAI, or when it did not and why ("could not connect", "timed out", a TLS or certificate problem,
  an HTTP status such as a redirect, or that the widget key is empty, which stops them), and how many
  failed in a row. Before, a failed update was written only to WHMCS's module log.
- **Send a test ticket update.** A new button on the Setup tab sends one signed test update to MagizAI
  and says what came back, in plain words. No ticket is read or changed.
- MagizAI's connection check now receives the same information (`ticket_events_health` in the ping),
  with the MagizAI address the updates go to.
- **Priority and department from the MagizAI inbox.** The inbox can set a ticket's priority (Low,
  Medium, High) and move it to another of your support departments, by name, with WHMCS's
  UpdateTicket. Like Close and Reopen, only while tickets are switched on, and every change is in the
  activity log. Nothing already as asked is sent again.

### Domains: the registrar lock and contact details

- **Turn the registrar lock on or off** (Actions tab, Signed-in client: change). The chat reads the lock
  back from the registrar afterwards and says what the registrar reports, including when it still
  shows the old state. A lock that is already as asked is not sent again.
- **Change a domain's contact (WHOIS) details** (Actions tab, Sensitive, off until you tick it): the
  email, phone, name, organisation or address of the registrant, admin, tech or billing contact, or all
  of them. Only the details the client names change, written into the fields your registrar returns;
  every other field goes back exactly as WHMCS read it. A field your registrar does not keep is refused,
  never invented. MagizAI shows every new value before the client taps Confirm, and refuses a value the
  client never typed in the chat.
- Both need the team member's "Manage Domain Settings" permission, as in the client area.

### Pay an invoice from the account credit

- **Pay an invoice from the account credit** (Actions tab, Sensitive, off until you tick it), with
  WHMCS's ApplyCredit. Only the signed-in client's own unpaid invoice, never more than the credit on the
  account and never more than the invoice still owes, both read from WHMCS when it runs. The chat first
  asks the module for the amount, which changes nothing, so the client confirms that exact amount.
- Needs "View & Pay Invoices". Late fees, due dates and discounts are not changed from the chat.

### Server commands for VPS and server hosts

- **Run a server command** (Actions tab, Sensitive, off until you tick it): a client can run one of the
  server module commands you list, such as reboot, boot or shutdown, on their own active service, with
  WHMCS's ModuleCustom, after they tap Confirm.
- **Nothing is offered until you list commands** in the new **Server commands from the chat** card on
  the Actions tab, one a line (`reboot`, or `virtualizor:reboot` for one module only). WHMCS's standard
  provisioning functions, and any command that creates, suspends, terminates, deletes, reinstalls,
  rebuilds, wipes, restores, renews or changes a password, package or plan, are refused there and when a
  command runs.
- **At most 3 an hour for one client** by default (1 to 20, on the same card), counting the ones the
  server refused. Every run and every refusal goes to the activity log here and to WHMCS's own.
- **The answer is WHMCS's.** The chat says a command ran only when the server module reported success,
  and that WHMCS reports that the command ran, not how the server is afterwards. A failure is said as a
  failure, with the server's own words when they name nothing internal.
- Needs "View Products & Services" and "View & Modify Product Passwords".

---

## 1.9.0

Upload the new files over the old ones, then open **Addons → MagizAI Live Chat** once.

### Tickets in the MagizAI inbox, switched on

- **On by default.** "Show tickets in the MagizAI inbox" (Setup tab) is now on for a new install and
  for an upgrade from a version before 1.7.0, which had no such setting. An install on 1.7.0 to 1.8.1
  keeps what it stored: if you never switched tickets on, tick the box on the Setup tab. Tickets are
  shared only with the MagizAI workspace this module is connected to. MagizAI connects its inbox by
  itself when it sees the setting on, in a mode where it posts nothing to any ticket. AI suggestions
  (private notes) and AI replies stay off until that workspace chooses them in MagizAI.
- **Replies go out as a real admin.** The admin chosen on the Setup tab, or, when none is chosen, the
  first active Full Administrator. A reply is never posted as nobody.
- **At once, not every five minutes.** The module now tells MagizAI when a ticket is opened, when the
  client or staff reply, when its status changes and when it is closed (WHMCS's TicketOpen,
  TicketOpenAdmin, TicketUserReply, TicketAdminReply, TicketStatusChange and TicketClose hooks).
  The message is signed with the bridge secret and carries only the ticket number and what
  happened; MagizAI then reads the ticket through the bridge. It waits three seconds at most, never
  stops a ticket from saving when MagizAI is down, and only writes to WHMCS's module log. MagizAI's
  five-minute check stays as the backup.
- **Every ticket, every reply.** Replies typed in WHMCS admin now reach the inbox (the check used to
  read only tickets waiting for a reply), and tickets in every department are read, not only the
  departments of the admin the API runs as.
- **Close and Reopen from the inbox** set the ticket's status in WHMCS, and any of your statuses,
  custom ones included, can be set. Notes written in the inbox are added to the ticket as staff
  notes. When WHMCS refuses a reply, a note or a status, its own words go back to the inbox, so the
  failure shows on the message.
- The inbox shows each ticket's number, subject, department, status and priority, and links to the
  ticket in your admin area (your own admin folder name is used when configuration.php sets one).

---

## 1.8.1

Upload the new files over the old ones, then open **Addons → MagizAI Live Chat** once. Nothing is
switched on or off by the upgrade.

### Login details, in the order clients expect

- **The username first, without the password.** When a signed-in client asks for their login details,
  the chat now shows the username, the control panel address and its one-click sign-in straight away,
  and sends nothing. The password follows only when the client chooses: **Show password** (after a
  6-digit code emailed to the address on the account; on the Setup tab, tick "Show a service password
  in the chat window" and keep "Send chat verification codes by email" on) or **Send by email**.
  Nothing new to switch on: the login details action does it.
- MagizAI now confirms every other change made from the chat with one tap on Confirm. Only showing a
  password in the chat needs the emailed code.

---

## 1.8.0

Upgrading works as always: upload the new files over the old ones, then open **Addons → MagizAI Live
Chat** once. Nothing here is switched on by the upgrade: the three new actions are changes, and wait
for you to tick them on the Actions tab.

### New clients can buy in the chat

- **Open an account for a new client and place their order** (Actions tab, Sensitive, off until you
  tick it). A visitor who is not signed in and has no account can buy in the chat: they give their
  name, email address and, when you charge tax, their country; the chat shows the order, the price
  and, when you require it, your terms of service, and they confirm. The module then opens the
  account and places the order on it. Every order check runs first (the plan is on sale, the term is
  sold, a domain to register is free, a payment method is on), so no account is left behind for an
  order that could never be placed.
- **Your own rules, nothing looser.** An account on its own only when "Allow Client Registration" is
  on; with an order, the way your cart lets a new client register at checkout. Never a second account
  for an email address WHMCS already has: the visitor is told to sign in. The account gets name,
  email, country and phone; the client completes the rest of their profile in the client area. The
  client notes say it was opened through live chat.
- **Their own password.** WHMCS sends its welcome email and its password reset email, so the client
  chooses a password themselves. No password is ever shown in the chat or sent to MagizAI.
- **Signed in once, to pay.** The chat's "Pay" link signs the new client in to their invoice with
  WHMCS's own single sign-on, made at the moment they tap it (a WHMCS sign-on link works once, for 60
  seconds). Only for an account that same conversation opened, in the last two hours, five times at most.
- **Limits.** One account per conversation, and an hourly ceiling for the whole install (Setup tab,
  "New accounts the chat may open per hour", default 5). A conversation
  that keeps trying addresses you already have gets a plain refusal after three tries in an hour.
  Accounts opened from the chat are recorded in their own table, which deactivating the module keeps.

### Tickets

- **Close a support ticket** (Actions tab). A signed-in client can close one of their own tickets when
  it is sorted. Never a ticket your team marked "prevent client closure" (WHMCS 8.11 and later). A
  reply opens it again, as in the client area. Needs the "View & Open Support Tickets" permission.
- **A ticket with no subject is no longer refused.** A client who only said what is wrong gets the
  first sentence of it as the subject, the same rule MagizAI uses, so the ticket is titled as the client
  confirmed it.

### Confirmations

- The Actions tab no longer says every change is confirmed "with a code": MagizAI now confirms an
  order, a renewal and a ticket with one tap on Confirm, and keeps the code for the riskier changes.

---

## 1.7.0

Upgrading works as always: upload the new files over the old ones, then open **Addons → MagizAI Live
Chat** once. Nothing here changes an account, raises an invoice or switches on an action you switched
off.

### Connect with a code

- **Setup in one paste.** In MagizAI, Connectors, WHMCS, create a connection code for a chatbot, then
  paste it on the Setup tab under "Connect with a code". The module trades it with MagizAI for every
  setting it needs: the chat on your WHMCS, client sign-ins and the knowledge sync. Nobody copies
  keys between the two systems any more.
- **What goes out, and what comes back.** The module sends the code, this WHMCS's System URL, its
  bridge secret (made first if there is none) and the module version, over https only. MagizAI
  answers with the widget key, the identity secret and a token for the knowledge sync, and says
  whether it could reach the bridge. A code works once and expires 30 minutes after it was created;
  a used or expired code changes nothing here.
- **Needs https.** Your System URL must start with https://, and the MagizAI address must be an https
  address with no path.

### Tickets in the MagizAI inbox

- **Off until you switch it on**, on the Setup tab under "Tickets in the MagizAI inbox", together with
  the WHMCS admin that replies are posted as (it must be an active admin). Then switch tickets on in
  MagizAI as well: Connectors, WHMCS, Tickets.
- **Through the signed bridge, not an API key.** MagizAI reads tickets awaiting a reply and their
  threads, posts the replies your team writes in the MagizAI inbox back to the ticket as the chosen
  admin, and can add internal notes. It cannot open, close, delete or reassign tickets.
- **Every call is recorded** in the module's activity log, and the bridge refuses ticket calls while
  the setting is off.

### Verification codes by email

- **The code a client types in the chat now comes from WHMCS, by email.** Before the chat changes
  something on an account or shows login details, the client types a 6-digit code. MagizAI used to
  show it in the chat, which proves the client meant it, not that they can read the account's
  mailbox. A new action, `verification_send`, has WHMCS email the code to the address on the account
  of the signed-in client, never to an address typed in the chat.
- **Your own email template.** The module creates "MagizAI verification code" under System Settings,
  Email Templates, General Messages, in English, French and Arabic, on activation, on every upgrade,
  in the daily cron and on the next send when it is missing. WHMCS adds your header, footer and
  signature. A template you edited is never overwritten. The subject does not carry the code.
- **A new setting on the Setup tab**, "Send chat verification codes by email". On for new installs
  and after this update. With it off, the code is shown in the chat as before. MagizAI switches to
  email only once it sees a module that can send it, and decides what happens when a send fails.
  The Actions tab shows this action without a checkbox: the Setup tab switches it.
- **The code is never kept by this module.** It is not stored, and it is not written to the
  module's activity log, WHMCS's activity log or WHMCS's module log, a failed send included. WHMCS's
  email log keeps the sent email, as it does for every email. The reply to MagizAI says only where
  the email went, masked.
- **Limits.** At most 5 codes an hour for an account and for a user. Refused in staff view.
- **Email history never reads a code back.** If a merchant adds the code to the template's subject,
  the list of emails sent to the client masks every 6-digit number in a subject.
- The connection check reports the new feature (`verification_email`), whether the setting is on and
  the state of the template, so MagizAI's connection test can say why email is not in use.

---

## 1.6.0

Not released yet. Upgrading works as always: upload the new files over the old ones, then open
**Addons → MagizAI Live Chat** once. Nothing here changes how an existing install behaves unless it
says so.

### How the module is built

- The module is now made of small files: one for each thing the chat can do, and one for each rule
  they share, under `lib/`. Nothing the chat says or does changes. Every answer, the admin page and
  every reply of the bridge were recorded before the change and checked to be exactly the same after
  it.
- Upload the whole `magizai` folder when you update. An action whose file is missing cannot answer.
  Upload the `lib` folder first, then `bridge.php`, `hooks.php` and `magizai.php`: once `lib` is in
  place, the earlier version's three files keep working with it until they are replaced.
- One request now loads only the action it runs, instead of every action at once.
- An action is found by its exact name only. MagizAI always sends exact names, so nothing changes
  for it.

### Signed-in clients

**Every page says why nobody is identified.**
- A client-area page used to say only "nobody is signed in" whenever the chat got no sign-in, for a
  staff session, an account with no client chosen, a closed account or an error alike. The assistant
  then asked people who were plainly signed in to sign in. Each page now also says why, as a short
  code (never a name, an email or an account number), and the assistant answers accordingly.
- Pages that went out without a sign-in for a reason worth knowing (a staff session, no account
  chosen, a closed account, an error) are counted by hour, and the module reports the last day's
  counts to MagizAI with each connection check. Signed-in and signed-out pages are not counted.

**Staff sessions.**
- Login as Owner, and a WHMCS admin session in the same browser as a client session, both count as
  staff. Staff get no account answers in the chat, and the assistant now says why instead of asking
  them to sign in. To test account answers, use a private window signed in as the client.
- One change for installs set to **Only to signed-in clients**: a browser signed in to the admin area
  and to a client account at the same time is a staff session now, so the chat is not shown there
  unless staff view is on. Before, it showed the chat as that client.

**Staff view, off unless you switch it on.**
- New on the Setup tab. With it on, staff in either situation above can look up the client's
  account through the chat, labelled as staff view. Every change is refused, nothing is sent to the
  client (a password reset email, or login details, which give the username only), and every request
  is recorded against the staff member in this module's activity log and in WHMCS's own. It is off on
  new installs, and an upgrade never switches it on.

**"I add the embed code myself".**
- A new choice under Show the chat, for a widget you put in your theme or tag manager: the module
  adds only the sign-in, so your widget knows who is signed in. It needs no widget key in the module.
  "Nowhere" now means what it says: the module adds nothing at all. If an install is set to Nowhere
  and has an identity secret, the Setup tab says so; the setting itself is not changed.
- **Only to signed-in clients** works as before for clients: a client who is signed in, with an account
  chosen that is not closed, sees the chat, also when no identity secret is set yet or a sign-in could
  not be made for them. The page then says why.

**Fresh sign-ins.**
- The chat fetches a fresh sign-in from your WHMCS (`index.php?m=magizai&action=identity`) before the
  one it has runs out, for as long as the client is still signed in. A client who leaves a page open
  is no longer treated as a stranger. The address answers only your own pages and is never cached.
  It is asked on the address the page was opened on, at your WHMCS's path, so a client area reached
  with or without `www.`, or over https while the System URL says http, still gets its fresh sign-in.
  Where the module adds nothing to your pages (Nowhere, or no widget key while the module adds the
  widget), the address answers "not available". If something on your WHMCS has already started
  sending the page when the module answers, it hands out no sign-in, because it can no longer stop
  the answer being cached, and the module log says where that output came from.
- Because of that, sign-ins last 15 minutes on a new install. An install that upgrades keeps the
  lifetime it had (60 minutes unless you changed it).

**Signing out ends the chat's sign-in.**
- When a user signs out of the client area, every sign-in the chat holds for them from before that
  moment stops working, in every tab and on every device. Signing straight back in works, even
  within the same second. Other users of the same account stay signed in.
- Leaving Login as Owner is not the client signing out. If WHMCS reports it as a sign-out while the
  session is still marked as Login as Owner, only that staff member's staff view ends, and the
  client's own sign-ins on their own devices keep working. (The README explains the other case.)

**The sign-in names the person.**
- The name in a sign-in is now the signed-in user's own, not the account holder's, and the account's
  name travels beside it. A team member no longer appears in MagizAI's inbox under the owner's name.

**A signing key only your WHMCS holds.**
- Once MagizAI says it can check it, the module makes an Ed25519 key that never leaves your WHMCS, and
  sends MagizAI its public half with each connection check (the nightly one, or Test connection). It
  signs sign-ins with that key once the public half has gone out in two checks at least an hour
  apart, so one answer lost on the way never leaves MagizAI unable to check them. An answer the module
  can tell will arrive broken (another module's output in front of it) does not count, and neither
  does a check that did not say it can check the key. This needs
  PHP's sodium extension. The Setup tab says which key is in use and why, and you can choose to keep
  the shared secret. Rotate all secrets replaces this key too.
- MagizAI can check these sign-ins but cannot make them. It can still use the ones it is given: its
  chat runs on your client-area pages, so it receives each signed-in client's sign-in there and can
  fetch a fresh one while the page is open, and a sign-in works until it expires (15 minutes on a new
  install) or the client signs out. So this stops MagizAI making sign-ins, not using them.
- Two hours after the switch, sign-ins made with the shared identity secret are refused, whether or
  not a client has opened a page since. If you set a sign-in lifetime longer than two hours, that
  wait is one lifetime instead, so no page loses a sign-in that is still valid.
- This holds while MagizAI keeps checking in. If it has not said for 30 days that it can check the
  key, or no check has carried the key for 29 days, sign-ins go back to the shared identity secret,
  which MagizAI holds, and the bridge accepts those again until the key has gone out twice more. The
  Setup tab says when this has happened.
- If WHMCS cannot decrypt the key for a moment, that page gets no sign-in and the key is kept. It is
  replaced only when WHMCS decrypts it to something that is not the key (its encryption key
  changed).

**Clearer refusals.**
- A refused sign-in now says what went wrong: no sign-in sent, a sign-in this module did not make
  (for example the identity secrets of WHMCS and MagizAI differ), one that has expired, one for
  another account, or one ended by signing out (or, in staff view, by leaving Login as Owner). Your
  activity log shows the reason, and the client is told what helps, which is not always "refresh the
  page".
- The module reports a fingerprint of its identity secret, never the secret itself, so MagizAI can
  check that the secrets in WHMCS and MagizAI match without either being sent.

**What an upgrade changes.**
- It adds two small tables (the page counts, and sign-outs from the last day) and a column to the
  activity log for the staff member's id. Nothing else about signing in changes: placement, staff
  view and the token lifetime stay as they were. Your actions stay as they were too, apart from the
  four new look-ups under Account answers below, which are switched on. If you upload the files and
  do not open the module, the daily cron and the first sign-out add the tables instead.
- New files, to upload with the rest: `lib/Identity/Session.php`, `lib/Identity/Signing.php`,
  `lib/Identity/Verifier.php`, `lib/Identity/Revocations.php`, `lib/Identity/Telemetry.php`,
  `lib/Identity/KeyPair.php`, `lib/Http/TokenEndpoint.php` and `lib/Http/Ping.php`.

### Account answers

**"I paid, so why does it still say unpaid?"**
- A new look-up, **Payment status of an invoice**. For one of the client's invoices the chat can say
  what WHMCS holds: its status and balance, the payments recorded against it (the date, the amount,
  the payment method's name and only the end of its reference, at most the last six characters) and
  what each service or domain on it is doing now. A long invoice lists at most its five latest
  payments and its first ten services or domains, and says how many more there are.
- A paid invoice whose service is still suspended is said to need your team. If another overdue
  invoice is the likely reason, the chat names it, with its pay link. A suspension for any other
  reason (your terms, an ID check) is never blamed on a payment.
- Card details, what a payment gateway writes about a payment, and the full payment reference are
  never sent. Only an unpaid invoice gets a pay link; a payment that is still clearing is never
  offered for payment again.
- An unpaid invoice whose payment was refunded says so, and what is still to pay. It is never told
  that no payment was recorded while the refunded payment is listed under it.
- A team member needs "View & Pay Invoices", and sees the services on the invoice only with "View
  Products & Services" as well.

**"My order is still pending."**
- **Order status**: the client's five newest orders, or one by the number on its confirmation, and
  what each one is waiting for: payment (with the invoice's pay link), a payment that is still
  clearing, or setup. An order is only ever called paid when WHMCS says so.
- Nothing from WHMCS's fraud checks is ever sent: an order held for review is "being reviewed by the
  team". The address it was placed from, staff notes and a transfer's EPP code are never sent either.
- Each order is one line, naming what it is for, and what a state means (waiting for setup, say) is
  said once after the orders. An order that does not fit in the answer is counted, never cut short.
- It needs "Place New Orders/Upgrades/Cancellations". An order's invoice number and pay link go only
  to a team member who may also see invoices ("View & Pay Invoices"). Anyone else is told the order is
  waiting for its invoice to be paid, and who can pay it.

**"When does it renew?"**
- **Renewal status**: the next due date (a date already passed says so), about when WHMCS raises the
  next renewal invoice, the invoice that already renews it with its pay link, and whether it can be
  renewed early from the chat or why not, by the same rules as renewing.
- A one-time purchase is told WHMCS never invoices it again and how to carry on. A service that
  renews is never told to order the same plan again. A domain gets its expiry, auto-renew and renewal
  price.
- A service that has ended (terminated or cancelled) is told it is not renewed and that no renewal
  invoice will come, with how to order it again. One that is not set up yet is told the invoice for
  its order comes first. When the hosting on a domain has ended and the domain registration is still
  live, "when does it renew?" is answered for the registration.
- Asked without naming a service, it answers for the plan that renews, as a renewal would. The
  client's other live services (a one-time line, a free trial) are then named after it with their
  dates, so "when does my line end?" is never answered with the wrong service's date alone.
- It needs "View Products & Services", and "View Domains" for a domain. The renewal invoice's number
  and pay link go only to a team member who may also see invoices. Anyone else is told one is raised
  and who can pay it.

**"I never got the email."**
- **Emails sent to the client**: the date and subject of each email WHMCS sent the account, and
  whether one failed or is still waiting to go. Never what an email says (a welcome email holds
  passwords), never the addresses, and never why a send failed. It needs "View Email History".
- **Send an email again**: a service's welcome email (the one its product is set to send) or an
  invoice email (the invoice, or the receipt for a paid one), to the address on the account, never
  to an address given in the chat. **It is off until you tick it**, on new installs and after an
  update, because it sends an email in the client's name.
- It counts as a change, so the client confirms it with a code like every other change. WHMCS's
  answer is checked: an email template that is switched off or missing, or a mail server that refuses
  the email, is never reported as sent, and the reason goes to your activity log. At most three an
  hour for an account, counting only emails that went. Refused in staff view.
- A team member needs "View Email History", plus "View Products & Services" for a welcome email or
  "View & Pay Invoices" for an invoice email.

**One click into the control panel.**
- "Log me into cPanel" now gets one link that opens the control panel in the same browser, through
  WHMCS's own single sign-on, for cPanel, Plesk, DirectAdmin, InterWorx and CyberPanel services. It
  works only inside the client's own WHMCS session: nothing is created, nothing secret is in the
  link, and it does nothing for anyone else.
- Login details and the getting-started steps lead with that link, before any password.
- A team member needs "Perform Single Sign-On". A client who switched single sign-on off in their
  security settings is told where to switch it back on, and any other service gets its page in the
  client area.

**Long accounts are never cut short.**
- The account overview and the lists of services, domains, invoices and tickets now start with their
  counts, for example "19 live services (1 past its due date, 4 due within 14 days, 1 suspended), 4
  ended", and list what needs attention first. They stay short enough that MagizAI never has to cut
  them. When a list leaves some out it ends with "... and 3 more, ask about a specific one", so
  nothing disappears without a word.
- The account overview lists open invoices with the most overdue first, then services with the
  soonest due first, domains, tickets, and the credit balance. A user who may see invoices always
  gets the credit balance now, $0.00 included.
- Every figure counts the whole account. An account with more than 500 live services was told it
  had 500, and its overdue, suspended and due-soon figures left out the oldest ones.
- "Expiring within 30 days" means the same in the domain list and the account overview: today up to
  30 days from today. The count and the domains marked as expiring always agree, and a domain whose
  date has already passed is not called expiring.

**What an update switches on.**
- The four new look-ups are switched on, however you update, like the look-ups of every release
  before. Sending an email again stays off until you tick it. An action you switched off stays off.
- New file, to upload with the rest: `lib/Summary.php`.

### Security and compatibility fixes

**Who a request is for.**
- A sign-in must name a client account number above zero. A sign-in for account "0" is refused
  before anything is looked up.
- On WHMCS 8 and later, a sign-in must also name a user of that account. One that does not is
  refused; it is never treated as the account owner any more.

**Team members see only what the owner allows, with WHMCS's own meanings.**
- A service password needs "View & Modify Product Passwords". A user without it gets the username
  and is told the owner has not given access to passwords.
- Ordering, upgrades, renewals, extras and cancellations need "Place New Orders/Upgrades/Cancellations".
  They were wrongly tied to the password permission before.
- Renewing a domain needs "Orders" as well as the domain permissions, and changing domain settings
  needs "Manage Domain Settings".
- The account credit is shown only to users who may see invoices. The Site Doctor ownership check
  needs "View Products & Services", and a link to a named service or domain needs the permission
  for it.
- A team member who may order but not see invoices was also given the number and pay link of the
  invoice a renewal, an upgrade, an extra or a new order from the chat had just raised. The change is
  still made; they are now told an invoice is raised and that the account owner, or a team member
  who may see invoices, can pay it.
- A team member who may order but not see invoices was given the number and pay link of the invoice
  that already covers a renewal. They are now told an invoice is raised and that the account owner,
  or a team member who may see invoices, can pay it. Nothing new is ordered either way. A suspended
  service no longer gives them the number of overdue invoices or the link to them.

**Service passwords.**
- Login details are sent at most three times an hour for an account, and three times an hour for a user.
  Only a request that actually emailed or showed a password counts. An answer with the username
  only, or with no password to give, never uses up the limit.
- When passwords may be shown in the chat, the client's sign-in must have been confirmed within
  15 minutes (a new setting on the Setup tab): by a client-area page, or by the chat fetching a fresh
  sign-in while they were still signed in. Otherwise the password is emailed instead.
- A custom field marked "Admin Only" is never read out as a client's username or password, and only
  the custom fields of the client's own product are read.
- WHMCS's encrypted form of an empty password is no longer handed out as the password. The custom
  fields are checked instead. A stored value WHMCS refuses to decrypt is not handed out either.
- A cPanel, Plesk or DirectAdmin service now gets its control-panel link: the server type is read
  from the product, because WHMCS's service list does not include it.

**Emails tell the truth.**
- Every email to a client is checked. If WHMCS cannot send it, the chat says so instead of claiming
  it was sent. A password reset whose email fails says the password was changed, alerts your team in
  the WHMCS activity log and, when you allow passwords in the chat, offers the new one there. A
  login details email that fails alerts your team in the activity log too.
- The address an email went to is read from the client details WHMCS will keep supporting, not
  from the copy WHMCS has marked for removal.
- Braces in a password or name no longer break emails on WHMCS 9, which reads custom messages as
  templates. Values with an invalid character are escaped instead of dropped.
- Passwords and transfer codes are masked in the WHMCS module log, including the escaped copies
  inside an email body that could not be sent.

**Orders, upgrades and renewals.**
- A domain is ordered for registration only when the lookup says it is free. If the lookup fails,
  no order is placed and the client is offered the other ways to order.
- Upgrade prices are read correctly from WHMCS's formatted figure. They used to show as $0.00, and a
  downgrade now reads as a credit. A currency symbol saved as an HTML entity (such as `&pound;`) is
  recognised, so upgrades in that currency are quoted instead of refused. The billing cycle is sent
  the way WHMCS documents it.
- Renewing a service early is offered only when WHMCS will accept it: WHMCS 8.8 or newer, an active
  service, on-demand renewal switched on for the product, and inside the renewal window. Otherwise
  the client is told why, and when it opens.
- A cancellation reason now goes through WHMCS's own field. Hidden support departments and retired
  extras are no longer offered. A registrar or server error about your own systems (a reseller
  account or its balance, an IP allow-list, a timeout) is kept for staff in the activity log, and
  the client is told plainly that it did not work.
- If WHMCS still refuses an early renewal (for a reason the module cannot read, such as metric
  billing), that refusal is remembered for the service and its due date, and the renewal is not
  offered again until the due date moves on.
- When an invoice already covers a renewal, the client is given that invoice's pay link and nothing
  new is ordered. A payment that is still clearing is never offered for payment again, and an
  invoice with collections is passed to your billing team. Because nothing is ordered, it does not
  count towards the client's 10 account changes an hour, and it is not written to the WHMCS
  activity log as a change.
- A team member who may not place orders is told so when they ask to renew, instead of being
  offered a confirmation code that would then be refused.
- A service that cannot be renewed early is told it renews through its next invoice, with the day
  WHMCS raises it, and is never told to order the same plan again: for a service that renews, that
  would be a second subscription. A free trial is told to order a paid plan. A one-time purchase is
  still told to order the same plan again.
- Renewals say the amount, in the client's own currency, and MagizAI can show it in the confirmation
  the client types a code for. Domain prices are quoted in the client's own currency when they are
  signed in.
- Out-of-stock plans are read from WHMCS's own stock control: shown as out of stock, and refused
  before an order is placed.
- Upgrade prices are worked out with a payment method you have switched on (the client's own, or
  another active one), so a host without Bank Transfer can offer upgrades. When WHMCS cannot price a
  change, the client hears WHMCS's own reason if it is about their request.

**The right service, without a needless question.**
- With a paid line and a free trial on the account, renewing, upgrading and adding an extra now
  mean the paid line, instead of asking which service. When a question is still needed, it names
  only the services the change applies to.
- A cancellation is never pointed at a service the client did not name. With more than one live
  service, the chat asks which one, free trials included.

**Invoices.**
- Invoices that are paid and still clearing ("Payment Pending") and invoices with collections now
  appear in the account overview and the invoice list, each said for what it is. Only an unpaid
  invoice gets a pay link.

**Domains.**
- The domain list shows live domains first, soonest expiry first, then the ones that expired in the
  last 90 days, up to 15 in all. It then says how many more there are and how many have ended.
  However many old domains an account holds, they can no longer hide the live ones.
- A transfer code the registrar sends itself is said to go to the domain's registrant contact,
  which can differ from the address on the account.
- A domain's details now say whether it can be renewed from the chat, by the same rules as renewing
  it: its state, the user's permissions and any invoice that already renews it. MagizAI reads this
  before it offers a confirmation code, so a renewal it would refuse is no longer offered. The number
  and pay link of that invoice go only to a team member who may see invoices, also when a renewal is
  refused.
- The "What the assistant may do" tab says where a transfer code goes: to the address on the account,
  or from the registrar to the domain's registrant contact.
- Domain prices are asked for the client or for a currency, never both, and the client's currency
  is looked up once per answer instead of once per price.
- Domain availability checks from the chat are limited: 12 an hour in one conversation and 150 an
  hour across your install, and a visitor who is not signed in gets at most two other extensions
  checked. Past a limit the client is pointed at your store's own domain search.

**Tickets.**
- On WHMCS 8.5 and newer, a ticket opened from the chat is filed for the user who asked, not only
  for the account. A ticket about a service or domain the client named is linked to it.
- The ticket list and the account overview read tickets in every department, not only those of the
  admin the API runs as.
- The knowledge sync no longer publishes the names of hidden support departments. The Setup tab
  marks a hidden department, which you can still choose as the default for chat tickets.

**Help articles.**
- Help article search ignores common words and ranks the best match first, however many articles
  mention the same word. Setup guides come from your own setup categories first.

**Updating.**
- A read action added in a new version is switched on however you update: by uploading over the old
  files, or by deactivating, uploading and activating. It used to stay off after deactivate, upload,
  activate. Anything that changes an account or raises an invoice is never switched on by an update,
  and an action you switched off stays off.

**Your settings.**
- New on the Setup tab: the identity token lifetime (5 to 240 minutes: 15 on a new install, and an
  upgraded install keeps the 60 it had) and how recently a client must have signed in to see a
  password in the chat.
- "Account changes need a sign-in within" starts at 15 minutes on a new install. An install that
  upgrades keeps the value it has.
- **Rotate all secrets** replaces the bridge secret and clears the identity secret and API token in
  one step. Revoke the old API token in MagizAI as well (Settings, API tokens): clearing it in
  WHMCS does not stop it working there. Deactivating the module does not replace the secrets.
  **Forget the kept copy** makes deactivation remove every setting and secret.
- The checklist says when a saved secret can no longer be read, which happens when WHMCS's own
  encryption key changes. The connection test reports the same, without the secrets themselves.
- The admin page reminds you to update WHMCS when it is a build before 8.13.7 or 9.0.8, which have a
  serious security flaw (CVE-2026-67399).

**Behind the scenes.**
- Stray output from other code (a PHP notice, whitespace in another module's file) no longer breaks
  the reply's signature. What was thrown away is noted in the activity log. An output buffer that
  other code opened and that cannot be closed no longer makes a request hang; the activity log
  says how much output it held.
- A database fault while recording a request is reported as a fault, not as a repeated request.
- Nothing deprecated in PHP 8.5 runs on PHP 8.
- Client-area pages decrypt only the secret they need, and the product catalogue reads WHMCS's
  product settings in one query instead of one per product. This release adds many files under
  `lib/`, so upload the whole `magizai` folder: an action whose file is missing cannot answer.

**Known limit.**
- WHMCS's older encryption cannot tell plain text from a value encrypted with a key it no longer
  has (after a server move that changed the encryption key, for example). Both decrypt to
  unreadable bytes. Because many provisioning modules store passwords as plain text, such a value
  is still read as a plain-text password, so in that one case the encrypted text can be handed out
  as the password. If your encryption key changed, reset the affected service passwords.

---

## 1.5.7

**Deactivating the module no longer disconnects it.**

WHMCS deletes an addon's settings when it is deactivated. Many admins update by deactivating,
uploading the new files and activating again, and every time that made a new bridge secret: the
copy saved in MagizAI stopped matching, every chat question failed, and your action choices and the
password switch went back to their defaults.

The module now keeps its own copy of its settings, which deactivation leaves alone. Activating it
again puts back the bridge secret, the identity secret, your action choices and the password switch
exactly as they were, so nothing has to be pasted again.

The copy is taken the first time you open the module after uploading this version, and again on
every save. Secrets in it stay encrypted with your WHMCS key, the same as in WHMCS's own table.

If your install was deactivated before this version, its settings are already gone: activate it,
then paste the new bridge secret into MagizAI once. From then on it is kept.

---

## 1.5.6

**The password now reaches the chat. Upgrade if you use the password switch.**

In 1.5.5 the module found the password and then lost it on the way out. The bridge built its reply
from the answer text only, so the password never left WHMCS and the client was told to use a button
that could not appear. The reply now carries it. It is still shown once, behind a button, in that
client's browser only, and never goes to the AI or into the transcript.

**Passwords and usernames, wherever you keep them.**
- A password stored in plain text is given as it is. One WHMCS cannot decrypt no longer comes back
  as unreadable characters: anything that is not readable text is refused and the next place is tried.
- If the service has no username, a custom field named username, user, login or line is used.

**Sub-users see only what the owner allows.** WHMCS lets an account owner give other users limited
access. The chat now follows those permissions: a user without access to invoices is not read the
invoices, and the account overview leaves out whatever that user may not see.

**Shared computers.** When nobody is signed in to the client area, the chat now starts fresh instead
of carrying on the previous client's conversation.

**Right service, right answer.**
- A client with one live service and several ended ones is never asked which one they mean.
- Service lists put live services first and show when each runs out, with overdue dates marked.
- Renewing a one-time subscription explains that the plan is ordered again, and an existing unpaid
  renewal invoice is matched to the exact service it belongs to.
- Invoices are found by the number the client sees on them, as well as by internal id.
- A plan sold on several terms asks which term instead of picking one.
- Setup steps for services with no domain (streaming, licences, VPN) no longer talk about domains
  and nameservers. Your own install guides are offered as choices.

**Prices in the client's currency.** A signed-in client is quoted in the currency WHMCS bills them
in. A visitor who is not signed in, or whose sign-in has lapsed, still gets your public prices.

---

## 1.5.5

**Service passwords are given to the client. This is the fix.**

WHMCS installs store a service password one of two ways: encrypted with WHMCS's own key, or in plain
text — which is how many provisioning modules write it, IPTV and streaming panels above all, and why
a merchant can simply read the password in their own WHMCS.

Every earlier version ran the stored value through WHMCS's decrypt step. WHMCS returns *nothing* when
asked to decrypt something that is not encrypted, so on every plain-text install the assistant
reported "no password stored" — with the password in plain sight.

The module now reads the password as it is stored. If WHMCS can decrypt it, the decrypted value is
used; if it cannot, the stored value is the password and is given as it is.

---

## 1.5.4

**The assistant now actually answers account questions.** A set of fixes found by testing a live
streaming merchant end to end, where every skill was firing and the answers still came back wrong.

**Passwords.**
- Found on products that keep them in **custom fields**, which is where almost everything WHMCS did
  not provision itself stores credentials: IPTV and streaming lines, VPNs, game servers, licence
  keys. Only field names that are unmistakably a password are read; licence keys, portal URLs and MAC
  addresses in other fields are never returned.
- When you have ticked *Show a service password in the chat window*, a client asking in the chat now
  gets it **in the chat**. It used to default to email unless the assistant asked for chat
  specifically, so a host who had ticked the box still saw clients told to check their email.
- Right after the login, the client is offered **your own setup guides** — by device or app, as
  buttons (Android TV / Firestick, iPhone, PC and so on, named as your articles are).

**The whole account, not a count.** The account overview used to say "Services: 1 active. Unpaid
invoices: 1." It now lists each service with the date it runs out, each unpaid invoice with its
amount, due date and pay link, domains and open tickets, so "when does my subscription end?" and
"what do I owe?" are answered outright.

**Expiry dates on one-off payments.** Services billed as a one-time payment — how most streaming
subscriptions are set up — showed no date at all. The date is now shown whenever WHMCS has one.

**No more "which service?" for somebody with one.** A client with a single service gets it used
automatically; a client with several is offered them as choices.

**Your MagizAI connection test now says whether passwords reach the chat**, so nobody has to
remember which box was ticked.

---

## 1.5.3

**Fixes "WHMCS has no stored password for this service" on services that do have one.**

The login-details answer read the password from the API response for a service, and that response
does not reliably carry it. So the assistant reported that no password was stored, on accounts where
the password was sitting in WHMCS the whole time, and offered a reset instead — changing a working
password for no reason.

It now reads the column directly, which is the authority.

Reminder: the password is **emailed** unless you tick *Show a service password in the chat window* in
this module's settings. With that ticked the client can reveal it in the chat itself, once, in their
own browser.

---

## 1.5.2

**Orders for anything you sell without a domain.**

Placing an order always demanded a domain name. That is right for shared hosting and wrong for most
of what WHMCS is actually used to sell — IPTV and streaming subscriptions, licences, VPN plans, SSL,
top-ups, support retainers. For all of those the assistant had to ask a customer for a domain they
do not have and the product does not accept, and the order could never be completed.

It now reads your own **Require Domain** setting on each product. A product with it off is ordered
with no domain, no registration and no availability check, and the assistant is told not to ask.
Products with it on are unchanged in every respect.

Read per product, not per install, so selling hosting *and* streaming from one WHMCS works correctly
for both. Your plan list in the chat now also says which plans need a domain.

---

## 1.5.1

**Fixes "Not connected yet" on an install that is connected.**

The badge at the top of this page, and the checklist line below it, asked one question: has MagizAI
made any request to this module recently? The connection test sends a `ping`, and `ping` was the one
request that answered without recording itself — so a perfectly working install read as not
connected, and the checklist advised fixing it by pressing the very button that could never satisfy
it.

Pings are now recorded like every other request. Press **Test connection** on the MagizAI Connectors
page and reload this page.

Nothing else changed. If your install already says *Connected to MagizAI*, you do not need this
version.

---

## 1.5.0

**Clients can get their own login details in the chat.**

"What is my cPanel password?" is one of the most common things a hosting client asks, and until now
the only answer the assistant had was to reset it — which changes a working password and breaks
whatever was still using it. A signed-in client can now simply ask.

The **username** is answered straight away. The **password** is emailed to the address on the
account, which is what an install does until you decide otherwise.

**Showing the password in the chat is a separate switch, and it ships off.**
Tick *Show a service password in the chat window* in the module settings to turn it on. With it on,
the client can reveal the password in the chat itself. How that works matters:

- the password is put behind a button and fetched by that client's browser, once;
- it is **never written into the conversation**, so it is not in the transcript, not in the inbox
  an agent reads later, and not in an exported chat;
- it is **never sent to the AI** — the assistant is told a password exists and is explicitly told it
  has not been given it;
- the link behind the button expires in three minutes, works a single time, and only in the chat it
  was created in.

Even with all of that, a password on a screen can be read by whoever is looking at that screen,
which is why the switch is yours and not ours.

Only ever the client's own services, only while they are signed in, and never for a cancelled or
fraud service. Nothing is changed by this: it is a read.

**Also new: how to start using a service.** A client who has just bought hosting can ask what to do
next and get their control panel address, how to point the domain, where to upload the site, how to
set up email, and links to your own help articles rather than generic ones.

Both are switched on when you upgrade, because both are reads of the client's own account.

---

## 1.4.2

Documentation release. Adds this changelog, and publishes it with the module so you can see what a
version changes before you install it.

## 1.4.1

Fixes found while testing ordering against live hosting accounts.

- **Prices are read in the client's own currency.** WHMCS stores a separate price row per currency.
  The module was reading the host's default row and printing it with the client's symbol, so a
  client billed in euros was shown the dollar figure labelled as euros, in the sentence they
  confirm before an invoice is raised. Nothing is converted with an exchange rate: a converted
  figure is a number nobody will ever be charged.
- **A plan with no price in the client's currency says so**, instead of reporting that the plan
  does not exist. It does exist; it is simply not priced for them.
- **The duplicate-invoice guard now covers services without a domain.** It searched on the service's
  domain and gave up when there was none, so for a VPS, a licence, an SSL or a support plan the
  check never ran at all and each renewal raised another invoice for the same period.
- **Upgrade options are capped at six.** Each option costs one API call to price, so a host with
  many plans in a group turned one read into an unbounded fan-out.
- **Removed the claim "nothing has been charged yet."** The module cannot see whether a host has
  automatic credit-card capture switched on, so it now says the service starts once the invoice is
  paid, which is true either way.
- **Update notice.** The module checks once a day whether a newer version exists and shows it, with
  a download link and a SHA-256 to verify the file. It never downloads or installs anything itself,
  sends nothing about your install, and fails silently if we are unreachable.

## 1.4.0

Ordering and account changes from the chat. Every action below is **off until you switch it on**,
and each one reads the price WHMCS holds at that moment rather than anything quoted earlier in the
conversation.

**New — the client confirms with a one-time code before anything happens:**

- **Place an order** for a plan and a domain. The assistant never takes a card: it creates the
  order and its invoice and hands back your payment link. It refuses a domain that is already
  registered, refuses a plan that matches two products, and will not sell a registration to a
  client who already owns the domain.
- **Upgrade or downgrade** a service, priced by WHMCS's own pro-rata calculation. A plan not sold
  on the client's existing billing term is not offered, so a monthly client cannot be moved onto a
  yearly invoice by accident.
- **Renew a service early** and **renew a domain**, both refused when an unpaid invoice already
  covers that period.
- **Add an extra** to a service, from the addons that actually apply to that product.
- **Reset a hosting password.** Generated on your server, set, and emailed to the address on the
  account. It is never shown in the chat.

**New — reads, switched on by the upgrade:**

- **Upgrade options and prices**, so a client can ask what a bigger plan would cost.
- **Extras available** for one of their services.
- **Client-area deep links**, straight to the right page. Deliberately not single sign-on: an
  auto-login token grants full account access and skips two-factor, and anything put in a chat can
  be read by whoever is watching it.

## 1.3.0

- **Site Doctor ownership check.** Answers which hosting accounts the signed-in client owns, so a
  website can only be inspected for the person who owns it. The answer holds the domain, the system
  username, the server and the status, and is never shown to the assistant or the client: it only
  decides yes or no. Switched on by the upgrade.

## 1.2.0

- **Your plans as cards in the chat.** `product_catalog` began answering with structured data as
  well as text, which is what makes plan cards possible: the price for the best-value term, the
  per-month equivalent, the saving against paying monthly, the feature list, and an order button.
  **Below this version plan cards cannot exist at all** — the assistant falls back to describing
  plans in prose it wrote itself, the connection still tests green, and nothing says why. If your
  chat shows plans as text rather than cards, this is the release you are missing.
- Hidden and retired products never appear. Prices refresh daily and whenever you save the
  connection.

## 1.1.0

- **Password reset.** Emails a reset link to the signed-in client's own address, or explains the
  forgotten-password route to a visitor who cannot sign in. The chat never shows, asks for or sets
  a password. Switched on by the upgrade.

## 1.0.0

First release. AI live chat that answers from your own WHMCS.

- **For any visitor:** knowledgebase search, announcements, network status, products and prices,
  domain availability and domain pricing.
- **For a signed-in client:** account overview, services, service details, domains, domain details,
  invoices, invoice details, tickets and ticket details.
- **Changes the client confirms:** nameservers, domain auto-renew, opening and replying to tickets,
  cancellation requests, and emailing a domain transfer code to the address on file.
- **Security:** no admin API key; every request signed with HMAC-SHA256 plus a timestamp and a
  one-time nonce; replies signed too; WHMCS verifies the client's identity token itself; every
  lookup scoped to the signed-in client, with "not found" and "not yours" answering the same so the
  chat cannot be used to discover other clients' domains.
