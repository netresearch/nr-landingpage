# Generic Image Generation via Task System in nr-llm

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Extend the nr-llm task system so that a task with `task_type: image_generation` routes through a generic `ImageGenerationInterface` to whatever image provider is configured via the provider chain (Provider → Model → Configuration → Task). Currently only OpenAI (DALL-E / gpt-image-1) is the external image provider; FalImageService is TYPO3-intern (FAL) and out of scope here.

**Architecture:** Introduce an `ImageGenerationInterface` contract and an `ImageGenerationAdapterRegistry` (mirroring the existing `ProviderAdapterRegistry` pattern for text LLMs). The registry maps `adapter_type` to image generation adapters. `DallEImageService` gets refactored to implement the interface and accept provider-chain credentials. The task system gains a `task_type` field that routes either to text completion (existing) or image generation (new). Future image providers (Stability AI, Midjourney API, etc.) just implement the interface and register.

**Tech Stack:** PHP 8.2+, TYPO3 v14, Extbase, Fluid, PSR-7/PSR-17/PSR-18 HTTP, OpenAI Images API

**Target project:** nr-llm (standalone checkout or vendor path)

---

## Architecture Overview

```
                          ┌─────────────────────────────────┐
                          │         TaskController           │
                          │       executeAction()            │
                          └───────────┬─────────────────────┘
                                      │ task.getTaskType()
                         ┌────────────┴────────────┐
                         ▼                         ▼
                   completion               image_generation
                         │                         │
                         ▼                         ▼
              LlmServiceManager        ImageGenerationAdapterRegistry
              .complete()                .generateFromConfiguration()
                         │                         │
                         ▼                         ▼
              ProviderInterface         ImageGenerationInterface
              (OpenAI, Claude,          (OpenAiImageAdapter, ...)
               Gemini, ...)                        │
                                                   ▼
                                          OpenAI Images API
                                          POST /v1/images/generations
```

### Betroffene Dateien

| Aktion | Datei |
|--------|-------|
| Create | `Classes/Specialized/Image/ImageGenerationInterface.php` |
| Create | `Classes/Specialized/Image/ImageGenerationAdapterRegistry.php` |
| Create | `Classes/Specialized/Image/Adapter/OpenAiImageAdapter.php` |
| Create | `Classes/Domain/Enum/TaskType.php` |
| Modify | `Classes/Domain/Model/Task.php` |
| Modify | `Classes/Domain/Enum/ModelCapability.php` |
| Modify | `Classes/Controller/Backend/TaskController.php` |
| Modify | `Configuration/TCA/tx_nrllm_task.php` |
| Modify | `Configuration/Services.yaml` |
| Modify | `ext_tables.sql` |
| Modify | `Resources/Private/Templates/Backend/Task/Execute.html` |
| Modify | `Resources/Private/Language/locallang_tca.xlf` |
| Create | `Tests/Unit/Domain/Enum/TaskTypeTest.php` |
| Create | `Tests/Unit/Specialized/Image/ImageGenerationAdapterRegistryTest.php` |
| Create | `Tests/Unit/Specialized/Image/Adapter/OpenAiImageAdapterTest.php` |

---

### Task 1: ImageGenerationInterface erstellen

Das generische Contract für alle Image-Provider.

**Files:**
- Create: `Classes/Specialized/Image/ImageGenerationInterface.php`

**Step 1: Write the interface**

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Specialized\Image;

use Netresearch\NrLlm\Specialized\Option\ImageGenerationOptions;

/**
 * Generic interface for image generation services.
 *
 * Implementations handle a specific API (OpenAI DALL-E, Stability AI, etc.)
 * and are resolved by ImageGenerationAdapterRegistry based on the provider's adapter_type.
 */
interface ImageGenerationInterface
{
    /**
     * Generate an image from a text prompt.
     *
     * @param array<string, mixed> $providerConfig API credentials and endpoint from provider chain:
     *   - apiKey: string (decrypted API key)
     *   - baseUrl: string (API endpoint)
     *   - modelId: string (e.g. "dall-e-3", "gpt-image-1")
     *   - timeout: int (seconds)
     */
    public function generate(
        string $prompt,
        array $providerConfig,
        ImageGenerationOptions|array $options = [],
    ): ImageGenerationResult;

