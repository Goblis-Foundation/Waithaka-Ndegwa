# Safe OpenAI request diagnostics

Explicit Studio and notes-report requests now surface a fixed safe error category instead of swallowing every failure as “no suggestion”. Responses are parsed only from completed assistant output text. Incomplete/max-output results are identified and do not silently become complete reports. The existing metadata/social fallback callers retain their non-throwing behavior.

One stateless HTTPS Responses request uses `store: false`, the configured model and only selected text. There are no tools, conversation links or automatic retries. Credential, access/model, prepaid credit, project/organisation limit, temporary rate, timeout, connection and provider failures are separated by status and allowlisted codes. Raw provider messages, source text, credentials and responses are never copied into error text or logs.

Pure fixture transports cover the request boundary, completed output, incomplete answers and secret-bearing error bodies. A successful configuration save or credit balance remains distinct from a successful generation.

Reference: https://developers.openai.com/api/docs/guides/error-codes and https://developers.openai.com/api/docs/guides/reasoning .
