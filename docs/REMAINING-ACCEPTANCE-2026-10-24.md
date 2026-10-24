# Remaining acceptance work — 24 October 2026

## Live observations
The owner signed into the private portal using secure browser authentication. Portal home exposes Daily program. A synthetic Studio request reached Gemini but returned a provider server error; no article, appointment or attendee notification was created. Account device alerts and daily-program reminders are now enabled. Background communication worker heartbeat is current. There are no registered push devices, so real-device delivery remains unverified. Inactivity lock is set to Keep my session.

## This release
- Stateless Gemini Interactions requests for ungrounded drafting and program interpretation, with store=false and bounded output.
- Structured document output and strict completed-model-text parsing. Provider thoughts, credentials and raw error bodies remain private.
- Explicit model availability refresh using the saved key; model selection remains an owner choice. No billing account or API key is created.
- AI-specific safe errors, without irrelevant publication-history advice. No automatic generation retry or silent model fallback.
- Program imports capped at 100 editable entries to stay within ordinary PHP form limits; a final completion marker rejects truncated submissions before creating entries. Pasted source text survives validation failure.
- Push setup distinguishes saved account preferences from browser permission and connected device registration. Named devices and disabled empty-recipient test actions reduce ambiguity.

## External checks still required
A phone/computer must grant notification permission and connect itself. A cloud-browser notification test cannot certify the owner's physical phone. Live Gemini generation and a synthetic PDF interpretation must be repeated after deployment; catalog access alone does not prove generation. Calendar changes must use a disposable, explicitly designated test event, not a real appointment. Existing source feeds and provider account limitations remain separate work and are not declared complete.

## Primary references
https://ai.google.dev/gemini-api/docs/interactions-overview
https://ai.google.dev/gemini-api/docs/document-processing
https://ai.google.dev/gemini-api/docs/structured-output