    /**
     * Check if this adapter supports the given model ID.
     */
    public function supportsModel(string $modelId): bool;

    /**
     * Get the adapter identifier (e.g. "openai", "stability").
     */
    public function getIdentifier(): string;
}
```

**Step 2: Commit**

```
feat: add ImageGenerationInterface contract for generic image generation
```

---

### Task 2: ModelCapability um IMAGE_GENERATION erweitern

**Files:**
- Modify: `Classes/Domain/Enum/ModelCapability.php`
- Modify: `Tests/Unit/Domain/Enum/ModelCapabilityTest.php`

**Step 1: Neuen Case hinzufügen**

In `ModelCapability` enum:

```php
case IMAGE_GENERATION = 'image_generation';
```

**Step 2: Bestehende Tests anpassen**

In `ModelCapabilityTest.php` den neuen Wert in `values()` und `isValid()` Tests ergänzen.

**Step 3: Commit**

```
feat: add IMAGE_GENERATION to ModelCapability enum
```

---

### Task 3: TaskType Enum erstellen

**Files:**
- Create: `Classes/Domain/Enum/TaskType.php`
- Create: `Tests/Unit/Domain/Enum/TaskTypeTest.php`

**Step 1: Write the test**

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Domain\Enum;

use Netresearch\NrLlm\Domain\Enum\TaskType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(TaskType::class)]
final class TaskTypeTest extends TestCase
{
    #[Test]
    public function completionIsDefault(): void
    {
        self::assertSame('completion', TaskType::COMPLETION->value);
    }

    #[Test]
    public function imageGenerationExists(): void
    {
        self::assertSame('image_generation', TaskType::IMAGE_GENERATION->value);
    }

    #[Test]
    public function valuesReturnsAllCases(): void
    {
        $values = TaskType::values();
        self::assertContains('completion', $values);
        self::assertContains('image_generation', $values);
    }

    #[Test]
    public function isValidAcceptsKnownValues(): void
    {
        self::assertTrue(TaskType::isValid('completion'));
        self::assertTrue(TaskType::isValid('image_generation'));
        self::assertFalse(TaskType::isValid('unknown'));
    }

    #[Test]
    public function getLabelReturnsHumanReadable(): void
    {
        self::assertSame('Text Completion', TaskType::COMPLETION->getLabel());
        self::assertSame('Image Generation', TaskType::IMAGE_GENERATION->getLabel());
    }

    #[Test]
    public function isImageGenerationReturnsTrueOnlyForImageType(): void
    {
        self::assertTrue(TaskType::IMAGE_GENERATION->isImageGeneration());
        self::assertFalse(TaskType::COMPLETION->isImageGeneration());
    }
}
```

**Step 2: Implement the enum**

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\Enum;

enum TaskType: string
{
    case COMPLETION = 'completion';
    case IMAGE_GENERATION = 'image_generation';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(
            static fn(self $case): string => $case->value,
            self::cases(),
        );
    }

    public static function isValid(string $value): bool
    {
        return in_array($value, self::values(), true);
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::COMPLETION => 'Text Completion',
            self::IMAGE_GENERATION => 'Image Generation',
        };
    }

    public function isImageGeneration(): bool
    {
        return $this === self::IMAGE_GENERATION;
    }
}
```

**Step 3: Run tests**

Run: `php vendor/bin/phpunit Tests/Unit/Domain/Enum/TaskTypeTest.php`

**Step 4: Commit**

```
feat: add TaskType enum for routing tasks to completion or image generation
```

---

### Task 4: Task Model + DB Schema um task_type erweitern

**Files:**
- Modify: `Classes/Domain/Model/Task.php`
- Modify: `ext_tables.sql`

**Step 1: DB-Spalte hinzufügen**

In `tx_nrllm_task`, nach `output_format`:

```sql
    -- Task type: completion (text LLM) or image_generation (image API)
    task_type varchar(30) DEFAULT 'completion' NOT NULL,
