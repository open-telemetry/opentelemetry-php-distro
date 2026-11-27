# Tests ported from opentelemetry-php-instrumentation

Source: `ext/tests` of open-telemetry/opentelemetry-php-instrumentation at b2fd524 (1.5.0 + later fixes).
To synchronize, compare `ext/tests` from that commit onwards and update the commit above.

- `with_span/`, `span_attribute/`, `mocks/` - `#[WithSpan]` / `#[SpanAttribute]` tests. They run with
  `OTEL_PHP_SCOPED_DEPS_ENABLED=false` and `includes/bootstrap_mock_unscoped.inc`, so the mock `WithSpanHandler`
  is the unscoped `OpenTelemetry\API\Instrumentation\WithSpanHandler`.
- `ext_hooks/` - generic `hook()` tests (`hook()` is `\OpenTelemetry\Distro\hook()` here).

Tests marked `--XFAIL--` document behaviour where distro differs from the extension; the reason is the XFAIL text.
When a gap is fixed the test starts to "pass unexpectedly" - remove the XFAIL section then.

Not ported (extension-specific): conflicts_*, minfo_*, ini_set, disable_*, validate_hook_functions_on, hook_disabled_extension,
configurable pre/post handler functions (`customize_handlers`, `invalid_callback`, `handler_not_found`), `expand_params_*` variants
gated by `allow_stack_extension`, warn-once / re-arm tests (distro logs instead of raising PHP warnings), `001` (extension_loaded).
Expected output was adapted where the extension raises `Warning: ... OpenTelemetry: ...` (distro logs these) and where pre hooks get
6 args instead of 8.
