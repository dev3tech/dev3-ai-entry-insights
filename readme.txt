=== DEV3 AI Entry Insights for Gravity Forms & WPForms ===
Contributors: dev3tech
Tags: gravity forms, wpforms, contact form 7, lead scoring, ai
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI summary, sentiment, intent, urgency and lead score for every Gravity Forms, WPForms and Contact Form 7 entry, with reply drafts, auto-tagging and an insights dashboard.

== Description ==

DEV3 AI Entry Insights reads every form submission for you and tells you which ones matter.

* **Works with** Gravity Forms (GFAPI + hooks, entry meta box, notes, import), WPForms Lite and Pro (Lite entries are stored by this plugin) and Contact Form 7.
* **Entry insights:** summary, intent, sentiment, category, urgency, lead score 0–100 with a reason, language and suggested tags. Analysed in the background, so forms never get slower.
* **Rule-based fallback:** configurable scoring signals, keyword points and auto-tag rules. Fully usable with AI off or without a key.
* **Entries inbox:** views (new, hot leads, needs attention, starred, replied, archived), filters, search, bulk actions, CSV export, notes and history.
* **Insights dashboard:** trends, sentiment per day, score distribution, categories, intents, tags, per-form quality, and an AI trend digest you pin when you like it.
* **Reply drafts:** DEV3 AI writes a reply in the sender's language and your brand voice; you edit and send it. Templates work without AI.
* **Alerts:** email for hot leads, urgent or unhappy entries.
* **Privacy first:** phone, password, hidden, file, payment and consent fields are never sent to the AI; emails become their domain. GDPR export/erase and retention included.

AI is powered by Google Gemini using your own API key. The key stays on your server.

== Installation ==

1. Upload the plugin zip in Plugins → Add New → Upload Plugin and activate it.
2. Activate Gravity Forms, WPForms or Contact Form 7.
3. Open DEV3 AI Entry Insights → API Config and add your Google Gemini API key (optional).
4. New submissions appear under Entries. Import older Gravity Forms / WPForms Pro entries on the Forms screen.

== Frequently Asked Questions ==

= Does it work without an API key? =
Yes. Rule-based scores, sentiment, urgency, categories, tags, alerts, templates, the dashboard and export all work without AI.

= Is Gravity Forms required? =
No. Any of Gravity Forms, WPForms (Lite or Pro) or Contact Form 7 is enough.

= What is sent to Google Gemini? =
The site name, the form title and text answers. Phone numbers, passwords, hidden, file, signature, payment and consent fields are never sent, emails are reduced to their domain and names to the first name.

== Privacy ==

This plugin sends form answers (minimised as described above) to Google Gemini when AI is enabled and an API key is set. Captured entries are stored in your WordPress database and included in the personal data export and erase tools.

== Screenshots ==

1. Insights dashboard with a pinned trend digest.
2. Entries inbox with scores, sentiment, urgency and tags.
3. Entry drawer with DEV3 AI insights.
4. Reviewing a DEV3 AI reply draft before sending.
5. Connected form plugins and per-form switches.
6. Works on phones.

== Changelog ==

= 1.0.0 =
* Initial release.