```

**Step 2: Property + Getter/Setter im Task Model**

```php
use Netresearch\NrLlm\Domain\Enum\TaskType;

// Property
protected string $taskType = 'completion';

// Getters
public function getTaskType(): string
{
    return $this->taskType;
}

public function getTaskTypeEnum(): ?TaskType
{
    return TaskType::tryFrom($this->taskType);
}

public function isImageGeneration(): bool
{
    return $this->taskType === TaskType::IMAGE_GENERATION->value;
}

// Setter
public function setTaskType(string $taskType): void
{
    $this->taskType = $taskType;
}
```

**Step 3: Commit**

```
feat: add task_type field to Task model (default: completion)
```

---

### Task 5: TCA um task_type mit Type-Switching erweitern

**Files:**
- Modify: `Configuration/TCA/tx_nrllm_task.php`
- Modify: `Resources/Private/Language/locallang_tca.xlf`

**Step 1: ctrl.type setzen**

```php
'ctrl' => [
    // ... existing ...
    'type' => 'task_type',
],
```

**Step 2: task_type Column hinzufügen**

```php
'task_type' => [
    'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_task.task_type',
    'description' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_task.task_type.description',
    'onChange' => 'reload',
    'config' => [
        'type' => 'select',
        'renderType' => 'selectSingle',
        'items' => [
            ['label' => 'Text Completion', 'value' => 'completion'],
            ['label' => 'Image Generation', 'value' => 'image_generation'],
        ],
        'default' => 'completion',
    ],
],
```

**Step 3: Types mit Type-Switching**

Ersetze den alten `'1'` type durch benannte Types:

```php
'types' => [
    'completion' => [
        'showitem' => '
            --div--;core.form.tabs:general,
                --palette--;;identity,
                --palette--;;settings,
                prompt_template,
            --div--;LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_task.tab.input_output,
                --palette--;;input,
                --palette--;;output,
            --div--;core.form.tabs:access,
                --palette--;;status,
        ',
    ],
    'image_generation' => [
        'showitem' => '
            --div--;core.form.tabs:general,
                --palette--;;identity,
                --palette--;;settings,
                prompt_template,
            --div--;core.form.tabs:access,
                --palette--;;status,
        ',
    ],
],
```

Image-Generation-Type braucht kein input/output Tab - Input ist immer manual (der Prompt), Output ist immer ein Bild.

**Step 4: settings Palette erweitern**

```php
'settings' => [
    'label' => '...',
    'showitem' => 'task_type, category, configuration_uid',
],
```

**Step 5: Labels in locallang_tca.xlf**

```xml
<trans-unit id="tx_nrllm_task.task_type">
    <source>Task Type</source>
</trans-unit>
<trans-unit id="tx_nrllm_task.task_type.description">
    <source>Determines whether this task generates text (via LLM completion) or images (via image generation API). The configured LLM Configuration must reference a provider and model that supports the chosen type.</source>
