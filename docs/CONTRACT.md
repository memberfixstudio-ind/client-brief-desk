# Client Brief Desk 1.0.0 runtime contract

A small business sends a clear brief checklist to an existing WordPress client account. The client saves answers, submits for review; staff requests revisions or approves that exact version. Everything stays in WordPress. Not an e-signature or file-storage system; no outbound email or remote services.

## Data and state

Namespace ClientBriefDesk; bootstrap defines CBD_VERSION, CBD_FILE. PHP8.2+, WP6.8+. Store owns wp_cbd_briefs and wp_cbd_events (InnoDB). One brief: id,title,client_id,created_by,due_date (YYYY-MM-DD or empty in site timezone),status(open/submitted/changes_requested/approved/archived),version integer optimistic lock,questions [{id,label,help,required}],answers {questionid:string},client_note (review feedback visible to client),internal_note (staff only),created_at/updated_at UTC. Max20 questions, title120, label200/help500, answer10000, totalanswers50000, notes5000. Questions IDs q1..q20 assigned once on creation. No question/assignee editing after creation in v1; duplicate creates a new request with empty answers. Client authenticated current user must equal client_id; staff requires manage_options. Only original client can save answers/submit; staff reviews, cannot impersonate clients.

Client PATCH answers in open/changes_requested; saves are explicit. Submit requires all required answers and moves open/changes_requested to submitted. Staff approve or request_changes only from submitted; feedback required for request_changes. Staff reopen only approved -> changes_requested with feedback; staff archive any nonarchived, staff delete only archived with version confirmation, no restore. Approved answers immutable until explicit reopen event. Every change increments version; stale version409 leaves DB unchanged. Audit event snapshots on submission/approval preserve answers/questions; event actors+timestamp recorded in same transaction. UI must not claim anonymous/legal approval. All API reads scoped by actor, 404 for inaccessible IDs. No customer data in page HTML config or public block markup.

## REST v1 (client-brief-desk/v1)

All require logged-in WordPress cookie+nonce. Admin actions require manage_options.
GET /briefs?status=all|open|submitted|changes_requested|approved|archived&search=...&page=1 => {items:[brief summary],total,page,pages,counts:{open,submitted,changes_requested,approved,archived},is_admin,timezone}. limit20 fixed. Search matches title only; client list scoped to client_id. Summary includes id,title,status,version,due_date,answered,required,total_questions,updated_at,client_name (staff only). Counts similarly scoped. No emails returned.
GET /briefs/{id} => full brief (internal_note only staff), client_name, events:[{id,action,actor_name,created_at,version,note,snapshot?}] most recent50. Client sees all lifecycle events except internal_note; no internal notes in client output/export.
POST /briefs admin {title,client_id,due_date,questions:[{label,help,required}]} => full brief201.
PATCH /briefs/{id} client {version,answers:{q1:...}} => full brief.
POST /briefs/{id}/action {version,action:submit|approve|request_changes|reopen|archive|duplicate|delete|internal_note,note?:string} => full brief except delete {deleted:true}; duplicate new brief201.
GET /briefs/{id}/export => scoped JSON {exported_at,brief:full brief} + no-store. Client export excludes internal_note and internal events.
GET /clients?search=... admin => [{id,name}] bounded20 WP users (no emails). Search textmin2 except empty results returns[].
GET /presets admin => {service:[questions],website:[questions],event:[questions]} (labels,help,required). UI can implement known presets locally and edit list; server validates final questions.

## Interface worker contract

Worker owns includes/class-ui.php, assets/admin.js, assets/desk.css only. UI::register(): adds wp-admin menu 'Client Brief Desk' admin-only; shortcode [client_brief_desk] renders client shell. Enqueue wp-api-fetch/wp-i18n + own CSS/JS only shell/admin page. Localize window.clientBriefDesk {root:rest_url('client-brief-desk/v1/'),nonce:wp_create_nonce('wp_rest'),isAdmin,currentUserId,timezone}; root id cbd-app. UI::render is admincallback and shortcode return shell. Use accessible responsive native JS, textContent, explicit labels/focus/error/loading states. Staff cockpit queue/filter/search + create form with presets/customquestions, client search selection; detail split answers/review/history; client portal list and saving/requiredprogress/submit. Show read-only submitted/approved responses. Export JSON via blob using API; print-friendly detail can use print stylesheet. No public demo data seeded on activation, no file upload/email settings.

Logged out shortcode: login link only (wp_login_url safely redirect); no API calls/customer data. Authenticated shell must not emit private details; REST responses Cache-Control private,no-store. UI must handle409 by preserving unsaved text and asking user to reload latest (no auto overwrite/retry). Show portal setup '[client_brief_desk]' + existing user requirement on admin page. No special client role required. Dates displayed in site timezone; due date is date-only. 390px responsive/no horizontaloverflow; no fake metrics or decorative charts. Unrelated pages load no assets.

## History boundary

Detail and JSON export include the latest 50 events visible to that caller. `history` reports `{limit: 50, returned: number, has_more: boolean}`; private staff events do not influence client metadata. The WordPress personal-data exporter pages through retained client-facing history separately.
