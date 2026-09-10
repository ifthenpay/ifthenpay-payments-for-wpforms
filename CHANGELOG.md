# Changelog

All notable changes to this project will be documented in this file.

The format loosely follows Keep a Changelog recommendations.

## [2.0.1] - 2026-09-10

### Fixed
- Sanitization function bug on the callback url.

## [2.0.0] - 2026-09-09

### Added

- Functional webhooks(callbacks).
- ifthenpay form templates (Simple and Complex).
- ifthenpay own wpforms form theme.

### Changed

- New Stary default payment method selection.
- PopUp to present Completed, Pending, Failed, Cancelled messages.
- Updated documentation.

### Fixed

- Duplication ID simulatainious payment.
- Saving pending, cancelled and failed payments and entries.

### Security

- Payment completion is now confirmed exclusively via the verified ifthenpay webhook; the browser-return endpoint..

## [1.0.0] - 2026-05-28

- Initial stable release.

<!-- Future versions:
## [Unreleased]
### Added
### Changed
### Fixed
### Security
-->