</trans-unit>
```

**Step 6: Commit**

```
feat: add task_type TCA field with type-switching for completion vs image tasks
```

---

### Task 6: OpenAiImageAdapter - generischer Wrapper um DallEImageService

Statt DallEImageService direkt zu koppeln, ein Adapter der `ImageGenerationInterface` implementiert und Provider-Chain-Credentials nutzt.

**Files:**
- Create: `Classes/Specialized/Image/Adapter/OpenAiImageAdapter.php`
- Create: `Tests/Unit/Specialized/Image/Adapter/OpenAiImageAdapterTest.php`

**Step 1: Write the adapter**

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Specialized\Image\Adapter;

use Netresearch\NrLlm\Specialized\Exception\ServiceConfigurationException;
use Netresearch\NrLlm\Specialized\Image\ImageGenerationInterface;
use Netresearch\NrLlm\Specialized\Image\ImageGenerationResult;
use Netresearch\NrLlm\Specialized\Option\ImageGenerationOptions;
use Netresearch\NrLlm\Service\UsageTrackerServiceInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * OpenAI image generation adapter.
 *
 * Supports DALL-E 2, DALL-E 3, and gpt-image-1 models via the OpenAI Images API.
 * Receives credentials from the provider chain (not from ExtensionConfiguration).
 */
final class OpenAiImageAdapter implements ImageGenerationInterface
{
    private const DEFAULT_BASE_URL = 'https://api.openai.com/v1/images';
    private const DEFAULT_MODEL = 'dall-e-3';
    private const DEFAULT_SIZE = '1024x1024';

    private const SUPPORTED_MODELS = [
        'dall-e-2',
        'dall-e-3',
        'gpt-image-1',
    ];

    private const MODEL_MAX_PROMPT_LENGTH = [
        'dall-e-2' => 1000,
        'dall-e-3' => 4000,
        'gpt-image-1' => 32000,
    ];

    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly UsageTrackerServiceInterface $usageTracker,
        private readonly LoggerInterface $logger,
    ) {}

    public function getIdentifier(): string
    {
        return 'openai';
    }

    public function supportsModel(string $modelId): bool
    {
        return in_array($modelId, self::SUPPORTED_MODELS, true);
    }

    public function generate(
        string $prompt,
        array $providerConfig,
        ImageGenerationOptions|array $options = [],
    ): ImageGenerationResult {
        $apiKey = $providerConfig['apiKey'] ?? '';
        if ($apiKey === '') {
            throw ServiceConfigurationException::invalidApiKey('image', 'openai');
        }

        $baseUrl = $providerConfig['baseUrl'] ?? self::DEFAULT_BASE_URL;
        $modelId = $providerConfig['modelId'] ?? self::DEFAULT_MODEL;

        $options = $options instanceof ImageGenerationOptions
            ? $options
            : ImageGenerationOptions::fromArray($options);

        $optionsArray = $options->toArray();
        $model = is_string($optionsArray['model'] ?? null) ? $optionsArray['model'] : $modelId;
        $size = is_string($optionsArray['size'] ?? null) ? $optionsArray['size'] : self::DEFAULT_SIZE;
        $quality = is_string($optionsArray['quality'] ?? null) ? $optionsArray['quality'] : 'standard';
        $style = is_string($optionsArray['style'] ?? null) ? $optionsArray['style'] : 'vivid';

        // Validate prompt length
        $maxLength = self::MODEL_MAX_PROMPT_LENGTH[$model] ?? 4000;
        if (mb_strlen($prompt) > $maxLength) {
            throw new \InvalidArgumentException(
                sprintf('Prompt exceeds maximum length of %d characters for %s', $maxLength, $model),
            );
        }

        $payload = [
            'model' => $model,
            'prompt' => $prompt,
            'n' => 1,
            'size' => $size,
        ];

        // gpt-image-1 only supports b64_json
        if ($model === 'gpt-image-1') {
            $payload['response_format'] = 'b64_json';
        } else {
            $payload['response_format'] = $optionsArray['response_format'] ?? 'url';
        }

        // DALL-E 3 specific options
        if ($model === 'dall-e-3') {
            $payload['quality'] = $quality;
            $payload['style'] = $style;
        }

        // Send request
        $url = rtrim($baseUrl, '/') . '/generations';
        $request = $this->requestFactory->createRequest('POST', $url)
            ->withHeader('Authorization', 'Bearer ' . $apiKey)
            ->withHeader('Content-Type', 'application/json');

        $body = $this->streamFactory->createStream(json_encode($payload, JSON_THROW_ON_ERROR));
        $request = $request->withBody($body);

        try {
            $response = $this->httpClient->sendRequest($request);
            $statusCode = $response->getStatusCode();
            $responseBody = (string)$response->getBody();

            if ($statusCode < 200 || $statusCode >= 300) {
                /** @var array{error?: array{message?: string}} $error */
                $error = json_decode($responseBody, true) ?? [];
                $errorMessage = $error['error']['message'] ?? 'Unknown API error (HTTP ' . $statusCode . ')';
                throw new \RuntimeException('Image generation failed: ' . $errorMessage);
            }

            /** @var array{data?: array<int, array{url?: string, b64_json?: string, revised_prompt?: string}>} $responseData */
            $responseData = json_decode($responseBody, true, 512, JSON_THROW_ON_ERROR);
            $data = ($responseData['data'] ?? [])[0] ?? [];
        } catch (\RuntimeException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->logger->error('Image generation API error', ['exception' => $e->getMessage()]);
            throw new \RuntimeException('Image generation request failed: ' . $e->getMessage(), 0, $e);
        }

        $this->usageTracker->trackUsage('image', 'openai:' . $model, [
            'size' => $size,
            'quality' => $quality,
        ]);

        return new ImageGenerationResult(
            url: $data['url'] ?? '',
            base64: $data['b64_json'] ?? null,
            prompt: $prompt,
            revisedPrompt: $data['revised_prompt'] ?? null,
            model: $model,
            size: $size,
            provider: 'openai',
            metadata: [
                'quality' => $quality,
                'style' => $style,
            ],
        );
    }
}
```

