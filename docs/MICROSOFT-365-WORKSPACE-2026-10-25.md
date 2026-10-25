# Microsoft 365 workspace

Requested identities: iwaithakaw@gmail.com, isaac@goblisgroup.com, isaac@goblisfoundation.com. The owner subsequently confirmed isaac@goblisfoundation.org as correct and isaac@goblisgroup.com as its alias. The Group slot therefore connects the canonical Foundation .org account once and displays the Group alias. The .com Foundation address remains separate and unverified; its relationship is not inferred. Alias sending is not enabled by this read grant. Provisioned Exchange mailboxes and licensed Teams hosts are not assumed.

## Implemented
- Owner-only /write/microsoft, independent encrypted grants, Graph identity matched against requested mail or UPN, explicit confirmation, session-bound ten-minute one-use state, PKCE, stale-grant checks and advisory locks.
- Existing registered meetings_microsoft callback reused only for workspace-specific matching state. Existing meeting grant retained.
- Delegated reads: User.Read, offline_access, Calendars.Read, Mail.ReadBasic, Contacts.Read, Files.Read, Tasks.Read, Notes.Read. Grant status is distinct from successful service access.
- Explicit reads return up to 20 events for seven days, mail headers, contacts, root file names, To Do lists or notebook metadata. No bodies, attachments, file content, automatic replication or AI processing. Results are request-local.
- Combined Outlook agenda labelled by source account. Per-account errors remain unknown rather than empty or free.
- Owner opt-in to booking conflict checks. All selected calendars must respond completely; failures and pagination hold public slots. Only busy intervals enter booking calculations. Reconnection preserves selected conflict checks and fails closed until confirmed. Disconnect requires removing conflict selection first.
- Desktop Outlook, Teams and OneDrive setup guidance. Website OAuth does not configure Windows or merge accounts. Gmail mail requires Google authorization even when its address is used for a Microsoft identity.

## Remaining capabilities
Mail sending and drafts, calendar changes, Teams creation/chat, SharePoint libraries, Planner, Office document editing, Forms, Power Automate and Copilot need further implementation and appropriately separate grants. Personal Graph onlineMeeting creation is unsupported. No claim that all Microsoft capabilities are implemented or that the three accounts are connected before actual consent.

## Newly requested social and fitness accounts
LinkedIn member: https://www.linkedin.com/in/iwaithakaw/ (never a Page destination).
Requested iwaithakaw: WeChat, Snapchat, Telegram, Signal, Threads, Reddit, Samsung Health, Google Fit, Strava and Nike Run Club. These are not connected by this release. A username is not proof of OAuth identity. Bots/channels are not silently substituted for personal accounts. Social/messaging and private fitness permissions are separate. Google Fit migration must be considered.

## Primary references
- https://learn.microsoft.com/en-us/graph/auth-v2-user
- https://learn.microsoft.com/en-us/graph/permissions-reference
- https://learn.microsoft.com/en-us/graph/api/user-list-messages
- https://learn.microsoft.com/en-us/graph/api/todo-list-lists
- https://learn.microsoft.com/en-us/graph/api/application-post-onlinemeetings
- https://support.microsoft.com/en-us/accounts-billing/manage/combining-microsoft-accounts
- https://support.microsoft.com/en-us/outlook/getstarted/add-an-email-account-to-outlook-for-windows
- https://developers.strava.com/docs/authentication/
- https://developer.samsung.com/health/data/overview.html
- https://developers.google.com/fit/overview

## Validation
Unit coverage: identity mismatch, owner isolation, actual scopes, fixed endpoints, bounded projection, OAuth state replay/expiry, refresh retention, incomplete/malformed busy intervals and confirmation revisions. HTTP coverage: authentication and owner guards. Production account access remains unverified until consent and live service reads.
