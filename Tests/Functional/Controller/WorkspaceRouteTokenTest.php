<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

namespace Netresearch\NrLandingpage\Tests\Functional\Controller;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Routing\Route as SymfonyRoute;
use Throwable;
use TypeError;
use TYPO3\CMS\Backend\Http\RouteDispatcher;
use TYPO3\CMS\Backend\Routing\Exception\InvalidRequestTokenException;
use TYPO3\CMS\Backend\Routing\Exception\MissingRequestTokenException;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Backend\Routing\Router;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\FormProtection\FormProtectionFactory;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The state-changing routes of the content workspace (ADR-0001, section 8)
 * are protected against cross-site request forgery by the core: every backend
 * route whose `access` option is not `public` needs a request token bound to
 * that route's identifier, and the RouteDispatcher refuses the request before
 * the controller runs. Dispatched through the real router and dispatcher, so
 * a route that became public, and skipped the check, fails.
 */
#[CoversNothing]
final class WorkspaceRouteTokenTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'netresearch/nr-landingpage',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Workspace/be_users.csv');
        $this->setUpBackendUser(1);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_REQUEST']);
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function routes(): iterable
    {
        yield 'save draft' => ['ajax_nr_landingpage_workspace_save_draft'];
        yield 'create link' => ['ajax_nr_landingpage_workspace_create_link'];
        yield 'revoke link' => ['ajax_nr_landingpage_workspace_revoke_link'];
    }

    #[Test]
    #[DataProvider('routes')]
    public function aPostWithoutATokenIsRefusedBeforeTheController(string $identifier): void
    {
        $this->expectException(MissingRequestTokenException::class);
        $this->dispatch($this->path($identifier), null);
    }

    #[Test]
    #[DataProvider('routes')]
    public function aPostWithAnotherRoutesTokenIsRefusedBeforeTheController(string $identifier): void
    {
        $other = $identifier === 'ajax_nr_landingpage_workspace_create_link'
            ? 'ajax_nr_landingpage_workspace_revoke_link'
            : 'ajax_nr_landingpage_workspace_create_link';

        $this->expectException(InvalidRequestTokenException::class);
        $this->dispatch($this->path($identifier), $this->tokenFor($other));
    }

    /**
     * The other direction: the route's own token passes the check, so the
     * refusals above are about the token and nothing else.
     */
    #[Test]
    #[DataProvider('routes')]
    public function aPostWithTheRoutesOwnTokenPassesTheTokenCheck(string $identifier): void
    {
        $path = $this->path($identifier);
        $token = $this->tokenFor($identifier);

        try {
            $this->dispatch($path, $token);
        } catch (MissingRequestTokenException|InvalidRequestTokenException $e) {
            self::fail('The route token was refused: ' . $e->getMessage());
        } catch (TypeError $e) {
            self::fail('The harness did not reach the token check: ' . $e->getMessage());
        } catch (Throwable) {
            // Anything after the token check is not what this test is about.
        }

        $this->addToAssertionCount(1);
    }

    /**
     * The path of an AJAX route as the router registered it, asserted not to
     * be public: a public route skips the token check.
     */
    private function path(string $identifier): string
    {
        $router = $this->get(Router::class);
        self::assertInstanceOf(Router::class, $router);

        foreach ($router->getRoutes() as $name => $route) {
            if ($name === $identifier && $route instanceof SymfonyRoute) {
                self::assertNotSame('public', $route->getOption('access'), $identifier);
                $path = $route->getPath();
                self::assertIsString($path);

                return $path;
            }
        }

        self::fail('No backend route ' . $identifier);
    }

    private function matched(string $path): Route
    {
        $router = $this->get(Router::class);
        self::assertInstanceOf(Router::class, $router);
        $route = $router->match($path);
        self::assertInstanceOf(Route::class, $route);

        return $route;
    }

    private function tokenFor(string $identifier): string
    {
        $factory = $this->get(FormProtectionFactory::class);
        self::assertInstanceOf(FormProtectionFactory::class, $factory);

        return $factory->createFromRequest($this->request('/ajax/nr-landingpage/workspace/create-link', null))->generateToken('route', $identifier);
    }

    private function dispatch(string $path, ?string $token): void
    {
        $dispatcher = $this->get(RouteDispatcher::class);
        self::assertInstanceOf(RouteDispatcher::class, $dispatcher);

        $request = $this->request($path, $token)->withAttribute('route', $this->matched($path));
        $GLOBALS['TYPO3_REQUEST'] = $request;
        $dispatcher->dispatch($request);
    }

    private function request(string $path, ?string $token): ServerRequest
    {
        $request = (new ServerRequest('https://typo3-testing.local/typo3' . $path, 'POST'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withParsedBody(['draft' => '1'] + ($token !== null ? ['token' => $token] : []));
        $request = $request->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));
        $GLOBALS['TYPO3_REQUEST'] = $request;

        return $request;
    }
}
