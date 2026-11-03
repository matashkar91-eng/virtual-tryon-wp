=== Virtual Try-On Studio ===
Contributors: matashkar91-eng
Tags: virtual try on, woocommerce, avalai, fashion
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Configurable Persian RTL fitting room, explicit product allowlist,
private images, durable jobs and private result links.

== Installation ==
Upload the plugin ZIP, activate, open the پرو مجازی menu, select products,
and place [virtual_tryon] on a page. Start in demo mode.
See README.md for private storage, cron, privacy and deployment requirements.

== External services ==
When AvalAI mode is enabled, a customer's photo, product image and prompt are
sent server-side to https://api.avalai.ir/v1/images/edits using the store's API key.
Nothing is sent to AvalAI in demo mode. The operator must review AvalAI's current
terms, privacy and image-edit API support before enabling live use.
AvalAI multi-image endpoint/model compatibility has NOT been live verified.
Demo returns the original photo and does not change garments or accessories.

== Changelog ==
= 0.1.0 =
Initial development release. Enable paid public use only after live provider
validation and the deployment checks in README.md.
