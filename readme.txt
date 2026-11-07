=== Virtual Try-On Studio ===
Contributors: matashkar91-eng
Tags: virtual try on, woocommerce, avalai, fashion
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 0.1.8
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
A live multi-image edit has succeeded on one merchant account; availability,
quality and billing can still differ by model, account and provider policy.
Demo returns the original photo and does not change garments or accessories.

== Upgrade Notice ==
= 0.1.8 =
Administrators can run an existing queued request directly from request history
when the host's WP-Cron runner is unavailable. This runs the same request and
does not create a duplicate job.

= 0.1.7 =
The fixed provider instructions now require a visible product replacement and
explicitly reject an unchanged original item. Scarf jobs tell the model to remove
the worn scarf completely and reproduce the selected scarf's print, colors,
border and fabric appearance. Existing store settings remain available.

= 0.1.6 =
Image-edit quality now defaults to low (Economy) and is sent explicitly instead
of leaving the tier to the provider. Existing settings and jobs remain available.
Review the quality field, save settings, and measure one new request in AvalAI
usage before opening the feature broadly. Price is not fixed or guaranteed by
this plugin.

= 0.1.5 =
After confirming version 0.1.5 is active, deactivate only the temporary AvalAI
timeout workaround if you added it in Code Snippets. Existing failed jobs are not
resubmitted. Check AvalAI history before manually rebuilding an uncertain job.

== Changelog ==
= 0.1.8 =
Add an authenticated, nonce-protected "run now" action for queued requests. It
lets the store administrator process the same queued row without hosting access,
while preserving the existing atomic claim that prevents duplicate execution.

= 0.1.7 =
Strengthen the non-editable image-role instructions for scarf, clothing, glasses
and jewelry jobs. Tell the model to isolate the product from its reference photo,
remove the existing matching item, visibly reproduce distinctive product details,
and never treat the unchanged input as a valid result. Preserve merchant prompts
as additional instructions after these fixed requirements. Show the selected
product reference beside the input and output in administrator request history.

= 0.1.6 =
Add a validated image-quality setting with Economy (low) as the safe default,
plus medium, high and provider-controlled auto choices. Send the selected value
in the image-edit request and snapshot it with each new job so its manual retry
keeps that cost profile. Pre-0.1.6 jobs keep an unknown-quality marker and retry
with low. Show the saved quality in request history. No request is generated
during upgrade, and exact provider price remains variable.

= 0.1.5 =
Enforce the configured edit timeout at both WordPress HTTP and cURL layers,
only while the single AvalAI image-edit request is active. Disable cURL's
low-speed abort for that request and record safe guard diagnostics on failure.
Keep TLS verification and redirects locked down, and never retry a paid edit
automatically. External hosting or upstream time limits can still apply.

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
