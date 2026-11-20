# Security

The hardening pass (ISSUE-41) is pinned down by the tests in `tests/Feature/Security`.

- **Rate limits.** Named limiters live in `App\Support\RateLimits`. Each one counts in
  its own bucket. An inline `throttle:30,1` shares one bucket per user with every other
  inline throttle, so mention lookups used to eat the message budget.

  | Limiter        | Routes                                 | Limit                                       |
  |----------------|----------------------------------------|---------------------------------------------|
  | `login`        | `POST /login`                          | 5/min per email and IP, 30/min per IP       |
  | `messages`     | sending in a channel or a DM           | 30/min per user, one budget for both        |
  | `invites`      | creating invites                       | 20/min per user                             |
  | `invite-codes` | opening and accepting `/invite/{code}` | 30/min per user, 60/min per IP              |

  The other routes keep their inline `throttle:N,1` limits.
- **XSS.** Views only print with `{{ }}`. Message markdown (`App\Support\Markdown`,
  `resources/js/markdown.js`) builds its own tags and escapes everything else. It links
  only `http(s)` URLs. Live messages are built with DOM methods, never `innerHTML`.
  Uploads other than images are always sent as downloads, with a sandbox CSP.
- **Mass assignment.** Every model lists `$fillable`. Nothing is unguarded. Controllers
  pass named fields, never `$request->all()`. Trusted columns (`email_verified_at`, pins,
  mentions, owners) are only set with `forceFill()` by the code that owns them.
- **CSRF and CORS.** Every POST/PUT/PATCH/DELETE route is in the `web` group and needs
  the CSRF token, with no `except` list. The one exception is Laravel's own
  `/broadcasting/auth`, which is reviewed in `CsrfCorsTest`. No CORS headers are sent, so
  other sites cannot read responses. The session cookie is `HttpOnly` and `SameSite=Lax`.
  `App\Http\Middleware\SecurityHeaders` forbids framing (`X-Frame-Options`,
  `frame-ancestors`), MIME sniffing and full-path referrers. The private `local` disk
  has `serve` off, so Laravel adds no `/storage/{path}` routes for it.
- **Broadcast channels.** All channels in `routes/channels.php` are private or presence
  channels. The `test` smoke-test channel is the only public one, and it carries no user
  data. `BroadcastChannelAuthorisationTest` pins the list of channels and who may join
  each one. When you add a channel, add it there too.
- **Websocket origins.** Reverb only accepts connections from pages on the `APP_URL` host
  (`config/reverb.php`). With `APP_ENV=local` or `testing` it also accepts `localhost` and
  `127.0.0.1`. Set `REVERB_ALLOWED_ORIGINS` (comma-separated hosts) to serve the app from
  somewhere else. In production, set your own random `REVERB_APP_KEY` and `REVERB_APP_SECRET`.
- **Uploads.** Message attachments are stored on the private `local` disk under random
  names, and they are only served through temporary signed URLs (`AttachmentController`).
  An altered or expired link, or an unknown id, is a `403`, so ids cannot be probed. Both
  the file extension and the type sniffed from the contents must be on the allow-list, and
  SVG and HTML are never accepted. Avatars and server icons are decoded and re-encoded as
  PNG by GD, so the stored file never contains the original bytes. See
  [features.md](features.md#messages).
