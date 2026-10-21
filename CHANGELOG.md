# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.1.0] - 2026-10-21

### Changed
- **Symfony 6.4 is no longer supported**: the plugin now requires Symfony 7.4 or 8 (`RateLimiterFactoryInterface` only exists since Symfony 7.3). PHP 8.2 stays supported with Symfony 7.4.

### Fixed
- Rate limiters are typed `RateLimiterFactoryInterface` instead of the concrete `RateLimiterFactory`, which Symfony 8 no longer autowires.
