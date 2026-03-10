<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Directive;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Mutation;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\MutationCollection;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\MutationMode;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Scope;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\SourceScheme;
use TYPO3\CMS\Core\Type\Map;

/**
 * Allow blob: workers in the backend scope.
 *
 * Required by the Alwan color picker library used in TYPO3's native
 * TCA type=color field. The library creates a Web Worker from a Blob URL.
 */
return Map::fromEntries([
    Scope::backend(),
    new MutationCollection(
        new Mutation(MutationMode::Extend, Directive::WorkerSrc, SourceScheme::blob),
    ),
]);
