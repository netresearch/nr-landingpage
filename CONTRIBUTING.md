# Contributing to nr_landingpage

Thank you for your interest in contributing to the TYPO3 Landing Page Generator extension.

## Getting Started

1. Clone the repository
2. Run `composer install` to set up dependencies
3. Run `composer ci` to verify everything passes

## Code Quality

All contributions must pass the following checks:

- **PHPStan Level 10** -- `composer ci:phpstan`
- **PHP-CS-Fixer** -- `composer ci:cgl` (fix with `composer fix:cgl`)
- **PHPUnit Tests** -- `composer ci:tests`

Run all checks at once with `composer ci`.

## Coding Standards

- Follow PER-CS 2.0 coding style (enforced by PHP-CS-Fixer)
- Use `declare(strict_types=1)` in all PHP files
- Use readonly properties and value objects where possible
- Keep services stateless; inject dependencies via constructor
- Add PHPDoc types for arrays (`@param list<string>`, `@return array<string, mixed>`, etc.)

## Architecture Rules

The codebase enforces layer dependencies via phpat architecture tests:

- **Domain layer** (`Domain\Model`) must not depend on Service, Controller, or Event layers
- **Service layer** must not depend on Controller layer
- **Events** must not depend on Service or Controller layers

## Testing

- Add unit tests for new services and model logic
- Place tests in `Tests/Unit/` mirroring the `Classes/` directory structure
- Use PHPUnit mocks for external dependencies (LLM client, database, etc.)

## Pull Requests

1. Create a feature branch from `main`
2. Make your changes with clear, descriptive commits
3. Ensure all CI checks pass
4. Open a pull request with a description of what changed and why

## Governance and policies

This repository follows the policies of the Netresearch GitHub organisation:

- [Governance](https://github.com/netresearch/.github/blob/main/GOVERNANCE.md) -- project roles, how decisions are made and how disagreements are resolved.
- [Roadmap](https://github.com/netresearch/.github/blob/main/ROADMAP.md) -- planned and excluded work for the coming year.
- [Handling of dependency and code analysis findings](https://github.com/netresearch/.github/blob/main/SECURITY.md#handling-of-dependency-and-code-analysis-findings) -- which findings block a change, the deadlines for the others, and how exceptions are recorded.
- [Secret management](https://github.com/netresearch/.github/blob/main/SECURITY.md#secret-management) -- where CI and release secrets are stored, who can access them and when they are rotated.
- [Access roster](https://github.com/netresearch/.github/blob/main/docs/access-roster.md) -- who has admin, maintain and write access to this repository.

Checks that run on every pull request in this repository:

- `.github/workflows/ci.yml` calls the shared `netresearch/typo3-ci-workflows` CI: PHP syntax lint, PHP-CS-Fixer (`composer ci:cgl`), PHPStan level 10 with the phpat layer rules (`phpstan.neon`), the unit suite (`composer ci:tests`) and the functional suite on SQLite, for PHP 8.2 to 8.4 and TYPO3 13.4 and 14.3.
- `.github/workflows/e2e.yml` runs the Playwright suite in `Tests/E2E/` against a TYPO3 instance.
- `.github/workflows/docs.yml` renders `Documentation/` when a pull request changes it.

These workflows run no dependency vulnerability scan and no static security scanner. Dependency updates arrive as pull requests from Renovate (`renovate.json`, which extends the shared `netresearch/renovate-config`).

## Reporting Issues

Please open an issue with:

- TYPO3 version and PHP version
- Steps to reproduce
- Expected vs. actual behavior

## License

By contributing, you agree that your contributions will be licensed under GPL-2.0-or-later.