**Step 2: Unit Test (grundlegend, HTTP wird gemockt)**

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Specialized\Image\Adapter;

use Netresearch\NrLlm\Specialized\Image\Adapter\OpenAiImageAdapter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(OpenAiImageAdapter::class)]
final class OpenAiImageAdapterTest extends TestCase
{
    #[Test]
    public function identifierIsOpenai(): void
    {
        $adapter = $this->createAdapter();
        self::assertSame('openai', $adapter->getIdentifier());
    }

    #[Test]
    public function supportsDalleAndGptImageModels(): void
    {
        $adapter = $this->createAdapter();
        self::assertTrue($adapter->supportsModel('dall-e-2'));
        self::assertTrue($adapter->supportsModel('dall-e-3'));
        self::assertTrue($adapter->supportsModel('gpt-image-1'));
        self::assertFalse($adapter->supportsModel('gpt-4o'));
        self::assertFalse($adapter->supportsModel('stable-diffusion'));
    }

    #[Test]
    public function generateThrowsOnEmptyApiKey(): void
    {
        $adapter = $this->createAdapter();
        $this->expectException(\Netresearch\NrLlm\Specialized\Exception\ServiceConfigurationException::class);
        $adapter->generate('test prompt', ['apiKey' => '']);
    }

    private function createAdapter(): OpenAiImageAdapter
    {
        return new OpenAiImageAdapter(
            $this->createMock(\Psr\Http\Client\ClientInterface::class),
            $this->createMock(\Psr\Http\Message\RequestFactoryInterface::class),
            $this->createMock(\Psr\Http\Message\StreamFactoryInterface::class),
            $this->createMock(\Netresearch\NrLlm\Service\UsageTrackerServiceInterface::class),
            $this->createMock(\Psr\Log\LoggerInterface::class),
        );
    }
}
```

**Step 3: Commit**

```
feat: add OpenAiImageAdapter implementing ImageGenerationInterface
```

---

### Task 7: ImageGenerationAdapterRegistry erstellen

Mappt `adapter_type` aus der Provider-Kette auf den richtigen Image-Adapter. Analog zu `ProviderAdapterRegistry` für Text-LLMs.

**Files:**
- Create: `Classes/Specialized/Image/ImageGenerationAdapterRegistry.php`
- Create: `Tests/Unit/Specialized/Image/ImageGenerationAdapterRegistryTest.php`

**Step 1: Write the registry**

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Specialized\Image;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Specialized\Exception\ServiceUnavailableException;
use Netresearch\NrLlm\Specialized\Option\ImageGenerationOptions;

/**
 * Registry for image generation adapters.
 *
 * Maps provider adapter_type values to ImageGenerationInterface implementations.
 * Resolves credentials from the provider chain and delegates to the appropriate adapter.
 */
final class ImageGenerationAdapterRegistry
{
    /** @var array<string, ImageGenerationInterface> adapter_type => adapter instance */
    private array $adapters = [];

    /**
     * Register an image generation adapter for a provider type.
     */
    public function registerAdapter(string $adapterType, ImageGenerationInterface $adapter): void
    {
        $this->adapters[$adapterType] = $adapter;
    }

    /**
     * Check if an adapter exists for the given provider type.
     */
    public function hasAdapter(string $adapterType): bool
    {
        return isset($this->adapters[$adapterType]);
    }

    /**
     * Generate an image using the provider chain from an LlmConfiguration.
     *
     * Resolves: Configuration → Model → Provider → adapter_type → ImageGenerationInterface
     */
    public function generateFromConfiguration(
        string $prompt,
        LlmConfiguration $configuration,
        ImageGenerationOptions|array $options = [],
    ): ImageGenerationResult {
        $model = $configuration->getLlmModel();
        if ($model === null) {
            throw new ServiceUnavailableException(
                'No model configured for image generation',
                'image',
                [],
            );
        }

        $provider = $model->getProvider();
        if ($provider === null) {
            throw new ServiceUnavailableException(
                'No provider configured for model ' . $model->getIdentifier(),
                'image',
                [],
            );
        }

        $adapterType = $provider->getAdapterType();
        if (!$this->hasAdapter($adapterType)) {
            throw new ServiceUnavailableException(
                sprintf('No image generation adapter registered for provider type "%s"', $adapterType),
                'image',
                ['adapterType' => $adapterType],
            );
        }

        $adapter = $this->adapters[$adapterType];

        $providerConfig = [
            'apiKey' => $provider->getDecryptedApiKey(),
            'baseUrl' => $this->resolveImageEndpoint($provider->getEffectiveEndpointUrl(), $adapterType),
            'modelId' => $model->getModelId(),
            'timeout' => $provider->getApiTimeout(),
        ];

        return $adapter->generate($prompt, $providerConfig, $options);
    }

    /**
     * Resolve the image-specific endpoint from the provider's base URL.
     *
     * OpenAI text endpoint: https://api.openai.com/v1
     * OpenAI image endpoint: https://api.openai.com/v1/images
     */
    private function resolveImageEndpoint(string $baseUrl, string $adapterType): string
    {
        if ($baseUrl === '') {
            return match ($adapterType) {
                'openai' => 'https://api.openai.com/v1/images',
                default => '',
            };
        }

        // If URL already contains /images, use as-is
        if (str_contains($baseUrl, '/images')) {
            return $baseUrl;
        }

        // Append /images to base URL
        return rtrim($baseUrl, '/') . '/images';
    }
}
```

