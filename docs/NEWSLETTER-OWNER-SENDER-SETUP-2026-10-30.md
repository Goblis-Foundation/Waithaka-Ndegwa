# Newsletter owner sender setup

The live owner-workspace audit found that the required subscriber-email mailing address could only be configured through deployment environment settings. `/write/newsletter/sender` completes that missing dashboard journey.

Only the current workspace owner can read or save the address. Saving requires authentication, CSRF, a rate limit, explicit footer review and the exact current version. The address is stored in one encrypted communication record under a named update lock. A blank value is an explicit override that blocks address-dependent newsletter approval rather than silently restoring an old environment value. An owner change cannot silently inherit a previous owner's footer disclosure. The initial deployment address remains the default until an owner actually saves the form.

The configured sender name, From and reply-to are displayed for review; this form only changes the postal footer. It does not connect a mailbox, widen OAuth rights, change transport, test an inbox, enrol subscribers, enable a worker or release an outbox. Existing newsletter revision and monthly-policy sender fingerprints consume the effective address. Changing it causes subsequent delivery checks to hold an old approved sender until review; messages already accepted by a provider cannot be recalled. Historical snapshots retain their exact original sender.

Setup is linked from the owner launch-readiness stream and newsletter editions, with direct links to monthly policy and operations. The address is escaped in the form, excluded from the public website and excluded from generic form assistance. It appears in subscriber email only through the existing explicitly approved newsletter snapshot and recipient/consent gates.

Acceptance includes pure owner/version/snapshot tests, actual isolated HTTP GET/POST/CSRF/409 behavior, encrypted-at-rest and no-mail/no-public-disclosure checks, responsive rendering and axe scans of the production template. The real address itself must be supplied and approved by the owner; none is invented or copied from another organisation.
