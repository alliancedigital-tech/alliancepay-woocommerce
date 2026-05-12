# Changelog
All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [Unreleased]
- (New changes that have not been tagged yet)

## [1.4.1] - 2026-05-12
### Fixed
- Added debug logging for missing MerchantId and DeviceId/refreshToken settings.

## [1.4.0] - 2026-04-28
### Added
- Added additionl fields to customerData during createOrder process.
- Added library league/iso3166 for country codes.

## [1.3.2] - 2026-03-31
### Fixed
- Added fix for minimal order total price lower than 10.

## [1.3.1] - 2026-03-04
### Fixed
- Fixed versioning logic and improved exception handling during the authorization process.
- Fixed missing import of exception classes in the authorization logic.

## [1.3.0] - 2026-02-27
### Added
- Added official Ukrainian language translations.

## [1.2.0] - 2025-11-03
### Added
- Added refund functionality through AlliancePay HPP API.

## [1.1.1] - 2025-10-17
### Added
- Added support for WooCommerce Blocks to render through React REST API.
### Changed
- AlliancePay Settings: added new editable "Title" and "Description" fields for better customization.

## [1.0.1] - 2025-10-16
### Fixed
- Security fix: Resolved unauthorized admin panel access for non-admin users.

## [1.0.0] - 2025-10-16
### Added
- Initial release.
- AlliancePay payment gateway integration with WooCommerce.
- Support for secure transaction processing and callback handling.