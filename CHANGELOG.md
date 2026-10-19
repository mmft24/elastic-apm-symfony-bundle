# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Support for Symfony 8 on PHP 8.3+ (`symfony/*` constraints widened to `^6.4|^7.0|^8.0`).
- Configurable HTTP capture threshold for `ExceptionListener`.
- `report_silenced_errors` option (default `false`) and an overridable
  `AbstractErrorHandlerListener::shouldReport()` filtering hook for the warning and deprecation listeners.

### Changed

- **BREAKING:** `ElasticApmBundle\Exception\ConfigurationException` now extends
  `\InvalidArgumentException` instead of `\ErrorException`. This is a public-API
  break for anyone constructing or catching the exception directly:
  - The third positional constructor argument changed meaning from
    `int $severity` (`\ErrorException`) to `?\Throwable $previous`
    (`\InvalidArgumentException`). Calls such as
    `new ConfigurationException($message, 0, \E_ERROR, $file, $line)` now throw
    a `TypeError`.
  - `catch (\ErrorException)` no longer traps it. Catch
    `ConfigurationException`, `\InvalidArgumentException`, `\LogicException`, or
    `\Throwable` instead.

  The change reflects intent: the exception models a manually-detected
  configuration precondition, not a real PHP error with severity/file/line
  semantics.
- Error-handler deduplication cache is now scoped per transaction.
- Hardened command parameter redaction and stopped double-reporting exception causes.
- Warnings and deprecations silenced with `@` or excluded by `error_reporting()` are no longer reported to APM;
  set `report_silenced_errors: true` to restore the previous behaviour.

### Fixed

- The warning and deprecation listeners were private services, so `ElasticApmBundle::boot()` could not find them
  in a compiled container and never registered their error handlers.
- The error handler now returns the previous handler's result unchanged instead of casting it to `bool`, so a
  previous handler returning `null` no longer makes PHP log the error a second time. It also accepts calls without
  `$file`/`$line`.

## [2.0.0]

Baseline release. See the Git history for prior changes.

[Unreleased]: https://github.com/mmft24/elastic-apm-symfony-bundle/compare/2.0.0...HEAD
[2.0.0]: https://github.com/mmft24/elastic-apm-symfony-bundle/releases/tag/2.0.0
