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

## Reporting Issues

Please open an issue with:

- TYPO3 version and PHP version
- Steps to reproduce
- Expected vs. actual behavior

## License

By contributing, you agree that your contributions will be licensed under GPL-2.0-or-later.
