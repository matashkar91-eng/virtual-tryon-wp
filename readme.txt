=== Virtual Try-On Studio ===
Contributors: matashkar91-eng
Tags: virtual try on, woocommerce, avalai, fashion
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 0.1.4
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
No images are sent to AvalAI in demo mode. An explicit administrator connection
check sends the saved API key to GET https://api.avalai.ir/v1/models, including
when demo is selected. It retrieves model availability without generating images.
The operator must review AvalAI's current terms, privacy and image-edit API support
before enabling live use.
AvalAI multi-image endpoint/model compatibility has NOT been live verified.
Demo returns the original photo and does not change garments or accessories.

== Changelog ==
= 0.1.4 =
Accept standard base64 and HTTPS image-URL results with safe bounded downloads.
Keep result references privately for GET-only recovery without regenerating.
Add safe response-shape diagnostics for unsupported JSON, HTML or pending replies.
This does not retrospectively recover response bodies discarded by older versions.

= 0.1.3 =
Add administrator-only, nonce-protected read-only connection/model probe.
Distinguish DNS, connect, TLS, timeout and generic transport failures using
bounded numeric evidence and timings, without logging raw HTTP errors or keys.
Keep uncertain requests from automatic resubmission; editing support remains
unverified by a successful model-list probe.

= 0.1.2 =
Fix settings validation rejecting dotted model names such as
gpt-image-2.5-flare. Preserve existing credentials and store settings on upgrade.
Accept bounded provider/model and version suffixes, while rejecting URLs,
whitespace and control characters. Add regression coverage for model settings.

= 0.1.1 =
Use gpt-image-2.5-flare for new settings: OpenAI retired gpt-image-1 on
October 23, 2026. Existing saved model settings are preserved; merchants can
change the model field manually. AvalAI routing still requires live validation.

= 0.1.0 =
Initial development release. Enable paid public use only after live provider
validation and the deployment checks in README.md.