**Step 2: Unit Test**

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Specialized\Image;

use Netresearch\NrLlm\Specialized\Image\ImageGenerationAdapterRegistry;
use Netresearch\NrLlm\Specialized\Image\ImageGenerationInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ImageGenerationAdapterRegistry::class)]
final class ImageGenerationAdapterRegistryTest extends TestCase
{
    #[Test]
    public function registerAndHasAdapter(): void
    {
        $registry = new ImageGenerationAdapterRegistry();
        $adapter = $this->createMock(ImageGenerationInterface::class);

        self::assertFalse($registry->hasAdapter('openai'));
        $registry->registerAdapter('openai', $adapter);
        self::assertTrue($registry->hasAdapter('openai'));
    }

    #[Test]
    public function hasAdapterReturnsFalseForUnknown(): void
    {
        $registry = new ImageGenerationAdapterRegistry();
        self::assertFalse($registry->hasAdapter('nonexistent'));
    }

    #[Test]
    public function generateFromConfigurationThrowsWithoutModel(): void
    {
        $registry = new ImageGenerationAdapterRegistry();

        $config = $this->createMock(\Netresearch\NrLlm\Domain\Model\LlmConfiguration::class);
        $config->method('getLlmModel')->willReturn(null);

        $this->expectException(\Netresearch\NrLlm\Specialized\Exception\ServiceUnavailableException::class);
        $registry->generateFromConfiguration('test', $config);
    }
}
```

**Step 3: Commit**

```
feat: add ImageGenerationAdapterRegistry for provider-chain image generation
```

---

### Task 8: DI-Konfiguration - Registry + Adapter verdrahten

**Files:**
- Modify: `Configuration/Services.yaml`

**Step 1: Services registrieren**

```yaml
  # Image Generation
  Netresearch\NrLlm\Specialized\Image\ImageGenerationAdapterRegistry:
    public: true

  Netresearch\NrLlm\Specialized\Image\Adapter\OpenAiImageAdapter:
    public: true
    tags:
      - name: nr_llm.image_adapter
        adapter_type: openai
