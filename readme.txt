=== Virtual Try-On Studio ===
Contributors: matashkar91-eng
Tags: virtual try on, woocommerce, avalai, fashion
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 0.4.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Persian RTL scarf fitting room with an independent raw-design gallery,
local Vazirmatn typography, private photos and private result links.

== Installation ==
Upload the plugin ZIP, activate, and open پرو مجازی > طرح‌های روسری.
Add a complete flat scarf artwork image, a name, and enable it. Save the gallery
to use independent designs and economical quality. WooCommerce is not required.
Place [virtual_tryon] on a page. Start in demo mode.
See README.md for private storage, cron, privacy and deployment requirements.

== External services ==
When AvalAI mode is enabled, a customer's photo, the selected full raw scarf
artwork and the prompt are sent server-side to
https://api.avalai.ir/v1/images/edits using the store's API key. The default
independent-design path sends exactly two images, without cropping the artwork.
Optional WooCommerce mode can process up to three product photos locally into
one design board and send it with a full product-context photo.
No images are sent to AvalAI in demo mode. An explicit administrator connection
check sends the saved API key to GET https://api.avalai.ir/v1/models, including
when demo is selected. It retrieves model availability without generating images.
The operator must review AvalAI's current terms, privacy and image-edit API support
before enabling live use.
A live multi-image edit has succeeded on one merchant account; availability,
quality and billing can still differ by model, account and provider policy.
Demo returns the original photo and does not change garments or accessories.

== Upgrade Notice ==
= 0.4.0 =
The default catalog is now an independent raw scarf-design gallery. Upload and
name designs in the new administrator page; WooCommerce products are optional.
Saving the gallery selects raw designs and low quality while retaining the API
key and model. Raw designs are sent whole as the second of two references.
The customer flow and gallery management use local Vazirmatn typography and
responsive, accessible selection and upload controls. No live generation runs
during upgrade or design upload. Exact provider price is not guaranteed.

= 0.3.1 =
Use the featured image and up to two existing WooCommerce gallery photos to
build one scarf-design detail board without requiring a separate upload. Ask
the provider for a newly rendered customer portrait, and never expose a signed
remote result to the customer before local unchanged-image validation. If the
host cannot download an existing result, an administrator can save and import
that same file without submitting or billing another generation. This release
supersedes 0.2.1's customer browser-direct fallback: only a locally stored and
validated output is now presented to the customer.

= 0.3.0 =
The plugin now derives a scarf-design detail from the existing WooCommerce
product photo and sends it before the full store photo. No separate reference
upload is required. It also refuses a provider result that is the unchanged
customer photo and omits the optional complexity parameter used by neither the
proven legacy workflow nor this release.

= 0.2.1 =
When the host cannot download an already-generated signed result, the authorized
customer or administrator can open that same result directly in their browser.
The fallback does not submit another generation request.

= 0.2.0 =
The saved-result downloader now restores its 110-second deadline at the final
WordPress, Requests and native cURL layers. Recovery remains a non-billable GET.

= 0.1.9 =
Downloading an already-generated provider result now has a 110-second budget
instead of 30 seconds. Recovery still uses only the saved result URL and never
submits or bills a new image generation.

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
= 0.4.0 =
Add a dedicated administrator raw-artwork gallery with image selection, names,
active toggles and previews. Separate the default design catalog from optional
WooCommerce products, and preserve omitted settings across the two forms.
Send the complete raw artwork without cropping, in a two-image edit request
with explicit identity and print roles, low quality, one 1024x1024 output and
no complexity override. Redesign customer selection, photo upload, readiness
feedback and result presentation. Bundle Vazirmatn under the SIL Open Font
License with no third-party font request. Retain validated private output and
non-billable result recovery safeguards from 0.3.1.

= 0.3.1 =
Build a single bounded product-design board from detail crops of the featured
image and up to two existing WooCommerce gallery photos, while keeping the
provider request at three images, low quality, 1024x1024 and one output. Require
a newly rendered portrait that uses the customer photo only for identity.
Remove the customer-facing redirect to an unvalidated provider result. Add an
administrator browser-save/import recovery path that performs no generation
request and rejects an unchanged customer photo before completing the job.

= 0.3.0 =
Use the store's existing product image to build a model-resistant scarf design
reference automatically. Send customer identity first, derived product detail
second and the original full product photo only as drape/context. Strengthen
the fixed role prompt so a person in the product photo cannot replace the
customer. Keep low quality, 1024x1024, one output and no complexity override to
match the previously successful low-cost request contract. Detect exact and
conservatively near-identical customer-photo outputs, do not expose them as a
completed try-on, and block the same paid retry.

= 0.2.1 =
Add a browser-direct fallback for saved provider results when server egress to
the image CDN times out. Keep the signed URL out of status JSON and HTML; expose
only a same-origin capability route that revalidates the private reference and
returns a no-store, no-referrer redirect. Add a separate administrator-only
direct-result route and button. Preserve the failed database state for audit and
perform no provider POST, server-side result GET or request-counter mutation.

= 0.2.0 =
Add a temporary, request-scoped transport guard for generated-result downloads.
Restore the bounded timeout, connection deadline, TLS checks, response byte cap,
manual redirect policy and disabled low-speed abort after late third-party hooks.
Remove every temporary hook after the GET and expose only safe diagnostics.

= 0.1.9 =
Increase the bounded, non-billable result-download window to 110 seconds for
slow image CDNs. Keep the saved private recovery reference and add safe elapsed
time diagnostics when a result GET still times out.

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
