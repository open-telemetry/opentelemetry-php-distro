## 0.5.0

- feat: add earlySetup to register OTLP transport before SDK initialization ([#121](https://github.com/open-telemetry/opentelemetry-php-distro/pull/121))
- feat: PSR18 auto instrumentation ([#111](https://github.com/open-telemetry/opentelemetry-php-distro/pull/111))
- feat: support file-based declarative configuration (OTEL_CONFIG_FILE) ([#106](https://github.com/open-telemetry/opentelemetry-php-distro/pull/106))
- Add #[WithSpan] / #[SpanAttribute] native attribute-based instrumentation ([#117](https://github.com/open-telemetry/opentelemetry-php-distro/issues/117)) ([#118](https://github.com/open-telemetry/opentelemetry-php-distro/pull/118))
- Support disabling scoped dependencies via configuration ([#95](https://github.com/open-telemetry/opentelemetry-php-distro/pull/95))
- fix: NOTICE file generation moved out of source ([#108](https://github.com/open-telemetry/opentelemetry-php-distro/pull/108))
- fix: prevent mock object leak in OpAmp tests ([#103](https://github.com/open-telemetry/opentelemetry-php-distro/pull/103))
- Fixed passing production options to component tests app code ([#107](https://github.com/open-telemetry/opentelemetry-php-distro/pull/107))
- Fixed ResourceAttributes deprecated ([#96](https://github.com/open-telemetry/opentelemetry-php-distro/pull/96))
- Fixed TraceAttributes deprecated ([#90](https://github.com/open-telemetry/opentelemetry-php-distro/pull/90))
- Fixed tests for debug_scoper_enabled=true ([#85](https://github.com/open-telemetry/opentelemetry-php-distro/pull/85))
- Run build cleanup and PHP tool scripts inside Docker ([#120](https://github.com/open-telemetry/opentelemetry-php-distro/pull/120))
- Improved unit tests to catch mismatches between options names, metadata and config snapshot ([#110](https://github.com/open-telemetry/opentelemetry-php-distro/pull/110))
- Refactored logging to use the same sink for tests and tools ([#102](https://github.com/open-telemetry/opentelemetry-php-distro/pull/102))
- Refactored simple component tests ([#101](https://github.com/open-telemetry/opentelemetry-php-distro/pull/101))
- Renamed ResourcesClient to ResourcesCleanerClient ([#100](https://github.com/open-telemetry/opentelemetry-php-distro/pull/100))
- Renamed appCodeArgs to appCodeRequestArgs ([#99](https://github.com/open-telemetry/opentelemetry-php-distro/pull/99))
- Added to BootstrapStageLoggingClassTrait a way to customize prod-log-… ([#98](https://github.com/open-telemetry/opentelemetry-php-distro/pull/98))
- Use get_config_option_by_name('enabled') instead of reading environment variable in PhpPartFacade ([#97](https://github.com/open-telemetry/opentelemetry-php-distro/pull/97))
- Adapted packages' PHP code layout to scoping ([#94](https://github.com/open-telemetry/opentelemetry-php-distro/pull/94))
- Renamed repo root directory for generated files from `build` to `_BUILT` ([#93](https://github.com/open-telemetry/opentelemetry-php-distro/pull/93))
- Add a fast development shortcut that only re-scopes distro code ([#92](https://github.com/open-telemetry/opentelemetry-php-distro/pull/92))
- Updated OpAmp configuration reference ([#89](https://github.com/open-telemetry/opentelemetry-php-distro/pull/89))
- PostgreSQL smoke component test ([#87](https://github.com/open-telemetry/opentelemetry-php-distro/issues/87)) ([#88](https://github.com/open-telemetry/opentelemetry-php-distro/pull/88))
- Removed retryDelayedHooks from InstrumentationBridge ([#86](https://github.com/open-telemetry/opentelemetry-php-distro/pull/86))
- OpAmp config improvements ([#84](https://github.com/open-telemetry/opentelemetry-php-distro/pull/84))
- Upgraded dev PHP dependencies ([#104](https://github.com/open-telemetry/opentelemetry-php-distro/pull/104))
- Update docker/setup-qemu-action action to v3.7.0 ([#112](https://github.com/open-telemetry/opentelemetry-php-distro/pull/112))
- Update docker/setup-buildx-action action to v3.12.0 ([#81](https://github.com/open-telemetry/opentelemetry-php-distro/pull/81))
- Update github/codeql-action action to v4.36.0 ([#113](https://github.com/open-telemetry/opentelemetry-php-distro/pull/113))

## 0.4.0

- Enabled support for PHP 8.5 ([#58](https://github.com/open-telemetry/opentelemetry-php-distro/issues/58)) (PR [#77](https://github.com/open-telemetry/opentelemetry-php-distro/pull/77))
- Added user bootstrap config option (PR [#76](https://github.com/open-telemetry/opentelemetry-php-distro/pull/76))
- Automate C++ semconv header generation during CMake configure ([#63](https://github.com/open-telemetry/opentelemetry-php-distro/issues/63)) (PR [#75](https://github.com/open-telemetry/opentelemetry-php-distro/pull/75))
- Update Laravel instrumentation to 1.7.0 (PR [#65](https://github.com/open-telemetry/opentelemetry-php-distro/pull/65))
- Update SDK and instrumentation modules (PR [#62](https://github.com/open-telemetry/opentelemetry-php-distro/pull/62))
- fix: selection of PHP version in component test docker image (PR [#61](https://github.com/open-telemetry/opentelemetry-php-distro/pull/61))

## 0.3.0

- fix: internal inferred spans filtering for scoped namespace (PR [#56](https://github.com/open-telemetry/opentelemetry-php-distro/pull/56))
- fix: use system_clock for Boost.Interprocess timed_receive to prevent futex spin loop causing high CPU ([#52](https://github.com/open-telemetry/opentelemetry-php-distro/issues/52)) (PR [#55](https://github.com/open-telemetry/opentelemetry-php-distro/pull/55))
- Enable vendor customizations and custom OpAMP consumers in PHP side ([#53](https://github.com/open-telemetry/opentelemetry-php-distro/issues/53)) ([#54](https://github.com/open-telemetry/opentelemetry-php-distro/issues/54)) (PR [#54](https://github.com/open-telemetry/opentelemetry-php-distro/pull/54

## 0.2.0

- Exposing artificial hook function if scoping is enabled (PR [#49](https://github.com/open-telemetry/opentelemetry-php-distro/pull/49))
- Dependency shadowing/scoping (PR [#47](https://github.com/open-telemetry/opentelemetry-php-distro/pull/47))

## 0.1.0

Initial technical preview release.

This is not an alpha or beta stability commitment.
The distro may not work correctly in all environments and may affect the behavior of the monitored application.
