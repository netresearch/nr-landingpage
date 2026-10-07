<!-- SPDX-License-Identifier: GPL-2.0-or-later -->
<!-- SPDX-FileCopyrightText: Netresearch DTT GmbH -->

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

- `.github/workflows/ci.yml` calls the shared `netresearch/typo3-ci-workflows` CI: PHP syntax lint, PHP-CS-Fixer (`composer ci:cgl`), PHPStan level 10 with the phpat layer rules (`phpstan.neon`), the unit suite (`composer ci:tests`) and the functional suite on SQLite, for PHP 8.2 to 8.4 and TYPO3 13.4 and 14.3; the `tests / All CI checks` job summarises them.
- `.github/workflows/e2e.yml` runs the Playwright suite in `Tests/E2E/` against a TYPO3 instance.
- `.github/workflows/docs.yml` renders `Documentation/` when a pull request changes it.
- `.github/workflows/checks.yml`, the organisation template for TYPO3 extensions: Composer Audit and Opengrep SAST through `security.yml` of `netresearch/typo3-ci-workflows` (which findings block a pull request is set by the organisation's [static analysis rule](https://github.com/netresearch/.github/blob/main/SECURITY.md#static-analysis-sast)); Dependency Review; the PHP licence check (`license-check.yml`); CodeQL for the workflow files and the JavaScript; Betterleaks secret scanning; zizmor for the workflow files; `pr-quality`, which reports the size of the pull request and approves a non-draft pull request from a branch of this repository opened by an owner, member or collaborator. The `fuzz` job is called but runs nothing here, as the repository has no fuzz tests. The `All security checks` job fails when any of these jobs failed or was cancelled.
- `.github/workflows/check-template-drift.yml`: the workflow files managed by the organisation template have not drifted from it (`.github/template.yaml` lists `ci.yml` and `release.yml` as this repository's own).
- `.github/workflows/labeler.yml` labels the pull request by the paths it changes, `.github/workflows/community.yml` greets the author of a first pull request and `.github/workflows/auto-merge-deps.yml` approves dependency update pull requests from Renovate or Dependabot and enables auto-merge for them, unless the pull request carries the `deps-major` or `deps-no-automerge` label; these three run on `pull_request_target` and check nothing.

Dependency updates arrive as pull requests from Renovate (`renovate.json`, which extends the shared `netresearch/renovate-config`).

## Reporting Issues

Please open an issue with:

- TYPO3 version and PHP version
- Steps to reproduce
- Expected vs. actual behavior

## License

By contributing, you agree that your contributions will be licensed under GPL-2.0-or-later.
