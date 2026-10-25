# Footer visitor statistics
Google Analytics read-only existing connection; matches deployed measurement ID and canonical website host to one web stream. All reports filter that streamId. Background refresh only; no public provider call, new tracker, IP storage or consent bypass. Active visitors are the last 30 minutes (minute range29..0), not concurrent human count. Visits are sessions from stream creation (property creation fallback) through today; not unique people and not invented pre-collection history. Explicit zero retained; missing rows or thresholded readings unavailable. Recent activity expires in three minutes, total in24h and bindingin2h; unknown values are never zero. Connection/config changes invalidate the binding; totals refresh hourly. Owner-only /write/visitor-statistics can verify immediately. Existing paused communication/social queues remain unchanged.

Official references checked25Oct2026:
- https://developers.google.com/analytics/devguides/reporting/data/v1/realtime-basics
- https://developers.google.com/analytics/devguides/reporting/data/v1/realtime-api-schema
- https://developers.google.com/analytics/devguides/config/admin/v1/rest/v1beta/properties
