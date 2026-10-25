# Giving defaults and Wise callback repair
Card is first and selected, USD is the card default. Bank transfer is a peer method: selecting it replaces checkout fields with Wise receiving details. USD is the first bank account and opens by default. Each private receiving account keeps its own save control.
GET/HEAD /webhooks/wise returns an availability response only. POST always verifies RSA-SHA256 against the selected environment's pinned Wise key before inspecting the test marker. Valid signed test notifications can acknowledge reachability before the subscription exists; they write no events, enable nothing and confirm no payments. Live events still require an enabled, verified profile, exact subscription and supported schema/event. Unsigned POST remains 401.
Use Account deposit events for incoming support, not Transfer update events. Then save the subscription ID in the private dashboard and enable receiving after profile verification. Token/profile mismatch is now resolvable with a read-only accessible-profile lookup.
Official references reviewed 25 October 2026:
- https://docs.wise.com/guides/developer/webhooks
- https://docs.wise.com/guides/developer/webhooks/event-handling
- https://docs.wise.com/guides/developer/webhooks/subscribe-to-webhooks
No transfer, settlement, real payment or supporter notification was initiated.
