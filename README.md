# MageMe Core for Magento 2

[![Latest Version on Packagist](https://img.shields.io/packagist/v/mageme/module-core.svg?style=flat-square)](https://packagist.org/packages/mageme/module-core)
[![Packagist Downloads](https://img.shields.io/packagist/dt/mageme/module-core.svg?style=flat-square)](https://packagist.org/packages/mageme/module-core)
[![Magento](https://img.shields.io/badge/Magento-2.4.x-EE672F.svg?style=flat-square)](https://magento.com)
[![PHP](https://img.shields.io/badge/PHP-7.4%20–%208.5-777BB4.svg?style=flat-square)](https://php.net)
[![License](https://img.shields.io/badge/license-MageMe%20EULA-blue.svg?style=flat-square)](https://mageme.com/license/)

Foundation module for all [MageMe extensions](https://mageme.com) for Magento 2. Provides the shared infrastructure used across the MageMe product family: license activation, the admin module ecosystem panel, news feed integration, and shared utility libraries.

`mageme/module-core` is normally installed automatically as a dependency when you add any other MageMe extension. You rarely need to install it on its own.

## What it provides

- **License management** — activate, deactivate, and verify MageMe extension licenses against `license.mageme.com` from each module's admin configuration section. Every request (activate, deactivate, check) sends the serial, the module name and its installed version, the store base URL, the Magento version and edition, the platform code (M2CE or M2EE) and the access token from the last activation, if there is one. The check runs from a cron job that looks every 5 minutes; each installation checks at its own minute of the hour, once a day per license (hourly while the installed version is not covered, right away after a module version changes, less often after failures), so admin pages never call the license server; "Recheck License Status" asks at once. Without a working cron the stored verdict stays as it was until a recheck or a new activation. The automatic check does not switch a license off, and neither does "Recheck License Status", whatever the license server answers: only Deactivate does. A granted check refreshes the support date and the version coverage, and the recheck says why the server did not grant the license.
- **Version coverage** — a license covers the module versions released before it expired. When the installed version is newer, one message in the admin notifications inbox (and a warning in the module's settings panel) says how to get it covered: renew the license, accept the license agreement of a free upgrade (the panel has an Accept and Upgrade button), or activate a production license instead of a development one; the storefront is not affected. There is one such message per module: a newer uncovered version replaces it, and it is marked read once the version is covered or the license is deactivated. The message links to the renewal page of the serial, so the serial is visible to every admin who may read the inbox. Other MageMe modules can read the verdict through `MageMe\Core\Api\LicenseCoverageInterface`. A free upgrade to a new product generation is finished by accepting its license agreement: `GenericLicenseHelper::acceptUpgrade()` behind the POST `core/license/acceptUpgrade` (ACL `MageMe_Core::license_accept_upgrade`, Administrators only by default) sends it for the module's serial and stores the new verdict.
- **Module ecosystem panel** — admin block injected into every MageMe module's configuration page. Shows the product family overview, installed add-ons, available updates, license status, and quick links to Renew, Get Pro, or Buy.
- **News feed** — surfaces MageMe announcements in the Magento admin notifications inbox through Magento's standard `AdminNotification` infrastructure.
- **Shared utilities** — locale-aware date and time formatting, visitor IP detection through proxies (see below), static asset content inlining, area detection helpers, and a self-sizing admin multiselect form field.
- **Bundled JS libraries** — SweetAlert2 and Tingle modal, kept for older MageMe modules (see [Third-party libraries](#third-party-libraries-deprecated-kept-for-older-modules-removal-in-core-30)).

## Data sent with the news feed

The news-feed request (at most once an hour, and only while someone is using the admin panel) carries:

- the list of enabled MageMe modules with their versions;
- a random, pseudonymous installation identifier, generated once on the first request and stored in the `flag` table — it is not derived from your domain or any other store data, and it lets us count installations rather than requests;
- a pseudonymous fingerprint of the environment (a truncated hash of the base URL and installation date) — together with the identifier it shows when a store changes its domain or is copied to staging;
- the application mode (production, developer or default) and whether the `Magento_Company` module (the B2B suite) is enabled.

It tells us which extensions and versions are actually in use and lets announcements be limited to those extensions. The store domain, customer data, and order data are never sent. On our side the raw web-server logs are kept for 52 days; the aggregated statistics contain no IP addresses.

To turn it off (nothing is sent and no identifier is generated):

```bash
bin/magento config:set mageme/feed/send_modules 0
```

The identifier stays in the `flag` table if the module is removed; delete the `mageme_feed_installation_id` row to drop it.

## Visitor IP address and trusted proxies

MageMe modules record the visitor's IP address with submissions, quote requests and price requests. Core reads it the
same way for all of them:

- **Trusted Proxies empty (default):** the first of `CF-Connecting-IP` and `X-Forwarded-For` that the request carries
  (for `X-Forwarded-For`, its leftmost address); when that value is not a valid IP, the address of the connection
  (`REMOTE_ADDR`). This is Magento's own remote address with these two headers. Any request can set these headers.
- **Trusted Proxies filled** (Stores › Configuration › MageMe Extensions › MageMe › Network): headers are believed only
  when the connection comes from a listed proxy, and only `X-Forwarded-For` is read: from the right, past the listed
  proxies, the first address that is not one of them. An entry that cannot be read (for example `unknown`) stops the
  walk and the connection address is stored. A request from any other address is recorded with its own connection
  address, so a visitor cannot pick the IP that gets stored.

The list takes IPv4 and IPv6 addresses and CIDR ranges, one per line (commas also work). Fill it when the store runs
behind a load balancer, Varnish or Cloudflare. With Cloudflare, list your own proxies and Cloudflare's IP ranges
(cloudflare.com/ips): Cloudflare adds the visitor to `X-Forwarded-For`, so the walk finds it. The result is always a
valid IP address.

## Third-party libraries (deprecated, kept for older modules; removal in Core 3.0)

| Library | Version | License | RequireJS name |
|---|---|---|---|
| [SweetAlert2](https://sweetalert2.github.io) | 8.5.0 | MIT, © 2014 Tristan Edwards & Limon Monte | `swal` |
| [tingle.js](https://tingle.robinparisi.com) | 0.15 | MIT, © 2015 Robin Parisi | `tingle` |

Older MageMe modules still load them, so they stay in Core 2.x with their license notices. Core's own admin UI no
longer uses them; new code uses `MageMe_Core/js/dialog`. They will be removed in Core 3.0.

## Requirements

- Magento 2.4.x (Open Source or Commerce)
- PHP 7.4, 8.0, 8.1, 8.2, 8.3, 8.4, or 8.5
- PHP `curl`, `intl`, and `json` extensions

## Installation

In most cases `mageme/module-core` is pulled in automatically as a dependency of another MageMe extension. To install it standalone:

```
composer require mageme/module-core
bin/magento setup:upgrade
bin/magento cache:flush
```

## MageMe extensions that depend on this module

[**MageMe WebForms 3**](https://mageme.com/magento-2-form-builder.html) — Magento 2 form builder with conditional fields, multi-step layouts, file uploads, approval workflows, and a full integration stack:

- Salesforce — create leads with campaign tracking
- HubSpot — sync contacts, companies, and tickets
- Zoho CRM & Desk — create leads and helpdesk tickets
- Freshdesk — support tickets with agent routing
- Zendesk — tickets with custom field types
- Klaviyo — profiles and email lists
- Mailchimp — audience subscriptions
- Zapier — connect forms to 7000+ apps

[**MageMe Hide Price**](https://mageme.com/magento-2-hide-price-extension.html) — control catalog visibility. Hide prices and the Add to Cart button from specific customer groups or per-product, with optional replacements: a sign-in button, an info alert, or a Request a Price form that emails the admin and the customer.

[**MageMe EasyQuote**](https://mageme.com/magento-2-quote-extension.html) — B2B request-a-quote workflow. Customers submit quote requests from the cart, admins respond with custom per-item pricing, volume tiers, custom shipping, and discounts. Full quote lifecycle, two-way messaging thread with attachments, PDF and CSV export, GraphQL and REST APIs.

[**MageMe EU Withdrawal**](https://mageme.com/magento-2-withdrawal-button-extension.html) — EU consumer right-of-withdrawal compliance. Adds the statutory "Withdraw from Contract" flow for B2C distance and off-premises contracts under Article 11a of Directive 2011/83/EU (as amended by Directive (EU) 2023/2673), effective 19 June 2026. Customers withdraw within the 14-day cooling-off period, with full audit trail, evidence capture, and admin approval workflow.

See the full catalog at [mageme.com](https://mageme.com).

## Custom Magento development

Need a feature an extension doesn't cover, or a bespoke Magento build? MageMe takes on custom extension development and integration work.

→ **[Custom Magento development](https://mageme.com/magento-services/custom-development)**

## Support

- Documentation: [docs.mageme.com](https://docs.mageme.com)
- Bug reports and feature requests: [GitHub Issues](https://github.com/mageme/module-core/issues)

## License

Governed by the **MageMe End User License Agreement** ([mageme.com/license](https://mageme.com/license/)). Distributed free of charge as the shared foundation for MageMe extensions. The admin panel uses icons from [Phosphor](https://phosphoricons.com) (MIT) and [Lucide](https://lucide.dev) (ISC).

---

**MageMe** builds Magento 2 and Adobe Commerce extensions for B2B merchants — form building, quoting, catalog control, and EU compliance.