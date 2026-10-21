# MagizAI Live Chat for WHMCS

The WHMCS addon module that connects your WHMCS to a [MagizAI](https://magiz.ai) account. The MagizAI
chat assistant then answers from your knowledgebase, announcements, network status, products and
domain prices, and for clients signed in to the client area it can look up their services, domains,
invoices and tickets, and make the changes you allow after the client taps Confirm in the chat.

**Current version: 1.10.1**

## Download

- The newest release: [Releases](https://github.com/shahidmallaofficial/MagizAi-WHMCS/releases/latest),
  as a zip with its SHA-256 checksum. It is the same file as
  `https://magiz.ai/downloads/whmcs/magizai-1.10.1.zip`.
- `https://magiz.ai/whmcs/latest.json` always names the newest version, its download and checksum.

## Install

1. Upload the `modules/addons/magizai` folder into your WHMCS root, so you end up with
   `modules/addons/magizai/magizai.php`.
2. In WHMCS go to **System Settings → Addon Modules**, find **MagizAI Live Chat** and click **Activate**.
3. Click **Configure**, tick the admin roles that may manage it, and save.
4. Open **Addons → MagizAI Live Chat** and follow the Setup tab.

The full setup, every action and what each one sends: [modules/addons/magizai/README.md](modules/addons/magizai/README.md).

## Update

Upload the new files over the old ones, then open **Addons → MagizAI Live Chat** once. An update never
switches on an action that changes something on an account: tick each one on the Actions tab when you
want it. What changed in each version: [CHANGELOG.md](modules/addons/magizai/CHANGELOG.md).

## Requirements

- WHMCS 8.0 or newer. Renewing a service early from the chat needs WHMCS 8.8 or newer.
- PHP 7.4 or newer, within the PHP versions your WHMCS release supports.
- A MagizAI account: [plans and prices](https://magiz.ai/pricing).

## Help

[magiz.ai/whmcs](https://magiz.ai/whmcs) explains what the module does. To reach us, use
[magiz.ai/contact](https://magiz.ai/contact).
