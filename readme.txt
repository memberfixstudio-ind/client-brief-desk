=== Client Brief Desk ===
Contributors: memberfixstudio-ind
Tags: client portal, onboarding, brief, approval, workflow
Requires at least: 6.8
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html

Collect client answers, request revisions and approve an exact version in a private WordPress workspace.

== Description ==
Create a clear project checklist for an existing WordPress client account. Clients save answers at their own pace and submit for review. Administrators request revisions or approve a version, with immutable submission snapshots, activity history and private staff notes.

Includes service, website and event presets, required-answer progress, due dates, scoped JSON export and WordPress privacy export/erasure. No external service, telemetry, paid dependency, file uploads or automatic email.

Requires InnoDB database tables. Single-site support; network activation is not validated. This is an operational review workflow, not an electronic signature system. Exclude logged-in portal pages and REST routes from shared caches.

== Installation ==
1. Upload and activate client-brief-desk.zip.
2. Add [client_brief_desk] to a published WordPress page.
3. Create or select an existing client account in WordPress; Subscriber is sufficient.
4. Open Client Brief Desk, create a brief and share the portal page yourself.

== Frequently Asked Questions ==
= Can clients see other clients' work? =
The API scopes lists, details, writes and exports to the assigned account. Administrators can manage all briefs.

= Does it send reminders or collect files? =
No. This release focuses on text briefs and review. No outbound communication is sent.

= What happens on uninstall? =
Records remain unless CBD_DELETE_DATA is explicitly true. Archive and delete specific briefs in the interface or use WordPress's personal-data erasure tools before deleting the client account. Deleted-account records require manual administrator review and deletion.

== Changelog ==
= 1.0.0 =
* Initial release: client briefs, private portal, exact-version review, snapshot history, notes and privacy tools.
