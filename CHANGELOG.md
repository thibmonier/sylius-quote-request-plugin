# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.1] - 2026-10-21

### Fixed
- Rate limiters are typed `RateLimiterFactoryInterface` instead of the concrete `RateLimiterFactory`, which Symfony 8 no longer autowires (compatible with Symfony 7.4 and 8.x).
