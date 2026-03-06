# Makefile for nr_landingpage TYPO3 extension development

.PHONY: help up start down restart install sync test test-unit test-func test-e2e coverage mutation lint lint-fix phpstan rector rector-fix clean ci ci-full

help:
	@echo "nr_landingpage - TYPO3 Landing Page Generator Development"
	@echo ""
	@echo "Quick Start:"
	@echo "  up          Complete setup (DDEV + TYPO3)"
	@echo ""
	@echo "Environment:"
	@echo "  start       Start DDEV"
	@echo "  down        Stop DDEV"
	@echo "  restart     Restart DDEV"
	@echo "  install     Install TYPO3 v14 with extension"
	@echo "  sync        Re-run extension:setup"
	@echo ""
	@echo "Testing:"
	@echo "  test        Run all tests (unit)"
	@echo "  test-unit   Unit tests"
	@echo "  test-func   Functional tests (SQLite)"
	@echo "  test-e2e    E2E tests (Playwright)"
	@echo "  coverage    Tests with coverage"
	@echo "  mutation    Mutation testing"
	@echo ""
	@echo "Quality:"
	@echo "  lint        Check code style"
	@echo "  lint-fix    Fix code style"
	@echo "  phpstan     Static analysis"
	@echo "  rector      Rector (dry-run)"
	@echo "  rector-fix  Apply Rector"
	@echo "  ci          All CI checks"
	@echo ""
	@echo "Maintenance:"
	@echo "  clean       Remove generated files"

up: start install
	@echo ""
	@echo "---------------------------------------------------"
	@echo "Setup complete!"
	@echo ""
	@echo "TYPO3 Backend: https://v14.nr-landingpage.ddev.site/typo3/"
	@echo "  Username: admin | Password: Joh316!!"
	@echo ""
	@echo "Configure nr-llm LLM provider before using the wizard."
	@echo "---------------------------------------------------"

start:
	ddev start

down:
	ddev stop

restart:
	ddev restart

install:
	ddev install-v14

sync:
	ddev exec -d /var/www/html/v14 vendor/bin/typo3 extension:setup
	ddev exec -d /var/www/html/v14 vendor/bin/typo3 cache:flush

test: test-unit
	@echo "All tests passed."

test-unit:
	Build/Scripts/runTests.sh -s unit

test-func:
	Build/Scripts/runTests.sh -s functional

test-e2e:
	Build/Scripts/runTests.sh -s e2e

coverage:
	Build/Scripts/runTests.sh -s unitCoverage

mutation:
	Build/Scripts/runTests.sh -s mutation

lint:
	Build/Scripts/runTests.sh -s cgl -n

lint-fix:
	Build/Scripts/runTests.sh -s cgl

phpstan:
	Build/Scripts/runTests.sh -s phpstan

rector:
	Build/Scripts/runTests.sh -s rector -n

rector-fix:
	Build/Scripts/runTests.sh -s rector

clean:
	Build/Scripts/runTests.sh -s clean

ci: lint phpstan test-unit
	@echo "CI checks passed."

ci-full: ci test-func
	@echo "Full CI checks passed."