```

**Step 2: Configurator oder CompilerPass**

Entweder einen Configurator auf der Registry:

```yaml
  Netresearch\NrLlm\Specialized\Image\ImageGenerationAdapterRegistry:
    public: true
    configurator: ['@Netresearch\NrLlm\Specialized\Image\ImageAdapterConfigurator', 'configure']
```

Oder einfacher - direkte Registrierung via `calls`:

```yaml
  Netresearch\NrLlm\Specialized\Image\ImageGenerationAdapterRegistry:
    public: true
    calls:
      - method: registerAdapter
        arguments:
          - 'openai'
          - '@Netresearch\NrLlm\Specialized\Image\Adapter\OpenAiImageAdapter'
```

Die `calls`-Variante ist einfacher und reicht vorerst. Ein CompilerPass mit Tagged Services wäre eleganter für viele Adapter, aber YAGNI.

**Step 3: Commit**

```
feat: wire ImageGenerationAdapterRegistry with OpenAiImageAdapter in DI
```

---

### Task 9: TaskController um Image-Routing erweitern

Das Kernstück - die `executeAction()` routet basierend auf `task_type`.

**Files:**
- Modify: `Classes/Controller/Backend/TaskController.php`

**Step 1: Dependency Injection erweitern**

```php
use Netresearch\NrLlm\Specialized\Image\ImageGenerationAdapterRegistry;

// Im Constructor hinzufügen:
private readonly ImageGenerationAdapterRegistry $imageGenerationRegistry,
```

**Step 2: executeAction() erweitern**

Im try-Block, vor der bestehenden Completion-Logik:

```php
try {
    $prompt = $task->buildPrompt(['input' => $dto->input]);
    $configuration = $task->getConfiguration();

    // Route image generation tasks to image adapter registry
    if ($task->isImageGeneration()) {
        if ($configuration === null) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Image generation tasks require an LLM Configuration with an image-capable provider and model.',
            ]);
        }

        $result = $this->imageGenerationRegistry->generateFromConfiguration($prompt, $configuration);

        return new JsonResponse([
            'success' => true,
            'type' => 'image',
            'imageUrl' => $result->url,
            'imageBase64' => $result->base64 !== null ? $result->toDataUrl() : null,
            'revisedPrompt' => $result->revisedPrompt,
            'model' => $result->model,
            'size' => $result->size,
            'provider' => $result->provider,
        ]);
    }

    // Existing text completion logic (unchanged)
    if ($configuration !== null) {
        $response = $this->llmServiceManager->completeWithConfiguration($prompt, $configuration);
    } else {
        $response = $this->llmServiceManager->complete($prompt, new ChatOptions());
    }

    // ... existing response ...
```

**Step 3: Commit**

```
feat: route image_generation tasks through ImageGenerationAdapterRegistry
```

---

### Task 10: Frontend - Bild-Ausgabe in Execute.html + TaskExecute.js

**Files:**
- Modify: `Resources/Private/Templates/Backend/Task/Execute.html`
- Modify: `Resources/Public/JavaScript/Backend/TaskExecute.js` (Pfad verifizieren!)

**Step 1: HTML - Image-Output-Container nach `#outputResult`**

```html
<div id="outputImage" class="d-none">
    <div class="text-center mb-3">
        <img id="generatedImage" src="" alt="Generated image"
             class="img-fluid rounded shadow" style="max-height: 512px;" />
    </div>
    <div class="alert alert-info small d-none" id="revisedPromptInfo">
        <strong>Revised prompt:</strong>
        <span id="revisedPromptText"></span>
    </div>
    <div class="mt-3 d-flex justify-content-between align-items-center">
        <small class="text-muted">
            Model: <span id="outputImageModel">-</span> |
            Size: <span id="outputImageSize">-</span> |
            Provider: <span id="outputImageProvider">-</span>
        </small>
        <a id="downloadImageBtn" href="" download="generated-image.png"
           class="btn btn-sm btn-default">
            <core:icon identifier="actions-download" size="small" />
            Download
        </a>
    </div>
</div>
```

