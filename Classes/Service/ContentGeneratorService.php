<?php

declare(strict_types=1);

namespace Netresearch\NrLandingpage\Service;

use Netresearch\NrLandingpage\Domain\Model\Template;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Service\Feature\CompletionService;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Throwable;
use TYPO3\HtmlSanitizer\Behavior;
use TYPO3\HtmlSanitizer\Behavior\Attr;
use TYPO3\HtmlSanitizer\Behavior\Tag;
use TYPO3\HtmlSanitizer\Sanitizer;
use TYPO3\HtmlSanitizer\Visitor\CommonVisitor;

final class ContentGeneratorService implements LoggerAwareInterface
{
    use LoggerAwareTrait;
    use LlmCompletionTrait;

    private ?Sanitizer $sanitizer = null;

    private function sanitizeHtml(string $html): string
    {
        return $this->getSanitizer()->sanitize($html);
    }

    private function getSanitizer(): Sanitizer
    {
        if ($this->sanitizer === null) {
            $hrefAttr = (new Attr('href'))
                ->addValues(new Behavior\RegExpAttrValue('#^(https?://|/|mailto:|tel:)#'));

            $behavior = (new Behavior())
                ->withFlags(Behavior::ENCODE_INVALID_TAG)
                ->withTags(
                    new Tag('p'),
                    new Tag('br'),
                    new Tag('ul'),
                    new Tag('ol'),
                    new Tag('li'),
                    new Tag('strong'),
                    new Tag('em'),
                    (new Tag('a'))->addAttrs($hrefAttr),
                    new Tag('h2'),
                    new Tag('h3'),
                    new Tag('h4'),
                );
            $visitor = new CommonVisitor($behavior);
            $this->sanitizer = new Sanitizer($behavior, $visitor);
        }
        return $this->sanitizer;
    }

    public function __construct(
        private readonly CompletionService $completionService,
        private readonly LlmServiceManagerInterface $llmServiceManager,
        private readonly LlmConfigurationRepository $configurationRepository,
        private readonly CTypeMetadataService $cTypeMetadataService,
        private readonly BackendLayoutService $backendLayoutService,
    ) {}

    /**
     * Generate content sections for a landing page via LLM.
     *
     * @param array<string, string> $briefingAnswers
     * @return list<array{section: string, ctype: string, colPos: int, header: string, subheader: string, bodytext: string, imageKeywords: list<string>, imagePrompt: string}>
     */
    public function generateContent(Template $template, array $briefingAnswers, string $outputLanguage = ''): array
    {
        try {
            $prompt = $this->buildContentPrompt($template, $briefingAnswers, $outputLanguage);
            $response = $this->completeJsonWithTemplate($template, $prompt);
        } catch (Throwable $e) {
            $this->logger?->error('Content generation failed', [
                'template' => $template->identifier,
                'error' => $e->getMessage(),
            ]);
            return [];
        }

        $columnMap = $this->backendLayoutService->getColumnMap($template->backendLayout);
        $validColPositions = array_keys($columnMap);

        return $this->validateSections($response, $template->allowedCTypes, $validColPositions);
    }

    /**
     * Generate page field values (title, seo_title, description, etc.) via LLM.
     *
     * @param array<string, string> $briefingAnswers
     * @return array<string, string>
     */
    public function generatePageFields(Template $template, array $briefingAnswers, string $outputLanguage = ''): array
    {
        try {
            $prompt = $this->buildPageFieldsPrompt($template, $briefingAnswers, $outputLanguage);
            $response = $this->completeJsonWithTemplate($template, $prompt);
        } catch (Throwable $e) {
            $this->logger?->error('Page field generation failed', [
                'template' => $template->identifier,
                'error' => $e->getMessage(),
            ]);
            return [];
        }

        return $this->validatePageFields($response, $template->pageFields);
    }

