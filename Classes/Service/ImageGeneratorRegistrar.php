<?php

declare(strict_types=1);

namespace Netresearch\NrLandingpage\Service;

use Netresearch\NrLlm\Specialized\Image\DallEImageService;
use Netresearch\NrLlm\Specialized\Image\FalImageService;

/**
 * Registers available AI image generators with ImageProviderService.
 *
 * Called via Services.yaml configurator after DI constructs ImageProviderService.
 * Both DALL-E (OpenAI) and FAL.ai generators are registered when their
 * respective API keys are configured.
 */
final class ImageGeneratorRegistrar
{
    public function __construct(
        private readonly DallEImageService $dallEImageService,
        private readonly FalImageService $falImageService,
    ) {}

    public function register(ImageProviderService $imageProviderService): void
    {
        $dallE = $this->dallEImageService;
        $imageProviderService->registerGenerator(
            'dall-e',
            static fn(string $prompt, array $options) => $dallE->generate($prompt, $options),
            static fn() => $dallE->isAvailable(),
        );

        $fal = $this->falImageService;
        $imageProviderService->registerGenerator(
            'fal-ai',
            static fn(string $prompt, array $options) => $fal->generate(
                $prompt,
                is_string($options['model'] ?? null) ? $options['model'] : 'flux-schnell',
                $options,
            ),
            static fn() => $fal->isAvailable(),
        );
    }
}
