# API

## WebSocket: Traffic Query (Panel -> V2bX Node)

This API allows the panel side to **actively query** traffic from a V2bX node over the **existing WebSocket connection**.

- Transport: WebSocket (same connection used by V2bX for panel communication)
- Direction: Panel initiates request, V2bX responds
- Side effects: **None** (traffic counters are **not reset**; periodic reporting is not affected)

### Request

The panel sends a WS message in the following JSON format:

```json
{
  "id": 123,
  "method": "POST",
  "path": "/api/v1/server/UniProxy/traffic/query",
  "headers": {
    "Content-Type": ["application/json"]
  },
  "body": "..."
}
```

Fields:

- `id` (number, optional but recommended)
  - Correlation id for request/response.
  - If omitted, V2bX will still respond, but the caller should ensure there is only one in-flight request when using legacy mode.
- `method` (string)
  - Allowed: `GET`, `POST`
- `path` (string)
  - Must be exactly: `/api/v1/server/UniProxy/traffic/query`
- `headers` (object, optional)
  - HTTP-like headers.
- `body` (string/bytes, optional)
  - JSON-encoded query payload.

#### Query Body

`body` is JSON. All fields are optional.

```json
{
  "tag": "[api-host]-vmess:1",
  "uid": 10001
}
```

- `tag` (string, optional)
  - If omitted or empty, query the current controller tag (the node instance that the WS connection belongs to).
  - If provided and not equal to the controller tag, V2bX returns `400 tag mismatch`.
- `uid` (number, optional)
  - If omitted or `0`, returns traffic for all users.
  - If provided, returns traffic only for the specified user.

### Response

V2bX responds with:

```json
{
  "id": 123,
  "status": 200,
  "headers": {
    "Content-Type": ["application/json"]
  },
  "body": "..."
}
```

`body` (JSON):

```json
{
  "tag": "[api-host]-vmess:1",
  "traffic": [
    {"UID": 10001, "Upload": 1234, "Download": 5678}
  ]
}
```

Notes:

- `traffic` may be an empty array if there is no data or the `uid` does not match any entry.

### Status Codes

- `200`: OK
- `400`: tag mismatch
- `404`: not found (wrong path)
- `405`: method not allowed
- `500`: internal error

### Compatibility

- V2bX supports server-initiated WS requests using the same message format.
- `id` is optional; for best reliability, the panel should always set `id`.
