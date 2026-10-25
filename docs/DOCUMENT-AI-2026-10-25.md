# Consented document AI using the working provider

OpenAI is available for booking PDF/image briefings and program PDF/image/Word interpretation through the existing Responses key. Documents are passed inline in one stateless request with store:false; no Files API upload, remote file URL, additional key grant or file-store record is needed. Original filenames and separate contact details are excluded. File types and the existing 4 MB bound remain enforced.

New booking forms display their configured AI provider (OpenAI by default) and submit that provider as a consent snapshot. The document metadata retains it. Legacy form submissions and stored attachments without a provider remain Gemini-consented; they are never silently sent to OpenAI. Existing review, failed-analysis alerts and report-before-acceptance guards remain intact. Owners can choose the provider for future forms in Availability.

Program upload includes an explicit provider choice and consent. OpenAI extraction uses a strict bounded JSON schema, then the existing date/time validation and editable selected-entry preview. CSV/ICS/text remain local. No preview sends invitations or imports a program until the author selects and confirms entries.

Offline transports cover inline bytes, no external file URLs/storage and size/type limits. Policy fixtures cover legacy consent preservation. A synthetic PDF can be interpreted in the private program preview without importing or notifying anyone.

Reference: https://developers.openai.com/api/docs/guides/file-inputs .
