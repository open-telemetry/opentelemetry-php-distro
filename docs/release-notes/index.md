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
