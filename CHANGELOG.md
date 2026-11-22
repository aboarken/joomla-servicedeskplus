# Changelog

All notable changes to `com_servicedeskplus` are documented in this file.
Versions follow the component manifest (`servicedeskplus.xml`) and use [Semantic Versioning](https://semver.org/).

## [1.32.0] - 2026-11-22

### Changed
- **Find Endpoints is organised like the other portals' pages: the answer first, exploring last.**
  - **One run:** *Find Endpoints*, on the page or in the toolbar, with the optional requester e-mail, runs every check at once. It replaces *Run Diagnostics* and *Check which endpoints answer*.
  - **Warnings at the top:** an http address, the certificate check turned off, no API technician e-mail, or new users matched automatically.
  - **Server card:** address, certificate (verified, pinned key or not checked), connect via, timeouts, response time and when it was checked.
  - **API key card:** whether the key was accepted, how many requests the API technician sees, the API technician e-mail, which approvals are shown, how new users are matched, and the cache.
  - **Endpoints the portal uses:** a table with what each is used for, its result (*Allowed*, *Refused*, *Not on this build*, *Error*), its time and its rows.
  - **Requester card** when an e-mail is given: whether the person can be matched, and their requests, assets and loans.
  - **Diagnostics report** to copy, as before. It now also lists the warnings and each endpoint's time.
  - **Advanced** (closed until used): the other known endpoints with their results and documentation links, and *Check an Endpoint*.

## [1.31.7] - 2026-11-22

### Changed
- **Find Endpoints:**
  - **Diagnostics:** the requester e-mail and *Run Diagnostics* now fill the row.
  - **`/api/v3/asset_loans`** is now in the list as an endpoint the portal reads (*My Loans → Returned*, Dashboard), so the check and Diagnostics test it.
  - **`/api/v3/categories`** is marked as read by the portal (*My Requests*, Category filter), so Diagnostics shows its fields.
  - The requester check in Diagnostics also counts the person's loans (`loaned_to.id`).
  - The report shows whether new users matched by e-mail are approved automatically or wait for an administrator.

## [1.31.6] - 2026-11-22

### Security
- **The API technician can't be linked to a portal user.** The account set under *Integration Settings → Approvals* decides approvals on approvers' behalf and may hold approval entries of its own. Matching by e-mail now skips it, *User Mappings* refuses it, and an existing link to it is suspended.
- **"Show approvals for" is enforced.** A record type that is switched off can no longer be opened, downloaded from or decided by typing its address; before, it was only hidden from the list.
- **Approval comments are sent as text.** `<`, `>` and `&` in a comment or an approver's name are written as entities, so they can't become markup where ServiceDesk Plus shows the comment. The portal shows them as typed.
- **Size limit on ServiceDesk Plus answers:** 32 MB (just above the 30 MB attachment limit). A bigger or endless answer is cut off instead of filling PHP's memory.
- **Attachment previews:** a previewed PDF may not submit forms or change its base address, and no file is shared with other sites (`Cross-Origin-Resource-Policy`, `Cross-Origin-Opener-Policy`).
- An internal helper of the approval controller could be called from a URL (it only gave an error page). It moved out of the controller.

### Changed
- **Release workflow:**
  - The build only has read access, so a pull request's build scripts can't change releases. A separate *publish* job, which runs no build script, attaches the files.
  - Manual runs publish only from `main`, and an existing release is replaced only when *Replace the files of an existing release* is ticked.
  - The manifest version must look like 1.2.3 and is passed to scripts as a variable.
  - `actions/checkout` and `actions/upload-artifact` are pinned to exact commits (v7.0.1, v7.0.2) instead of `@v4`.
- **Dependabot** (`.github/dependabot.yml`): a weekly pull request when a pinned action has a new version.
- **CODEOWNERS** (`.github/CODEOWNERS`): the owner reviews every change, in particular the workflow, the build scripts, the update server files, the manifest and the install script.
- README: how to report a security problem and how the release is protected.

## [1.31.5] - 2026-11-20

### Fixed
- **My Loans → Returned: the table did not fit.** Its columns were squeezed and names broke over several lines. Each cell now stays on one line (comments still wrap) and the table scrolls sideways inside its box on narrow pages; it can also be scrolled with the keyboard.

## [1.31.4] - 2026-11-20

### Added
- **My Requests: a Category filter** next to *Type*: *All categories* and the categories of ServiceDesk Plus (`/api/v3/categories`, deleted ones left out, cached); when ServiceDesk Plus does not give them, the categories of the user's open and on-hold requests. It works with the category cards: choosing a card selects it here and the other way round.

### Changed
- *Request type* is now just **Type**.

## [1.31.3] - 2026-11-20

### Changed
- **My Requests:** the category cards no longer have an *All categories* card or the line "Open and on-hold requests by category." above them. Clicking the selected category card again shows all categories.

## [1.31.2] - 2026-11-20

### Changed
- **Cards above the lists fit on one row:** up to 8 cards share the row in equal columns on screens 768 px and wider (more than 8 wrap, so they stay readable); two per row on phones. Long names are shortened with "…".
- Announcement types: ServiceDesk Plus offers no list of announcement types (`/api/v3/announcement_types`, `/api/v3/announcementtypes` and `/api/v3/announcements/_metainfo` return nothing), so the cards show the types used by current announcements.

## [1.31.1] - 2026-11-20

### Fixed
- **My Requests showed no category cards.** The per-category counts asked from ServiceDesk Plus gave nothing on some servers. The cards now show the **open and on-hold requests per category**, from the same figures as the Dashboard's category chart (no extra calls when the Dashboard was opened), with *All categories* and *No category*; a card shows that category in the list.

### Changed
- **Cards above the lists fill the whole row**, however many there are.
- **Open Requests by Priority:** no names under the bars (the colour key below names them) and no line above the key.

## [1.31.0] - 2026-11-20

### Added
- **Summary cards above the lists**, one per group with its total; a card shows that group, the selected one is outlined:
  - *My Requests:* a card per category with the number of requests for the chosen status, type and search (counted by ServiceDesk Plus; categories without requests are left out), and *All categories*. A card filters the list to its category.
  - *My Approvals:* *All types*, *Requests*, *Changes*, *Releases*, *Purchase orders* (the modules checked in Integration Settings) for the chosen *Show*.
  - *My Loans:* *On loan*, *Due within 30 days*, *Overdue*, *Returned*.
  - *Announcements:* *All types* and a card per announcement type.
- **Dashboard → My Loans card:** a *Returned* row with the number of returned loans.
- **Dashboard → Open Requests by Priority:** a colour key under the bars: each priority's colour, name, count and share.

### Changed
- **Loan names, everywhere:** *All my loans* → **On loan**, *To return within 30 days* / *Loans to return* → **Due within 30 days**, *Previous loans* → **Returned** (*Overdue* stays).

## [1.30.1] - 2026-11-19

### Changed
- **Previous loans show the asset type, model and serial number**, one row per asset (a loan of several assets has a row for each; its number and dates on the first). They are read from the assets themselves when the loan does not carry them (several at once, at most 100 assets per user, kept in the cache); an asset that no longer exists keeps its name. Search also finds the type, model and serial number.

## [1.30.0] - 2026-11-19

### Added
- **My Loans → Show → Previous loans:** the user's returned loans, newest return first, with the loan number, the assets, the loan start, the due date (the extended date when the loan was extended), the return date (marked *Late* when returned after the due date) and the comments. Read from ServiceDesk Plus's loan history (`/api/v3/asset_loans`, up to 300 loans, only those lent to the user); search and sort work as for current loans.

## [1.29.1] - 2026-11-19

### Changed
- **Open Requests by Priority:** the label shown when pointing at a bar has the portal's blue background.

### Fixed
- **Rejecting a purchase order from the portal showed an error although it was recorded.** ServiceDesk Plus shows the rejected entry as *Denied*; the portal expected *Rejected*. Both are accepted now, and the user gets the confirmation.
- **A decided purchase order stayed in My Approvals.** The approver's own entry is no longer removed there and still reads *pending*; the list (and the approval page) now also require the approval level itself to be waiting.

## [1.29.0] - 2026-11-19

### Added
- **Attachments open in a popup:** on the approval page, images (PNG, JPG, GIF, WebP) and PDF files open in a popup over the page, with *Open in new tab*, *Download* and *Close*. Other files are downloaded as before. A file is only shown in the browser when its type is one of these and its content really is of that type; everything else (HTML, SVG, office files, ...) is always sent as a download. The approver check is the same as for downloads.

### Changed
- **Changes and releases are approved like purchase orders:** after the decision is recorded under the API technician, no approval entry is removed (and none after a failed attempt), so ServiceDesk Plus cannot restart the approval. Only requests still have the approver's own pending entry removed, and only while the level is still waiting.

## [1.28.1] - 2026-11-19

### Changed
- **Purchase order approval page: the Description section is divided into Remarks and Terms**, each under its own label ("—" when not filled in). Terms moved there from the Purchase Order section.

### Fixed
- **Open Requests by Priority: a scrollbar appeared under the chart** when the pointer was on the last bar: its hover label reached past the edge. Hover labels of the bars now stay inside the chart, and the chart only scrolls sideways on narrow screens.

## [1.28.0] - 2026-11-19

### Added
- **Purchase order details on the approval page:** PO number, vendor, requested by, owner, created and required dates, cost center, GL code, ship-to address and terms (those that are filled in), the items with quantity, unit price and amount, and the sub total, discount, taxes, shipping, adjustment and total with the currency.
- **Purchase order attachments** on the approval page, taken from the purchase order itself, with the same download checks as for requests.
- **Result messages above the page title** on My Approvals and the approval page (for example "Your decision was recorded"), in the style of the *Updating…* note: green for success, amber for warnings, red for errors. They no longer depend on where the site template shows messages, and stay in place when the page refreshes itself.

### Changed
- **"Back to …" links are buttons** in the style of the page numbers of the lists (request, loan, approval, announcement and new request pages).

### Fixed
- The description of a purchase order approval showed ServiceDesk Plus's internal status text; it now shows the purchase order's remarks.

## [1.27.1] - 2026-11-19

### Fixed
- **Purchase order approvals were undone right after they were recorded.** After the decision was recorded under the API technician (and shown as *Approved*), the portal removed the approver's own pending entry, as it does for requests. On a purchase order ServiceDesk Plus answers a removed entry by restarting the whole approval: status back to *Open*, entries back to *To Be Sent*. The portal no longer removes any approval entry on purchase orders.
- For every module, when the approval level is complete after the decision, no entry is removed at all.

## [1.27.0] - 2026-11-19

### Changed
- **Open Requests by Category is a pie (donut) chart of every category** (up to 40, most requests first, then *No category*), with the total in the middle and a legend of names, counts and shares; a very long legend scrolls inside the card. Colours: with up to six categories each keeps its own colour; with more, the colours alternate around the ring so neighbouring slices differ.
- **Open Requests by Priority is a bar graph:** one bar per priority in its usual colour, with the count above and the name below (the share in the tooltip).
- The cards stay two per row (three rows of two).

### Removed
- The segmented priority bar and its styles.

## [1.26.2] - 2026-11-19

### Fixed
- **Purchase order approvals were reported as done while ServiceDesk Plus had not recorded them.** After the approval e-mail to the API technician, ServiceDesk Plus marks the entry as sent a moment later; a decision sent while it still showed *To Be Sent* was answered OK and ignored, and the portal then removed the real approver's pending entry. Now the portal waits (up to about 8 seconds) until the entry shows as sent, decides, and checks that the entry really shows *Approved* or *Rejected* (deciding once more if needed).
- **The real approver's entry is only removed once the decision shows in ServiceDesk Plus.** If it does not, the API technician's added entry is removed instead, nothing else changes, and the user sees "ServiceDesk Plus did not record this decision, so nothing was changed…".
- The log says why an approval e-mail was not sent (ServiceDesk Plus's mail failed, or the API technician has no e-mail address), and which status the API technician's entry shows after the e-mail.

## [1.26.1] - 2026-11-19

### Fixed
- **Purchase order approvals stopped with "ServiceDesk Plus sent an answer that could not be read"** after the API technician was added as approver. ServiceDesk Plus answers that step for purchase orders in another shape; the portal now finds the new entry by reading the approval level again, and records the decision.
- **A retry reuses the API technician's entry left "To Be Sent"** by an earlier attempt, instead of adding another one.

### Changed
- **Open Requests by Category in colour:** each category's bar has its own colour from the Dashboard's validated palette (the priority chart's colours). A category keeps its colour as counts change; *Other categories* and *No category* stay grey.
- **Dashboard cards two per row:** the three charts and the My Requests, My Approvals and My Loans cards form one grid of two cards per row (three rows when all six are shown; one per row on small screens). A card alone in the last row takes the full width.

## [1.26.0] - 2026-11-19

### Changed
- **Dashboard: "Open Requests by Category"** replaces *Who Is Handling My Requests*: vertical bars of the user's open and on-hold requests per category (the five with the most, then *Other categories* and *No category*), with the count above each bar and the name below (full name on hover). On narrow screens the chart scrolls sideways inside its card.
- **Dashboard charts two per row** instead of three; a chart alone in the last row takes the full width; one per row on small screens.
- The Dashboard asks ServiceDesk Plus for the fields it uses (`fields_required`), including the category, which is not always among the default list fields. A server that refuses this is asked again without it (logged; categories may then show as *No category*).

### Removed
- The *Who Is Handling My Requests* chart and its strings and styles.

## [1.25.0] - 2026-11-19

### Changed
- **Dashboard: "Who Is Handling My Requests" replaces "Requests by Status"**, which repeated the My Requests tiles. It shows the user's open and on-hold requests per technician (the five with the most, then *Other technicians*) and how many are *Not assigned yet*, with names and counts beside each bar. Made from the requests already read: no extra ServiceDesk Plus calls. Results saved before the update show "Shown after the next update of the Dashboard." until the page refreshes.
- **Dashboard: new colours for "Open Requests by Age"**: a warm ramp from light gold (under 1 week) to deep red (over 3 months), darker with age, so older requests stand out.

### Removed
- The *Requests by Status* chart, its strings and styles.

## [1.24.2] - 2026-11-19

### Changed
- **Clearer message when ServiceDesk Plus does not allow a decision.** When neither the approver's decision nor the API technician fallback is accepted, the user now sees "ServiceDesk Plus does not allow this decision from the portal" instead of "for example because the record is closed"; only a closed or cancelled record still says it is closed. The audit log and `administrator/logs/com_servicedeskplus.php` name the module whose *Edit* permission the API technician's ServiceDesk Plus role needs (for example Purchase Orders with only *View*).

## [1.24.1] - 2026-11-19

### Fixed
- **My Approvals opened on *Requests* instead of *All types*.** An empty type was read as "request"; it now means all types.
- **Approval attachments did not show.** The files are now taken from the request, change or release itself, as ServiceDesk Plus sends them with the record; the separate attachments list is only read when the record has none listed. The *Attachments* section is always shown (except for purchase orders), with "No attachments." when there are none, or a note when ServiceDesk Plus could not be read.

## [1.24.0] - 2026-11-19

### Added
- **New users are approved automatically again (default).** *Integration Settings → Users → New users*: **Approve automatically** (a first-visit e-mail match works at once, as before 1.23.0) or **Wait for administrator approval** (the 1.23.0 behaviour). A Joomla e-mail that changes later still suspends the mapping until an administrator approves it, with both choices. Users waiting for approval are matched on their next visit once automatic approval is on, after a fresh check that ServiceDesk Plus still has exactly one user with their address.
- **Attachments on the approval page.** The approval page lists the files attached to the request, change or release, with their size, for download. Every download checks in ServiceDesk Plus that the user is an approver of that record and that the file is listed on it; files are always sent as downloads (never opened as a page of the site), up to 30 MB, and recorded in the audit log. Purchase orders have no attachments in the API.

### Fixed
- **My Approvals (and the other lists) always open on their default filters, such as *All types*.** Browsers could put back the choice made before when a list was reloaded or opened again with Back.

## [1.23.1] - 2026-11-18

Security and clean-up release after an audit of the whole component: security, structure against the Joomla Manual, and unused code.

### Security
- **Confirm checks the e-mails again.** *User Mappings → Confirm* now refuses a mapping when the e-mail of the ServiceDesk Plus user found for the account is not the account's current Joomla e-mail. Before, someone could set a colleague's address, open the portal once (found, *Waiting for approval*), change the address back, and be confirmed together with others. Linking different addresses on purpose is still possible by opening the mapping and saving it.
- **Pages of one record are never shown from older results.** Since 1.21.0 a request page could be shown from a copy up to 7 days old while its conversation and approvals were read live, also for a request that had been given to someone else in ServiceDesk Plus. Now only the Dashboard and the lists (which hold the user's own rows) open with older results; a request, loan, approval or announcement page always checks ServiceDesk Plus again after the cache time, and a "not yours" answer deletes the older copies.
- **Confirmed mappings are checked against ServiceDesk Plus once a day again.** Since 1.23.0 every new mapping was stored as made by an administrator, which is never re-checked. A confirmed e-mail match is an e-mail match again: it is suspended when the ServiceDesk Plus user is gone or its e-mail changed.
- **Cached results are deleted when they must no longer be used:** a user's entries when their mapping is suspended, changed or matched again; every entry when the ServiceDesk Plus address changes. At most 150 entries are kept per user (many different searches could fill the table).
- **Background update:** only this site's own address is ever loaded; a page that became another page (login, *could not be matched*, *waiting for approval*) is loaded normally instead of keeping the older content.
- **Without JavaScript** the Dashboard and lists no longer stay on older results: the page reloads itself once for current ones.

### Fixed
- Two accounts found for the same ServiceDesk Plus user at the same moment: the second is stored as *SDP user already linked* instead of showing an error page.
- User Mappings: searching a long number no longer fails on PostgreSQL.
- Updating removes the old session cache class left by versions before 1.21.0.

### Changed (Joomla Manual)
- Saving a mapping passes through the form's filter and validation on the server.
- No HTML in the *waiting for approval* notice texts.
- The installer checks for the PHP cURL extension, which every ServiceDesk Plus call needs.
- **SECURITY.md**: how to report a security problem privately. README: the portal's permissions are not granted to any group after installation; cache behaviour; project structure.

### Removed
- Unused code: `ListPage::$startIndex` / `$rowCount`, `ListPageTrait::$total`, the unused payload of the `sdp:refreshed` event; outdated comments.

## [1.23.0] - 2026-11-18

### Security
- **Every new match by e-mail waits for an administrator.** Joomla lets users change their own e-mail without verifying it, so someone who had never opened the portal could set a colleague's address and be matched to that colleague's ServiceDesk Plus account. Now the ServiceDesk Plus user found by e-mail is stored as **Waiting for approval**, and the user sees *"Your account was found in ServiceDesk Plus and is waiting for an administrator to approve it"* until an administrator confirms it in **User Mappings** (select, **Confirm**; several at once).
  - *User Mappings* shows how many users wait, with a link to them, and has the status filter *Waiting for approval*.
  - **Match again** also ends in *Waiting for approval*, so it can no longer skip the check.
  - While a user waits, no ServiceDesk Plus call is made for them; if their e-mail changes meanwhile, the mapping is suspended.
  - Users already matched are not affected, and mappings an administrator makes (*New*, *Confirm*) are active at once.

## [1.22.2] - 2026-11-18

### Changed
- **Dashboard → Employee Information:** the user's details are now a panel titled *Employee Information* (title in the blue of the cards), like the other Dashboard panels: Department, Job Title, Employee ID and Name, each label above its value, values in the same blue as the cards. A field that is empty in ServiceDesk Plus is left out instead of showing "—".

## [1.22.1] - 2026-11-18

### Fixed
- **Requests by Status:** the Open bar could not be seen when there were far more closed requests (e.g. 65 open and 26,922 closed: under one pixel). *Active* (Open, On Hold) and *Completed* (Resolved, Closed) are now drawn side by side on their own scales, labelled as such.

### Changed
- **Open Requests by Age** is now a donut with the total in the middle and a legend with counts and shares.
- **Open Requests by Priority** is now one bar split by priority, with a legend of counts and shares. Each priority keeps its place (Low, Normal, Medium, High, Urgent, Critical, then other names) and its colour; the colours were checked for colour-blind readers.
- Large numbers on the Dashboard have thousands separators (26,922).

### Removed
- *Show figures* under the charts. Screen readers still get the figures of each chart as a table.

## [1.22.0] - 2026-11-18

### Added
- **Dashboard → Your details:** the user's **Department, Job Title, Employee ID and Name** at the top, from their own ServiceDesk Plus user record (`GET /api/v3/users/{id}`: `department`, `jobtitle`, `employee_id`, `name`; the Joomla name when ServiceDesk Plus has none). Read once and cached like the other results; when it cannot be read the Dashboard simply shows no header.
- **Dashboard charts**, made from the open and on-hold requests already read for the figures (no extra ServiceDesk Plus calls):
  - *Requests by Status:* Open, On Hold, Resolved and Closed as stacked bars of incidents and service requests (resolved and closed without the type split show one grey bar); each bar opens My Requests with that status.
  - *Open Requests by Age:* under 1 week, 1–4 weeks, 1–3 months, over 3 months.
  - *Open Requests by Priority:* most frequent first, then requests without a priority; past six priorities the rest are added up as *Other*.
  - Each chart has a legend or labels, a tooltip on hover and a *Show figures* table; colours are checked for colour-blind readers.

### Removed
- **Dashboard → Announcements** panel and *Announcements* summary card, and **Recent Requests** panel. The Dashboard no longer reads announcements, which saves ServiceDesk Plus calls. The Announcements page itself is unchanged. The *View announcements* permission alone no longer opens the Dashboard.

## [1.21.1] - 2026-11-17

### Fixed
- **Pages showed "Could not update just now" and never updated** on sites whose template puts the class `com-servicedeskplus` on the page `<body>` (e.g. Helix Ultimate and other templates that add the component name to the body). The background refresh took the whole page for the portal content: the note appeared above the site header and the fresh page was rejected. The portal content is now found by its own `data-sdp-root` attribute.
- The fallback styles in `portal.css` are scoped to the portal content the same way, so they no longer reach the rest of the page on such templates.

### Changed
- When a page cannot be updated, the note says why, e.g. *"(ServiceDesk Plus could not be reached.)"* or *"(HTTP 500)"*, and the reason is written to the browser console.

## [1.21.0] - 2026-11-17

### Changed
- **Every portal page opens at once.** When a user's ServiceDesk Plus results are older than the cache time, the page is shown straight away with their last results (up to 7 days old) and the note *"Showing your data from … Updating…"*, and the page loads itself again in the background and replaces its content with the fresh version. This covers the Dashboard, My Requests, My Approvals, My Loans, Announcements and the detail pages.
  - If the user has started typing or choosing something on the page, it is not replaced: *"Newer data is available. Reload the page"* is shown instead, so nothing entered is lost.
  - If ServiceDesk Plus cannot be reached, the last results stay, with *"Could not update just now"*.
  - If the page no longer exists for the user (e.g. a request that is no longer theirs), it is loaded normally, so the older copy is never kept.
  - A user's own new requests and approval decisions still clear their results at once, so they never see an older state of their own action.
  - Without JavaScript the page shows the last results; reloading it after the cache time reads them again.
- **The per-user cache is kept in the database** (new table `#__sdp_cache`) instead of the Joomla session, so it survives logout and session expiry, and pages loading at the same time never overwrite each other's results. Entries are only ever shown to the same Joomla user with the same ServiceDesk Plus mapping, and are deleted after 7 days.
- *Integration Settings → Limits → Cache time*: description updated (results are used as they are for this time, then shown while refreshing).

### Removed
- The session-based cache (`StoredUserCache`).

## [1.20.1] - 2026-11-16

Security and clean-up release after a review of the whole component.

### Security
- **No automatic matching after an e-mail change.** A Joomla user who was not matched (or matched ambiguously) and then changed their e-mail was matched automatically to the ServiceDesk Plus user with the new address. Anyone could take over the requests and approvals of a person who has no portal account yet by entering that person's address in their own profile. Now any changed e-mail suspends the mapping until an administrator checks it (*User Mappings*), as it already did for active mappings.
- **Several ServiceDesk Plus users with one e-mail** are no longer resolved to the newest one: the mapping is *ambiguous* and an administrator chooses.
- **The API key must be entered again** when the ServiceDesk Plus address, *Connect via*, the certificate pin or CA file changes, or the certificate check is turned off. Before, someone allowed to configure the component (without being a Super User) could point the stored key at another server and receive it with *Test connection*.
- **Pages could be opened without their permission** through the inherited `display` task of the request, approval, settings, user-mapping and Find Endpoints controllers (e.g. `task=request.display&view=announcements`), skipping the check of *View announcements*, *View requests*, … on the site and of *Super User* (`core.admin`) in the administrator. Only the user's own data could be read; these tasks are now refused.
- **Pinned certificates, fail safe:** the pin is set before the usual certificate check is switched off, and a cURL build that cannot check a pinned key now refuses the connection instead of sending requests unchecked.
- **One decision per approval level at a time:** a double submit, a second tab or another approver of the same level now waits for the first decision and then finds the level decided, instead of adding contradictory decisions through the API technician account.

### Fixed
- When ServiceDesk Plus cannot be reached, the parallel approval check (1.20.0) tried every read again one by one, which could keep a page loading for minutes. Failed reads are now tried once more together.
- Approval decision: after an error the user is sent back to the approval (ids were checked together, which failed for long ids); an expired page says so instead of "already submitted".
- Integration Settings and the mapping form: values of a rejected save are shown once (the mapping form only for the same user), not on every later visit; with the mapping form this could save one user's values while editing another.
- Mapping the same ServiceDesk Plus user from two requests at once shows "already mapped" instead of a database error.
- CA bundle field: Windows paths and paths with spaces were silently emptied; the file must now exist and be readable.
- The search for a user's e-mail reads up to 500 results (was 100), so the exact address is found among partial matches.

### Removed
- Unused code: `SdpClient::listAssets()`, `IdentityResolver::reset()`, `PageStats::snapshot()`, `RequestStats::COUNTED_BUCKETS`, `FieldInspector::TYPES`, an unused property of the My Approvals view, six unused Dashboard strings, and the unused Bootstrap grid, utility and alert fallbacks in `portal.css` (the pages use the component's own classes since 1.18).

### Changed
- The package is built from the files tracked in git only; `.gitignore` lists editor files.
- README: licence (GPL-2.0-or-later), requirements (Joomla 6.0+, PHP 8.3+), how the pages are styled, project structure, On-Premises API documentation link, and a security section.

## [1.20.0] - 2026-11-16

### Changed
- **Faster pages: connections are reused.** Reads from ServiceDesk Plus no longer open a new connection each time: DNS results, open connections and TLS sessions are shared for the rest of the page load, so only the first call pays for the lookup, the connection and the handshake. This speeds up every portal page. Certificate check, pinned key, *Connect via* and timeouts work as before (same CA file as the Joomla HTTP client). Writes (new requests, decisions, notes, uploads) are sent as before.
- **Faster My Approvals and Dashboard: approvals are checked in parallel.** The approval levels and approvals of the records waiting for approval are read several at a time instead of one after another. On a test server answering in 100 ms, the scan of 35 records (75 calls) went from 10.8 s to 2.6 s, with the same result. A record that cannot be read is still skipped and logged.

### Added
- **Integration Settings → Limits → Parallel API calls** (1 to 10, default 6): how many reads are sent at once. 1 sends them one after another, as before. Lower it if ServiceDesk Plus answers "too many requests".
- **Timing in the log** (`administrator/logs/com_servicedeskplus.php`): each call shows where its time went, e.g. `-> HTTP 200, 240 ms (DNS 2 ms, connect 15 ms, TLS 80 ms, server 140 ms)` or `(reused connection, server 140 ms)`, and every page load ends with one summary line: `SDP page summary: 75 calls, 2630 ms waiting for ServiceDesk Plus, slowest call /api/v3/requests 144 ms`.
- **Find Endpoints → Diagnostics → Timing**: three small reads (new or reused connection, DNS, connect, TLS and server time) and six reads at once, so the report shows whether slowness comes from the network or from ServiceDesk Plus itself.

## [1.19.0] - 2026-11-16

### Added
- **Find Endpoints** (*Components → ServiceDesk Plus → Find Endpoints*, and a button on Integration Settings), as in the ZKBioTime component:
  - **Diagnostics** at the top: one button runs every check from the Joomla server: the connection, a few rows of every endpoint the portal reads (requests, approvals, changes, releases, purchase orders, assets, announcements, users), and whether the other known endpoints answer. With a requester's e-mail it also checks that the portal can match that person and counts their requests and assets. It shows one plain-text report with a **Copy report** button.
  - **1. Known API Endpoints:** ServiceDesk Plus offers no list of its API endpoints on the server, so the page lists the 52 top-level list endpoints of ManageEngine's *ServiceDesk Plus On Premise* API collection ([servicedeskplus.docs.buildwithfern.com](https://servicedeskplus.docs.buildwithfern.com/servicedesk-plus-on-premise)), the portal's own first (marked *portal*), each with its section, its name there and a link to its documentation page. **Check which endpoints answer** reads one row of each and shows *Answers* with the total, or why not (*Not on this build*, *No permission*, …).
  - **2. Check an Endpoint:** any `/api/v3` path, optionally with a search field and value (e.g. `status.name` = `Open`); a path ending in an id is read as one record. It shows the field paths, the kind of value in each (date, number, text, list, …) and the status texts found.
  - `SdpClient`: assets (`GET /api/v3/assets`) and the attachment upload (`PUT /api/v3/requests/{id}/upload`, field `input_file`) are no longer marked unverified: both are in the On Premise API collection.
  - Only field paths, kinds of values, counts and status-like texts (status, priority, urgency, approval status, … not belonging to a person) are shown: never names, e-mails, ids, subjects or other personal values. Only reading (GET) requests are sent. Super User only, every action a POST with a CSRF token, at most 60 per hour, audited (`settings.explore`); results are kept in the administrator's session only.

## [1.18.3] - 2026-11-16

### Changed
- **Repository renamed** to `aboarken/joomla-servicedeskplus`: the update server, changelog and links point to the new address.

## [1.18.2] - 2026-11-16

### Changed
- **Approval comment in ServiceDesk Plus:** the approver's own text now follows the portal line directly, without "Custom comment:", e.g. `Approved by (username) Full Name via the Joomla portal: <the user's comment>`. Without a comment the line still ends "via the Joomla portal.". Decisions recorded earlier keep their wording.

## [1.18.1] - 2026-11-16

### Added
- **Dashboard, Announcements:** *End date* column ("No end date" when there is none).

### Changed
- **Filter bars** size each list to its longest option, so *Waiting for my approval* on My Approvals is shown in full; fields wrap to a second line on narrow screens.
- **Announcements list:** the *Posted by* column is removed. It is still shown on the announcement's own page.

## [1.18.0] - 2026-11-16

### Added
- **Dashboard, My Requests block:** *Resolved* and *Closed* tiles next to *Open* and *On Hold*. They are counted by ServiceDesk Plus (one-row searches with a total count), so they do not slow the Dashboard down. Each tile lists incidents first, then service requests.

### Changed
- **Open** colour is now #17426f (cards, tiles, badges, buttons).
- **All portal pages use the Dashboard design:** My Requests, My Approvals, My Loans and Announcements have a filter panel and a table panel with coloured status, type and decision badges; request, approval, loan and announcement pages show their fields in panels, the conversation as a timeline, and coloured Approve / Reject buttons; the request form is a panel too.
- **Filters apply at once:** there is no Search button any more. Choosing a status, type, show or sort option applies it; the search text is applied with Enter (or when cleared).

## [1.17.0] - 2026-11-16

### Changed
- **Dashboard redesign:** coloured summary cards (blue open, amber on hold, purple waiting approval, red/amber/green loans to return, teal announcements) that lift on hover; *Announcements* and *Recent Requests* in panels with a *View all* link and coloured status and type badges; *My Requests* as two tiles (Open, On Hold) with the service and incident split; *My Approvals* and *My Loans* as lists with coloured counts (green for approved and nothing overdue, red for overdue). The Dashboard has its own styles, so it looks the same on any site template.
- **My Requests filter** is labelled *Request type*, with the options *All types*, *Incident* and *Service*.

## [1.16.0] - 2026-11-16

### Added
- **My Requests type filter:** *Type* (all types, incident, service request) between *Search* and *Status*.
- **Loan details:** purchase date, warranty expiry date, department and the user the asset is loaned to.

### Changed
- **Repository renamed** to `aboarken/servicedeskplus`: the update server, changelog and links point to the new address.
- **Dashboard is faster:** only open and on-hold requests are read from ServiceDesk Plus (up to 500 of each, one search per status); resolved, closed and canceled requests are no longer counted.
- **Dashboard layout:** the *On Hold requests* card replaces *Overdue requests*; *Recent Requests* (open and on-hold only) sits under *Announcements* with the same heading size; *My Requests*, *My Approvals* and *My Loans* sit side by side in three columns (stacked on small screens), with the same tables as before.
- **Any site template:** the pages no longer depend on Cassiopeia's styles. Zero-specificity fallbacks in `portal.css` cover templates without Bootstrap, and the pagination and conversation layouts can be overridden in the template (`html/layouts/com_servicedeskplus`).

## [1.15.0] - 2026-11-15

### Added
- **Joomla changelog:** the version number in *System → Manage → Extensions* and the update view now show what changed. `changelog.xml` is built from this file for every release (`<changelogurl>`, as described in the Joomla manual).

### Changed
- **Cache time is set in minutes** (*Integration Settings → Limits → Cache time (minutes)*, 0–60, default 5). An existing value in seconds is converted, rounded up, until the settings are saved. With 0 every page reads ServiceDesk Plus again, which makes the Dashboard slow; 5 minutes is recommended.
- **Close** button on Integration Settings and User Mappings turns Joomla's red on hover.
- **Dashboard wording:** headings *My Requests*, *Recent Requests*, *My Approvals*, *My Loans*; cards *Waiting approval*, *Loans to return* and *Announcements*.

### Removed
- Unused code left from earlier versions (per-user key context, an unused asset helper and component getter, the announcement excerpt) and a reference to a missing document.

## [1.14.1] - 2026-11-15

### Changed
- **Dashboard** has the 1.11 design again: summary cards (open requests, overdue requests, waiting for my approval, loans to return within 30 days, current announcements), then the five newest announcements, *My requests* with service requests and incident requests by status and the five most recent requests, *My approvals* by type with approved and rejected by me, and *My loans*. Every figure links to its page with the matching filter. Request figures are counted from the user's 1000 most recent requests and include a **Canceled** row.

## [1.14.0] - 2026-11-15

### Added
- **Dashboard** (new menu item type): figures for the logged-in user, each opening its page with the matching filter. *Requests:* all and one per status of the My Requests filter (counted by ServiceDesk Plus, one call per status). *Approvals:* waiting for my approval, approved by me, rejected by me (decisions made through the portal). *Loans:* all, to return within 30 days (including overdue), overdue. *Announcements:* current announcements. Each section only for users with that page's permission; a section that cannot be read shows its error while the others still show. The Dashboard needs any one of the portal permissions.

### Changed
- **My Approvals** and **My Loans** work like My Requests: one filter bar (search, *Show*, type for approvals, sort, Search button), striped table with the ID first and ID and subject/name as links, and pages of 25.
  - My Approvals *Show*: waiting for my approval, approved by me, rejected by me, all my decisions (replaces the separate "My past decisions" table). Up to the user's 200 latest decisions are listed.
  - My Loans *Show*: all my loans, to return within 30 days, overdue. Sort by due date or name.
- Announcements use the same list code (no visible change).

### Removed
- The links between portal pages added in 1.13.0. The file is removed on update.

## [1.13.0] - 2026-11-15

### Added
- **Approvals on the request page:** when a request has approvals, its page shows them by level: approver, status, sent for approval, decided on and comments, plus the request's approval status in the details. Requests without approvals show no approvals section.
- **Links between portal pages:** My Requests, New Request, My Approvals, My Loans and Announcements link to each other at the top of each page. Only pages the user may open are shown; a link uses its menu item's title when there is one.
- **Close** button on the backend pages *Integration Settings* and *User Mappings* (back to the administrator dashboard).

### Changed
- **Announcements** are shown as a table like My Requests: ID, subject (both link to the announcement), type, priority, posted by, start date and end date, with search, type filter, sorting and pages. The announcement page shows its ID and who posted it.
- Joomla review: database queries use `createQuery()` (`getQuery(true)` is deprecated since Joomla 5); every site page loads the portal stylesheet; the manifest has an author URL.

## [1.12.1] - 2026-11-15

### Fixed
- **Internal announcements were never shown.** Announcements created for the team (not public) are now shown to **technicians** — users whose ServiceDesk Plus record has *is_technician* — together with every other announcement in its display period. Requesters still only see public announcements (and group ones for their groups).
- Each load of Announcements writes one line to `administrator/logs/com_servicedeskplus.php`: how many announcements were read and shown, and how many were hidden as outside the display period, internal, or for other user groups.

### Changed
- Messages now say **ServiceDesk Plus** instead of "the service desk", e.g. "The ServiceDesk Plus did not allow this action."

## [1.12.0] - 2026-11-15

### Added
- **Announcements** (new menu item type): ServiceDesk Plus announcements, newest first, with date, type, priority and a short preview; each opens on its own page. Shown only to users with the new **View announcements** permission (`sdp.announcement.view`, not granted by default). Only announcements ServiceDesk Plus shows to requesters are listed: public, inside their display period, and — when limited to user groups — only for members of those groups. Technician-only announcements never appear. The newest 100 announcements are read.

### Removed
- **My Dashboard.** Its files are removed on update, and existing *My Dashboard* menu items are moved to the trash.

## [1.11.0] - 2026-11-15

### Added
- **My Dashboard** (new menu item type): open requests, overdue requests, approvals waiting for the user and overdue loans at a glance; the user's **service requests and incident requests by status** (Open, On Hold, Resolved, Closed, other) with totals; the five most recent requests; waiting approvals by type (the modules ticked in Integration Settings); total and overdue loans. Built from the lists the portal already reads (up to the user's 1000 most recent requests); each part follows the permission of its own page.
- **Conversation on request pages:** the e-mails and notes of a request, oldest first, on the request page and on the approval page of a request. Only items ServiceDesk Plus marks as visible to the requester are shown; technicians' internal notes are never read. The newest 20 items are read.

### Changed
- **Backend addresses:** `view=settings` is now `view=integrationsettings` and `view=usermaps` is now `view=usermappings`. Old links and bookmarks are redirected; files of the old views are removed on update.

## [1.10.0] - 2026-11-14

### Added
- **"My past decisions" shows what each decision was about:** new *Type* and *Subject* columns. The subject and module are stored with each decision (two new columns in `#__sdp_audit`, added by the 1.10.0 database update). Decisions made before this version show "—" as subject.

### Fixed
- **The same approval listed twice.** When an approver is added twice to the same level, ServiceDesk Plus keeps two pending entries. My Approvals now shows one row per record and level, and a decision through the API technician removes all of that approver's pending entries on the level.

### Changed
- The type column in My Approvals is back to **Type** with plain text, as in 1.9.0 (1.9.1's "Approval For" heading and coloured labels are removed; the labels did not suit the site template).
- **Approval comment format** in ServiceDesk Plus: `Rejected by (username) Full Name via the Joomla portal. Custom comment: <the user's comment>`. The user's Joomla username is shown in brackets before their name; "Custom comment:" is added only when the user wrote a comment. The private request note uses the same name.

## [1.9.1] - 2026-11-14

### Fixed
- **Changes, releases and purchase orders were not listed in My Approvals.** On build 16030, `GET /api/v3/approvals` only returns the API technician's own approvals and ignores the approver filter. My Approvals now scans each module like requests: requests and changes with approval status *Pending Approval* (up to 50 and 25), purchase orders with status *Pending Approval* (25), and the 10 newest releases.
- **Approvals of closed records.** ServiceDesk Plus keeps a pending approval on a closed request, but lets nobody change its approvers, so the portal could not record a decision and showed a generic error. Closed and cancelled records are no longer listed, and if it happens anyway the message says the approval cannot be recorded from this site.

- **A rejection could end up as an approval.** ServiceDesk Plus's "Auto-approve requests if already approved by the same approver in the previous stages" (and "… when the requester is the approver") approved the API technician's added entry the moment its approval e-mail was sent, completing the level before the portal's rejection arrived ("Approval(s) doesn't belong to current level"). The portal now re-reads the added entry before deciding: if ServiceDesk Plus already decided it the other way, it does not touch the approver's own entry, removes the added one when ServiceDesk Plus allows it, logs an error for the administrator and tells the user the decision was not recorded. **Turn both "Auto-approve" options off** under Admin → Advanced Portal Settings → Approval.

### Added
- **Integration Settings → Approvals → Show approvals for:** choose which modules My Approvals checks (requests, changes, releases, purchase orders; all by default). Each module adds ServiceDesk Plus calls when the list is built.

### Changed
- My Approvals: the type column is now **Approval For** and shows the type as a coloured label.
- "ServiceDesk Plus did not allow this decision. Please contact IT." now ends with "Please contact the System Administrator."

## [1.9.0] - 2026-11-14

### Added
- **My Approvals covers changes, releases and purchase orders** as well as requests. A new *Type* column shows the module; the approval page shows the change, release or purchase order with its title, description and requester. Approve and reject work for all four, including the API technician workaround (adding the API technician, sending the approval e-mail, recording the decision, removing the approver's pending entry). Problems are not included: ServiceDesk Plus has no approvals for problems.
  - Calls follow ManageEngine's ServiceDesk Plus On-Premises Postman collection: `approve`/`reject` for requests and changes, `_approve`/`_reject` for releases and purchase orders; adding approvers with `{"approvals": [...]}` for changes, releases and purchase orders; `send_notification?ids=` (requests, changes) and `_send_notification` (releases, purchase orders). The other form is tried when ServiceDesk Plus answers "not found".
  - Private notes naming the approver are added to requests, changes and releases. Purchase orders have no notes in the API; the approval comment names the approver.

### Changed
- **Faster My Approvals.** The list now comes from one call, `GET /api/v3/approvals`, filtered by the user's approver id and the pending status, instead of up to 101 calls scanning recent requests. Every returned item is checked to belong to the user. If the build does not support this call, ignores the filter, or returns nothing, the previous request scan runs, so request approvals keep working as before.

## [1.8.2] - 2026-11-14

### Fixed
- **Sending the approval e-mail on ServiceDesk Plus On-Premises.** 1.8.1 used the Cloud addresses (`…/approvals/{id}/_send_notification`), which On-Premises answers with "Invalid URL". The portal now uses the On-Premises form first: `PUT …/approval_levels/{level}/approvals/send_notification?ids={approval_id}` with `{"approval": {"notification": {"title", "description"}}}`, as documented in the On-Premises v3 reference for change approvals. The Cloud forms remain as fallbacks.

## [1.8.1] - 2026-11-14

### Fixed
- **Approving through the API technician failed with "Recommendation mail is not yet sent."** ServiceDesk Plus accepts no decision on an approval until its approval e-mail has been sent. After adding the API technician as approver, the portal now sends that e-mail (to the API technician's mailbox) before recording the decision. Both the `send_notification` and the documented `_send_notification` paths are supported.

## [1.8.0] - 2026-11-14

### Added
- **Approve and reject from Joomla, even though ServiceDesk Plus only accepts the assigned approver.** ServiceDesk Plus refuses decisions sent with the API technician's key for approvals assigned to someone else (code 4201). With the new **Integration Settings → Approvals → API technician e-mail** set, the portal then:
  1. checks, as before, that the logged-in user is the assigned approver and the approval is pending;
  2. adds the API technician as approver on the same approval level (or reuses its pending entry);
  3. approves or rejects as the API technician, with "Approved by *name* via the Joomla portal." and the user's comment as approval comment;
  4. removes the user's own pending entry, so the level shows one decision;
  5. adds a private request note (not shown to the requester) naming the person who decided.
  If the decision fails, the added entry is removed again. When the level also needs other approvers, or the user's entry cannot be removed, the user is told so. Leave the e-mail empty to keep the previous behaviour.
- The portal still tries the direct decision first, so nothing changes where ServiceDesk Plus already accepts it.

### Changed
- Approval comments are sent as one line of at most 250 characters, as ServiceDesk Plus requires; the comment box allows 200 characters.

## [1.7.1] - 2026-11-14

### Fixed
- **Approvals refused by ServiceDesk Plus** (SDP code 4201, "You are not authorized to take action on this approval") now show "ServiceDesk Plus did not allow this decision. Please contact IT." instead of the generic "did not accept the data" message.
- **Short connection drops:** a read from ServiceDesk Plus that fails to connect (for example a dropped TLS handshake) is tried once more after 0.3 seconds. Creating requests and approving or rejecting are never repeated.

### Changed
- The message "The service desk did not accept the data that was sent." now reads "ServiceDesk Plus did not accept the data that was sent."
- Connection failures in `administrator/logs/com_servicedeskplus.php` now include the cURL error text (for example "SSL certificate problem: unable to get local issuer certificate"), so TLS problems can be diagnosed.

## [1.7.0] - 2026-11-14

### Added
- **Updates from the Joomla backend.** The component now has an update server, so new versions appear under **System → Update → Extensions** and install with one click. Every GitHub release carries `updates.xml` (version, download link and SHA-256 checksum), which Joomla reads from `releases/latest/download/updates.xml`. Sites on 1.6.0 or older install 1.7.0 once by hand; later versions then arrive through Joomla.

### Changed
- **Page titles follow the menu item.** My Requests, New Request, My Loans and My Approvals show the menu item's title as page heading and browser title. The menu item's *Page Heading*, *Browser Page Title* and *Show Page Heading* settings are respected. Without a menu item, the component's own titles are used.
- **My Requests:** the *Submit a Request* button has the same size as the *Search* button and lines up with it.
- **Request details:** *Technician Group* is shown before *Technician*. Priority, Urgency, Technician Group, Technician, Category, Subcategory and Item now all show "Not assigned" when empty (Subcategory and Item showed "—").
- **Backend:** "Settings" is now **Integration Settings** and "User mappings" is **User Mappings**.
- **Extension description** (shown after installing): "Integrate ManageEngine ServiceDesk Plus with Joomla to provide a centralized IT self-service portal for users."

## [1.6.0] - 2026-11-14

### Removed
- **Settings → Approvals** section: the *Pending approval status* and *Requests checked for approvals* options. My Approvals keeps working with the built-in values, which match a standard ServiceDesk Plus setup: the status "Pending Approval" and the 50 most recent requests waiting for approval.
- **Test asset endpoint** toolbar button in Settings. It was a one-time diagnostic for checking the asset API; My Loans is not affected. **Test connection** and **Read certificate pin** stay.
- Saved values of the two removed options are deleted from the database when you upgrade.

## [1.5.0] - 2026-11-14

### New Request form
- The menu item type is now called **Request Form** (was "Submit Request").
- The page title is now **New Request** (was "Submit Request").
- The submit button is now labelled **Submit** (was "Submit Request").
- The **Cancel** button is now a neutral grey button, styled like the Search button on My Requests.
- Attachments are limited to **3 files**, **1 MB per file** (3 MB in total). Previously 5 files, 10 MB each, 20 MB in total.
  - Enforced on the server by `AttachmentValidator`: the size of the stored upload is used (never the size reported by the browser), and the file count includes every file sent, valid or not, and is checked before any file is read.
  - The form also checks files as soon as they are chosen and clears a selection that breaks the limits.
  - Error and help texts take their numbers from the validator constants, so they always match the enforced limits.

### My Requests
- The button at the top right is now labelled **Submit a Request**.
- Fixed the Search button: it sat in a one-column grid cell, so its label overflowed or wrapped at tablet and small desktop widths. The filter bar now uses a responsive layout and the button has a consistent grey style and the same height as the inputs.
- The status filter now defaults to **Open** when the page is opened without a filter (menu link, "Back to My Requests"). "All statuses" stays selected while paging (`status=all`). If no status named "Open" is configured, the list shows all statuses as before.

### Request details
- Request details now show **Urgency**, **Technician Group**, **Subcategory** and **Item**, read from the ServiceDesk Plus request (`urgency`, `group`, `subcategory`, `item`). Empty values show "—", and an unassigned group shows "Not assigned".
- Request details cached in the session by an older version still load after the upgrade.

### Other
- The portal stylesheet is now loaded on the New Request page.
- Web asset registry version raised to 1.5.0 so browsers load the new CSS and JavaScript.
- No database schema changes (empty `1.5.0.sql` update files).
- New **Release package** GitHub Actions workflow: builds and checks the installation package for every pull request and attaches `com_servicedeskplus.zip` to each GitHub release.

## [1.4.0]

- Baseline release imported into this repository.