    /**
     * @param array<string, string> $answers
     */
    private function formatBriefing(array $answers): string
    {
        $lines = [];
        foreach ($answers as $key => $value) {
            $lines[] = '- ' . $key . ': ' . $value;
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<string, string> $briefingAnswers
     */
    private function buildContentPrompt(Template $template, array $briefingAnswers, string $outputLanguage = ''): string
    {
        $briefing = $this->formatBriefing($briefingAnswers);
        $cTypes = $template->allowedCTypes !== []
            ? implode(', ', $template->allowedCTypes)
            : 'text, textmedia, textpic';
        $cTypeInstruction = $template->allowedCTypes !== []
            ? "Verwende ausschliesslich folgende Content-Typen: {$cTypes}"
            : "Verwende gaengige Content-Typen wie: {$cTypes}";

        $cTypeMetadata = $this->buildCTypeMetadataBlock($template->allowedCTypes);
        $columnBlock = $this->buildColumnBlock($template->backendLayout);
        $languageBlock = $this->buildLanguageBlock($outputLanguage);

        return <<<PROMPT
            {$template->systemPrompt}

            Briefing:
            {$briefing}
            {$cTypeMetadata}
            {$columnBlock}
            --- ANWEISUNGEN ZUR AUSGABE ---
            {$languageBlock}
            Erstelle Inhalte fuer eine Landing Page basierend auf dem obigen Kontext.
            {$cTypeInstruction}

            Strukturiere die Seite als konversionsstarke Landing Page:
            - Beginne mit einer aufmerksamkeitsstarken Hero-Section (klare Headline, Subheadline, Nutzenversprechen)
            - Folge mit konkreten Vorteilen oder Features (Bullet Points oder kurze Absaetze)
            - Fuelle die Seite mit Social Proof, Testimonials oder Vertrauenssignalen
            - Schliesse mit einem klaren Call-to-Action

            Schreibe ueberzeugend, konkret und nutzenorientiert. Vermeide generische Floskeln.
            Jede Section soll einen klaren Zweck im Conversion-Funnel haben.

            Befuelle fuer jeden Content-Typ die korrekten Felder gemaess der obigen Feldbeschreibungen.
            Verwende nur Felder, die fuer den jeweiligen CType definiert sind.

            WICHTIG: Erzeuge KEINE <img>-Tags oder Bild-URLs im bodytext.
            Bilder werden separat aus der CMS-Mediathek zugeordnet.
            Der bodytext soll ausschliesslich Text-HTML enthalten (p, ul, ol, h2-h4, strong, em, a).

            Fuer jede Section: Schlage 3-5 Suchbegriffe vor (imageKeywords), mit denen
            passende Bilder in einer Mediathek gefunden werden koennen.
            Verwende konkrete, beschreibende Einzelwoerter oder kurze Phrasen auf Englisch
            (z.B. "business meeting", "data analytics dashboard", "customer support").

            Erstelle zusaetzlich pro Section einen detaillierten englischen Bild-Prompt (imagePrompt),
            der ein passendes Foto beschreibt. Der Prompt soll enthalten:
            - Hauptmotiv und Kontext (was ist zu sehen?)
            - Bildstil (Foto, Illustration, Icon-Stil)
            - Stimmung und Farbpalette
            - Perspektive und Komposition
            Beispiel: "Overhead view of a modern coworking space with diverse professionals collaborating around laptops, natural daylight, warm tones, shallow depth of field"

            Antworte ausschliesslich als JSON-Array:
            [
              {"section": "string", "ctype": "one of [{$cTypes}]", "colPos": 0,
               "header": "string", "subheader": "string", "bodytext": "HTML string",
               "imageKeywords": ["keyword1", "keyword2", "keyword3"],
               "imagePrompt": "A detailed description of an image suitable for this section"}
            ]
            PROMPT;
    }

    /**
     * Build the CType metadata block for the prompt.
     *
     * @param list<string> $allowedCTypes
     */
    private function buildCTypeMetadataBlock(array $allowedCTypes): string
    {
        $cTypesToDescribe = $allowedCTypes !== [] ? $allowedCTypes : ['text', 'textmedia', 'textpic'];

        $json = $this->cTypeMetadataService->buildCTypeDescription($cTypesToDescribe);
        if ($json === '') {
            return '';
        }

        return <<<BLOCK

            --- VERFUEGBARE CONTENT-TYPEN (CMS-Felddefinitionen) ---
            Jeder Content-Typ hat bestimmte Felder, die du befuellen kannst.
            Verwende nur die hier aufgelisteten Felder pro CType:

            {$json}
            BLOCK;
    }

    /**
     * Build the column position block for the prompt.
     */
    private function buildColumnBlock(string $backendLayout): string
    {
        $columnMap = $this->backendLayoutService->getColumnMap($backendLayout);
        $formatted = $this->backendLayoutService->formatColumnMapForPrompt($columnMap);

        if ($formatted === '') {
            return '';
        }

        $columnCount = count($columnMap);

        return <<<BLOCK

            --- VERFUEGBARE SPALTEN (Backend Layout) ---
            Die Seite hat {$columnCount} Inhaltsbereiche (Spalten). Du MUSST fuer JEDE Spalte
            mindestens ein passendes Content-Element erzeugen.

            Verfuegbare Spalten:
            {$formatted}

            Regeln fuer die Spalten-Zuweisung:
            1. Setze "colPos" im JSON auf die Nummer der Spalte, in die der Inhalt gehoert.
            2. Jede Spalte MUSS mindestens ein Content-Element erhalten — keine Spalte darf leer bleiben.
            3. Leite den Zweck jeder Spalte aus ihrem Namen ab:
               - "Main", "Content", "Hauptinhalt" → umfangreicher Seiteninhalt (Hero, Features, Texte)
               - "Sidebar", "Seitenleiste", "Aside" → kompakte Zusatzinfos (Kontakt, Links, CTAs, Teaser)
               - "Footer", "Fusszeile" → Abschluss-Elemente (CTA, Kontaktdaten, Copyright-Hinweis)
               - "Header", "Kopfzeile", "Top" → einleitende Elemente (Hero-Banner, Navigation-Highlights)
               - "Banner", "Stage" → prominente visuelle Elemente
               - Andere Namen → interpretiere den Zweck sinnvoll aus dem Namen
            4. Verteile den Inhalt so, dass jede Spalte ihrem Zweck entsprechend gefuellt wird.
               Hauptinhalt-Spalten bekommen mehr und laengere Sections,
               Sidebar/Footer-Spalten bekommen kuerzere, fokussierte Sections.
            BLOCK;
    }

    /**
     * @param array<string, string> $briefingAnswers
     */
    private function buildPageFieldsPrompt(Template $template, array $briefingAnswers, string $outputLanguage = ''): string
    {
        $briefing = $this->formatBriefing($briefingAnswers);
        $fields = $template->pageFields !== []
            ? implode(', ', $template->pageFields)
            : 'title, seo_title, description, og_title, og_description';
        $languageBlock = $this->buildLanguageBlock($outputLanguage);

        return <<<PROMPT
            {$template->systemPrompt}

            Briefing:
            {$briefing}

            --- ANWEISUNGEN ZUR AUSGABE ---
            {$languageBlock}
            Generiere Werte fuer folgende Seitenfelder: {$fields}

            Regeln fuer SEO-Felder:
            - title: Praegnanter Seitentitel, max. 70 Zeichen
            - seo_title: Klickstarker SEO-Titel mit Hauptkeyword am Anfang, max. 60 Zeichen
            - description: Ueberzeugende Meta-Description mit Call-to-Action, 120-160 Zeichen
            - og_title: Aufmerksamkeitsstarker Social-Media-Titel, max. 60 Zeichen
            - og_description: Neugier weckende Social-Media-Beschreibung, max. 200 Zeichen
            - slug: URL-Pfad in Kleinbuchstaben mit Bindestrichen, keine Umlaute

            Antworte ausschliesslich als JSON-Objekt:
            {"field_name": "value", ...}
            PROMPT;
    }

    /**
     * Build a language instruction block for the LLM prompt.
     *
     * When an output language is known (resolved from the site's default language),
     * instructs the LLM to generate all user-facing content in that language.
     */
    private function buildLanguageBlock(string $outputLanguage): string
    {
        if ($outputLanguage === '') {
            return '';
        }

        return <<<BLOCK

            WICHTIG: Schreibe ALLE generierten Inhalte (header, subheader, bodytext, title, description etc.)
            in folgender Sprache: {$outputLanguage}.
            Die Sprache dieser Anweisungen ist irrelevant — der Output MUSS in {$outputLanguage} sein.
            BLOCK;
    }

    /**
     * @param list<string> $allowedCTypes
     * @param list<int> $validColPositions
     * @return list<array{section: string, ctype: string, colPos: int, header: string, subheader: string, bodytext: string, imageKeywords: list<string>, imagePrompt: string}>
     */
    private function validateSections(mixed $response, array $allowedCTypes, array $validColPositions = [0]): array
    {
        if (!is_array($response)) {
            return [];
        }

        $validated = [];
        foreach ($response as $item) {
            if (!is_array($item) || !isset($item['section'], $item['ctype'])) {
                continue;
            }

            if (!is_string($item['section']) || !is_string($item['ctype'])) {
                continue;
            }

            $ctype = $item['ctype'];
            if ($allowedCTypes !== [] && !in_array($ctype, $allowedCTypes, true)) {
                $ctype = 'text';
            }

            $bodytext = is_string($item['bodytext'] ?? null) ? $item['bodytext'] : '';

            $imageKeywords = [];
            if (is_array($item['imageKeywords'] ?? null)) {
                foreach ($item['imageKeywords'] as $kw) {
                    if (is_string($kw) && trim($kw) !== '') {
                        $imageKeywords[] = trim($kw);
                    }
                }
            }

            $rawColPos = $item['colPos'] ?? 0;
            $colPos = is_int($rawColPos) ? $rawColPos : (is_numeric($rawColPos) ? (int) $rawColPos : 0);
            if ($validColPositions !== [] && !in_array($colPos, $validColPositions, true)) {
                $colPos = $validColPositions[0];
            }

            $validated[] = [
                'section' => $item['section'],
                'ctype' => $ctype,
                'colPos' => $colPos,
                'header' => is_string($item['header'] ?? null) ? $item['header'] : '',
                'subheader' => is_string($item['subheader'] ?? null) ? $item['subheader'] : '',
                'bodytext' => $this->sanitizeHtml($bodytext),
                'imageKeywords' => $imageKeywords,
                'imagePrompt' => is_string($item['imagePrompt'] ?? null) ? $item['imagePrompt'] : '',
            ];
        }

        return $validated;
    }

    /**
     * @param list<string> $allowedFields
     * @return array<string, string>
     */
    private function validatePageFields(mixed $response, array $allowedFields): array
    {
        if (!is_array($response)) {
            return [];
        }

        $validated = [];
        foreach ($response as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            // When allowedFields is empty, accept all string-keyed fields
            if ($allowedFields !== [] && !in_array($key, $allowedFields, true)) {
                continue;
            }

            if (!is_string($value)) {
                continue;
            }

            $validated[$key] = $value;
        }

        return $validated;
    }
}