**Step 2: JavaScript - Response-Handler**

Im Success-Callback:

```javascript
if (data.type === 'image') {
    document.getElementById('outputResult').classList.add('d-none');
    document.getElementById('outputImage').classList.remove('d-none');

    const img = document.getElementById('generatedImage');
    img.src = data.imageBase64 || data.imageUrl;

    if (data.revisedPrompt) {
        document.getElementById('revisedPromptText').textContent = data.revisedPrompt;
        document.getElementById('revisedPromptInfo').classList.remove('d-none');
    } else {
        document.getElementById('revisedPromptInfo').classList.add('d-none');
    }

    document.getElementById('outputImageModel').textContent = data.model || '-';
    document.getElementById('outputImageSize').textContent = data.size || '-';
    document.getElementById('outputImageProvider').textContent = data.provider || '-';
    document.getElementById('downloadImageBtn').href = data.imageBase64 || data.imageUrl;
} else {
    document.getElementById('outputImage').classList.add('d-none');
    document.getElementById('outputResult').classList.remove('d-none');
    // ... existing text handling ...
}
```

**Step 3: Reset bei neuem Execute** - `#outputImage` ausblenden wenn Execute erneut geklickt wird.

**Step 4: Commit**

```
feat: display generated images in task execution view
```

---

### Task 11: E2E-Test - Kompletter Flow

**Step 1: DB-Schema aktualisieren**

```bash
php vendor/bin/typo3 database:updateschema
```

**Step 2: Records im Backend anlegen**

1. **Provider**: "OpenAI" (adapter_type: `openai`, API Key via Vault)
2. **Model**: "DALL-E 3" (model_id: `dall-e-3`, capabilities: `image_generation`, provider: OpenAI)
3. **Configuration**: "Image Gen Config" (model: DALL-E 3)
4. **Task**: "Generate Image" (task_type: `image_generation`, configuration: Image Gen Config, prompt_template: `{{input}}`)

**Step 3: Ausführen**

- Task öffnen → Input: "A professional photo of a modern office workspace"
- Execute klicken
- Erwartung: Bild wird generiert und inline angezeigt, mit Model/Size/Provider Info

---

## Erweiterbarkeit

Neuen Image-Provider hinzufügen (z.B. Stability AI):

1. `Classes/Specialized/Image/Adapter/StabilityImageAdapter.php` erstellen (implements `ImageGenerationInterface`)
2. In Services.yaml registrieren:
   ```yaml
   Netresearch\NrLlm\Specialized\Image\Adapter\StabilityImageAdapter:
     public: true
   ```
   Und in der Registry:
   ```yaml
   calls:
     - method: registerAdapter
       arguments: ['stability', '@...StabilityImageAdapter']
   ```
3. Provider mit adapter_type `stability` + passendem Model anlegen
4. Fertig - Task mit der Configuration funktioniert automatisch

---

## Offene Punkte

1. **Vault-Integration**: `Provider.getDecryptedApiKey()` muss korrekt funktionieren. Testen ob der VaultService im Task-Controller-Kontext verfügbar ist.

2. **gpt-image-1**: Der `OpenAiImageAdapter` unterstützt es bereits (b64_json only, 32k prompt length). Die `ImageGenerationOptions`-Validation (die nur dall-e-2/dall-e-3 kennt) muss ggf. relaxed werden für unbekannte Models.

3. **ImageGenerationOptions Kompatibilität**: Die bestehende `ImageGenerationOptions`-Klasse validiert hart gegen DALL-E Modelle. Der `OpenAiImageAdapter` umgeht das teilweise, aber bei `fromArray()` könnte es Validation-Fehler geben. Ggf. Options-Validation für unbekannte Models überspringen.

4. **DallEImageService Koexistenz**: Der alte `DallEImageService` bleibt unverändert für Abwärtskompatibilität (nr-landingpage nutzt ihn direkt). Langfristig könnte nr-landingpage auf `ImageGenerationAdapterRegistry` umgestellt werden.
