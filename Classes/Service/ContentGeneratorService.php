<?php

declare(strict_types=1);

namespace Netresearch\NrLandingpage\Service;

use Netresearch\NrLandingpage\Domain\Model\Template;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Service\Feature\CompletionServiceInterface;
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

    private const VALID_IMAGEORIENT = [0, 1, 2, 8, 9, 10, 17, 18, 25, 26];

    private ?Sanitizer $sanitizer = null;

    private function sanitizeHtml(string $html): string
    {
        return $this->getSanitizer()->sanitize($html);
    }

    private function sanitizeCreativeHtml(string $html, bool $allowScripts = false): string
    {
        return $this->creativeHtmlSanitizer->sanitize($html, $allowScripts);
    }

    /**
     * Remove CSS that reached a structured section.
     *
     * Structured mode has no home for CSS. Its sections go through the strict
     * sanitizer, whose whitelist has no `style` tag and whose ENCODE_INVALID_TAG
     * flag keeps a rejected tag's text content — so a rule set arrives on the
     * page as visible body text. 0.3.5 removed the prompt instruction that
     * produced one; this removes the class of failure, because a model can emit
     * a stray rule set without being asked for it.
     *
     * Creative mode owns a <style> block and must keep it. It is sanitized
     * elsewhere and does not pass through here.
     *
     * The discriminator is the brace body, not the selector: a block is removed
     * only when its contents are entirely CSS declarations. Prose in braces, a
     * JSON fragment and anything else keep their braces.
     */
    private function stripCssRuleSets(string $html): string
    {
        // A complete <style> element, contents included. The sanitizer would
        // encode the tag and leave the declarations behind as text.
        $html = preg_replace('#<style\b[^>]*>.*?</style\s*>#is', '', $html) ?? $html;

        // An unpaired <style> or </style>: drop the tag only and let the rule-set
        // pass below decide about the declarations around it.
        $html = preg_replace('#</?style\b[^>]*>#i', '', $html) ?? $html;

        // Innermost rule sets first, so a wrapping at-rule empties out and is
        // removed on a later pass. Five passes cover any nesting a model emits.
        for ($pass = 0; $pass < 5; $pass++) {
            $next = preg_replace_callback(
                '#(?<selector>[^{}<>]{1,200}?)\{(?<body>[^{}]*)\}#s',
                static function (array $m): string {
                    $selector = trim($m['selector']);
                    $body     = trim($m['body']);

                    // A selector is short and has no sentence in it.
                    if ($selector === '' || count(preg_split('#\s+#', $selector) ?: []) > 8) {
                        return $m[0];
                    }

                    // An at-rule whose block emptied out on an earlier pass.
                    if ($body === '') {
                        return str_starts_with($selector, '@') ? '' : $m[0];
                    }

                    // Every declaration must be `property: value`, custom
                    // properties included. One that is not keeps the block.
                    $withoutComments = preg_replace('#/\*.*?\*/#s', '', $body) ?? $body;
                    foreach (explode(';', $withoutComments) as $declaration) {
                        if (trim($declaration) === '') {
                            continue;
                        }
                        if (preg_match('#^\s*(?:--)?[A-Za-z-][A-Za-z0-9-]*\s*:\s*[^;]+$#', $declaration) !== 1) {
                            return $m[0];
                        }
                    }

                    return '';
                },
                $html,
            );

            if ($next === null || $next === $html) {
                break;
            }
            $html = $next;
        }

        return trim(preg_replace('#\n{3,}#', "\n\n", $html) ?? $html);
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
        private readonly CompletionServiceInterface $completionService,
        private readonly LlmServiceManagerInterface $llmServiceManager,
        private readonly LlmConfigurationRepository $configurationRepository,
        private readonly CTypeMetadataService $cTypeMetadataService,
        private readonly BackendLayoutService $backendLayoutService,
        private readonly CreativeHtmlSanitizer $creativeHtmlSanitizer,
    ) {}

    /**
     * Generate content sections for a landing page via LLM.
     *
     * @param array<string, string> $briefingAnswers
     * @param int $parentPageId Page ID used to resolve TSconfig-based backend layouts
     * @return list<array{section: string, ctype: string, colPos: int, header: string, subheader: string, bodytext: string, imageKeywords: list<string>, imagePrompt: string, animation?: array{type?: string, duration?: float, delay?: float, stagger?: float}}>
     */
    public function generateContent(Template $template, array $briefingAnswers, string $outputLanguage = '', int $parentPageId = 0): array
    {
        if ($template->isCreativeMode()) {
            return $this->generateCreativeContent($template, $briefingAnswers, $outputLanguage, $parentPageId);
        }

        $prompt = $this->buildContentPrompt($template, $briefingAnswers, $outputLanguage, $parentPageId);
        $response = $this->completeJsonWithTemplate($template, $prompt, 'generateContent');

        $columnMap = $this->backendLayoutService->getColumnMap($template->backendLayout, $parentPageId);
        $columnMap = $this->filterColumnMap($columnMap, $template->contentColumns);
        $validColPositions = array_keys($columnMap);

        return $this->validateSections($response, $template->allowedCTypes, $validColPositions);
    }

    /**
     * Generate creative HTML content for each layout column.
     *
     * @param array<string, string> $briefingAnswers
     * @return list<array{section: string, ctype: string, colPos: int, header: string, subheader: string, bodytext: string, imageKeywords: list<string>, imagePrompt: string}>
     */
    private function generateCreativeContent(Template $template, array $briefingAnswers, string $outputLanguage, int $parentPageId): array
    {
        $columnMap = $this->backendLayoutService->getColumnMap($template->backendLayout, $parentPageId);
        if ($columnMap === []) {
            $columnMap = [0 => 'Main'];
        }
        $columnMap = $this->filterColumnMap($columnMap, $template->contentColumns);

        $prompt = $this->buildCreativePrompt($template, $briefingAnswers, $outputLanguage, $columnMap);
        $response = $this->completeJsonWithTemplate($template, $prompt, 'generateCreativeContent');

        $allowScripts = $template->isAnimationEnabled();

        return $this->validateCreativeSections($response, $columnMap, $allowScripts);
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
            $response = $this->completeJsonWithTemplate($template, $prompt, 'generatePageFields');
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
     * The colour scheme, phrased for the mode that has to carry it.
     *
     * Only creative mode has a home for CSS: its sections are rendered with the
     * `html` CType and its own <style> block. Structured mode is semantic HTML
     * only — the same prompt forbids visual styling a few lines further down —
     * so a ruleset produced there lands in bodytext and is displayed as text.
     * That is how a literal ":root { --primary: … }" ended up in the middle of a
     * generated page: the model was doing what this block told it to.
     */
    private function buildColorBlock(Template $template): string
    {
        if ($template->colorPrimary === '' && $template->colorSecondary === ''
            && $template->colorBackground === '' && $template->colorText === '') {
            return '';
        }

        if (!$template->isCreativeMode()) {
            // No CSS syntax at all: naming a property is enough for the image
            // prompts, and anything that looks like a rule invites one.
            return <<<COLORS

                --- FARBSCHEMA ---
                Farben dieser Seite: Primaer {$template->colorPrimary}, Sekundaer {$template->colorSecondary}, Hintergrund {$template->colorBackground}, Text {$template->colorText}.
                Diese Angaben sind reine Information, etwa fuer Bildbeschreibungen.
                Schreibe KEIN CSS und keine Style-Regeln. Das Design kommt vom Frontend-Template.
                COLORS;
        }

        return <<<COLORS

            --- FARBSCHEMA (PFLICHT) ---
            Du MUSST ausschliesslich folgende Farben verwenden. Keine eigenen Farben erfinden.
            Definiere die CSS Custom Properties IM <style>-Block der ERSTEN Section:
            <style>:root { --primary: {$template->colorPrimary}; --secondary: {$template->colorSecondary}; --bg: {$template->colorBackground}; --text: {$template->colorText}; }</style>
            Nutze in allen Sections var(--primary), var(--secondary), var(--bg), var(--text).
            Erlaubt sind Abstufungen per opacity/lighten/darken (z.B. rgba, color-mix).
            COLORS;
    }

    /**
     * Build the script example fragment for the creative mode JSON bodytext example.
     */
    private function buildCreativeBodytextExample(Template $template): string
    {
        if (!$template->isAnimationEnabled()) {
            return '';
        }

        return "<script data-creative>document.addEventListener('DOMContentLoaded', function() { gsap.from('.hero-title', {scrollTrigger: '.hero', opacity: 0, y: 30, duration: 0.8}); });</script>";
    }

    private function buildAnimationBlock(Template $template): string
    {
        if (!$template->isAnimationEnabled()) {
            return '';
        }

        return <<<BLOCK

            Optional pro Section: "animation" Objekt.
            Moegliche Typen: fade-up, fade-down, slide-left, slide-right,
            zoom-in, scale-up, stagger-children, typewriter, parallax.
            Nicht jede Section braucht Animation — setze sie gezielt ein.
            Format: {"type": "fade-up", "duration": 0.8, "delay": 0, "stagger": 0.15}
            Alle Felder ausser "type" sind optional.
            BLOCK;
    }

    /**
     * @param array<string, string> $briefingAnswers
     */
    private function buildContentPrompt(Template $template, array $briefingAnswers, string $outputLanguage = '', int $parentPageId = 0): string
    {
        $briefing = $this->formatBriefing($briefingAnswers);
        $cTypes = $template->allowedCTypes !== []
            ? implode(', ', $template->allowedCTypes)
            : 'text, textmedia, textpic';
        $cTypeInstruction = $template->allowedCTypes !== []
            ? "Verwende ausschliesslich folgende Content-Typen: {$cTypes}"
            : "Verwende gaengige Content-Typen wie: {$cTypes}";

        $columnMap = $this->backendLayoutService->getColumnMap($template->backendLayout, $parentPageId);
        $columnMap = $this->filterColumnMap($columnMap, $template->contentColumns);

        $cTypeMetadata = $this->buildCTypeMetadataBlock($template->allowedCTypes);
        $columnBlock = $this->buildColumnBlock($columnMap);
        $languageBlock = $this->buildLanguageBlock($outputLanguage);
        $colorBlock = $this->buildColorBlock($template);
        $animationBlock = $this->buildAnimationBlock($template);
        $jsonExample = $this->buildJsonExample($columnMap, $cTypes, $template->isAnimationEnabled());

        return <<<PROMPT
            {$template->systemPrompt}

            Briefing:
            {$briefing}
            {$cTypeMetadata}
            {$columnBlock}
            {$colorBlock}
            --- ANWEISUNGEN ZUR AUSGABE ---
            {$languageBlock}
            Erstelle Inhalte fuer eine Landing Page basierend auf dem obigen Kontext.
            {$cTypeInstruction}

            Strukturiere die Seite als konversionsstarke Landing Page:
            - Beginne mit einer aufmerksamkeitsstarken Hero-Section (klare Headline, Subheadline, Nutzenversprechen)
            - Folge mit konkreten Vorteilen oder Features
            - Fuelle die Seite mit Social Proof, Testimonials oder Vertrauenssignalen
            - Schliesse mit einem klaren Call-to-Action

            INHALTLICHE TIEFE:
            Jede Section soll substanziellen Inhalt haben — nicht nur eine Ueberschrift mit ein bis zwei Saetzen.
            Die gewuenschte Textlaenge pro Section ergibt sich aus dem System-Prompt des Templates.
            Wenn dort keine Angabe steht: mindestens 100-200 Woerter pro textlastiger Section
            (text, textmedia, textpic). Header-Elemente (ctype=header) duerfen kurz sein.
            Die Laengenangabe bezieht sich auf JEDE EINZELNE Section, nicht auf die Gesamtseite.

            Schreibe ueberzeugend, konkret und nutzenorientiert. Vermeide generische Floskeln.
            Jede Section soll einen klaren Zweck im Conversion-Funnel haben.

            Befuelle fuer jeden Content-Typ die korrekten Felder gemaess der obigen Feldbeschreibungen.
            Verwende nur Felder, die fuer den jeweiligen CType definiert sind.

            WICHTIG: Erzeuge KEINE <img>-Tags oder Bild-URLs im bodytext.
            Bilder werden separat aus der CMS-Mediathek zugeordnet.

            HTML-GESTALTUNG im bodytext:
            Der bodytext wird im TYPO3 Rich Text Editor angezeigt. Nutze die verfuegbaren
            HTML-Elemente gezielt fuer Struktur und Lesbarkeit:
            - <p> fuer Fliesstext — NICHT alles in einen einzigen Absatz
            - <h3>, <h4> fuer Zwischenueberschriften innerhalb einer Section (h2 = Section-Header)
            - <ul>/<ol> fuer Aufzaehlungen und Schrittfolgen
            - <strong> fuer wichtige Begriffe, <em> fuer Betonungen — sparsam einsetzen
            - <blockquote> fuer Zitate, Testimonials oder hervorgehobene Aussagen
            - <a href="#"> fuer interne Verweise (href bleibt Platzhalter)
            - <table> NUR wenn tabellarische Daten sinnvoll sind (Vergleiche, Specs, Preise)
            VERBOTEN: inline style-Attribute, CSS-Klassen, <div>, <span>, <img>, <script>.
            Kein visuelles Styling — nur semantisches HTML. Das Design kommt vom Frontend-Template.

            BILD-LAYOUT pro Section (imageorient):
            Waehle pro Section eine passende Bildposition fuer Abwechslung:
            - 0: Bild oben zentriert (gut fuer Hero, breite Bilder)
            - 17: Bild rechts im Text (Textumfluss)
            - 18: Bild links im Text (Textumfluss)
            - 25: Bild rechts neben Text (50/50 Layout)
            - 26: Bild links neben Text (50/50 Layout)
            Variiere die Position zwischen Sections — nicht jedes Bild oben!

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
            {$animationBlock}
            Antworte ausschliesslich als JSON-Objekt mit dem Schluessel "sections":
            {"sections": {$jsonExample}}
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
     *
     * @param array<int, string> $columnMap
     */
    private function buildColumnBlock(array $columnMap): string
    {
        if (count($columnMap) <= 1) {
            return '';
        }

        $columnCount = count($columnMap);
        $formatted = $this->backendLayoutService->formatColumnMapForPrompt($columnMap);

        return <<<BLOCK

            --- VERFUEGBARE SPALTEN (Backend Layout) ---
            WICHTIG: Die Seite hat {$columnCount} Inhaltsbereiche (Spalten).
            Du MUSST Content-Elemente auf ALLE {$columnCount} Spalten verteilen.
            Keine Spalte darf leer bleiben!

            Verfuegbare Spalten:
            {$formatted}

            Regeln fuer die Spalten-Zuweisung:
            1. Setze "colPos" im JSON auf die Nummer der Spalte, in die der Inhalt gehoert.
               NICHT alle Elemente in colPos 0 — verteile sie sinnvoll auf alle Spalten!
            2. Jede Spalte MUSS mindestens ein Content-Element erhalten.
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
            5. Pruefe vor der Ausgabe: Kommt jeder colPos-Wert ({$this->formatColPosValues($columnMap)})
               mindestens einmal im JSON-Array vor? Falls nicht, ergaenze fehlende Spalten!
            BLOCK;
    }

    /**
     * Build a JSON example that reflects the actual column layout.
     *
     * When multiple columns exist, show examples with different colPos values
     * to prevent the LLM from defaulting everything to colPos 0.
     *
     * @param array<int, string> $columnMap
     */
    private function buildJsonExample(array $columnMap, string $cTypes, bool $includeAnimation = false): string
    {
        $animationField = $includeAnimation
            ? ",\n   \"animation\": {\"type\": \"fade-up\", \"duration\": 0.8}"
            : '';

        if (count($columnMap) <= 1) {
            return <<<JSON
            [
              {"section": "string", "ctype": "one of [{$cTypes}]", "colPos": 0,
               "header": "string", "subheader": "string", "bodytext": "HTML string",
               "imageKeywords": ["keyword1", "keyword2", "keyword3"],
               "imagePrompt": "A detailed description of an image suitable for this section",
               "imageorient": 0{$animationField}}
            ]
            JSON;
        }

        $examples = [];
        foreach ($columnMap as $colPos => $name) {
            $examples[] = '  {"section": "Inhalt fuer ' . $name . '", "ctype": "one of [' . $cTypes . ']", "colPos": ' . $colPos . ',' . "\n"
                . '   "header": "string", "subheader": "string", "bodytext": "HTML string",' . "\n"
                . '   "imageKeywords": ["keyword1", "keyword2"], "imagePrompt": "...", "imageorient": 25' . $animationField . '}';
        }

        return "[\n" . implode(",\n", $examples) . "\n]";
    }

    /**
     * Format colPos values as comma-separated list for prompt instructions.
     *
     * @param array<int, string> $columnMap
     */
    private function formatColPosValues(array $columnMap): string
    {
        return implode(', ', array_keys($columnMap));
    }

    /**
     * Filter column map to only include columns selected in the template.
     * Returns the full column map if no content columns are configured.
     *
     * @param array<int, string> $columnMap
     * @param list<int> $contentColumns
     * @return array<int, string>
     */
    private function filterColumnMap(array $columnMap, array $contentColumns): array
    {
        if ($contentColumns === []) {
            return $columnMap;
        }

        $filtered = array_intersect_key($columnMap, array_flip($contentColumns));

        // Fall back to full map if filter would result in empty (misconfiguration)
        return $filtered !== [] ? $filtered : $columnMap;
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
     * @return list<array{section: string, ctype: string, colPos: int, header: string, subheader: string, bodytext: string, imageKeywords: list<string>, imagePrompt: string, animation: array{type?: string, duration?: float, delay?: float, stagger?: float}}>
     */
    private function validateSections(mixed $response, array $allowedCTypes, array $validColPositions = [0]): array
    {
        if (!is_array($response)) {
            return [];
        }

        $validated = [];
        foreach ($this->normalizeToItemList($response, ['section', 'ctype']) as $item) {
            if (!isset($item['section'], $item['ctype'])) {
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

            $rawImageorient = $item['imageorient'] ?? 0;
            $imageorient = is_int($rawImageorient) ? $rawImageorient : (is_numeric($rawImageorient) ? (int) $rawImageorient : 0);
            if (!in_array($imageorient, self::VALID_IMAGEORIENT, true)) {
                $imageorient = 0;
            }

            $validated[] = [
                'section' => $item['section'],
                'ctype' => $ctype,
                'colPos' => $colPos,
                'header' => is_string($item['header'] ?? null) ? $item['header'] : '',
                'subheader' => is_string($item['subheader'] ?? null) ? $item['subheader'] : '',
                'bodytext' => $this->sanitizeHtml($this->stripCssRuleSets($bodytext)),
                'imageKeywords' => $imageKeywords,
                'imagePrompt' => is_string($item['imagePrompt'] ?? null) ? $item['imagePrompt'] : '',
                'imageorient' => $imageorient,
                'animation' => $this->validateAnimation($item['animation'] ?? null),
            ];
        }

        return $validated;
    }

    /**
     * Build the LLM prompt for creative HTML mode.
     *
     * @param array<string, string> $briefingAnswers
     * @param array<int, string> $columnMap
     */
    private function buildCreativePrompt(Template $template, array $briefingAnswers, string $outputLanguage, array $columnMap): string
    {
        $briefing = $this->formatBriefing($briefingAnswers);
        $languageBlock = $this->buildLanguageBlock($outputLanguage);
        $colorBlock = $this->buildColorBlock($template);

        $columnDescriptions = [];
        foreach ($columnMap as $colPos => $name) {
            $columnDescriptions[] = "- colPos {$colPos}: \"{$name}\"";
        }
        $columnsBlock = implode("\n", $columnDescriptions);

        if ($template->isAnimationEnabled()) {
            $scriptRule = <<<RULE
                JAVASCRIPT-ANIMATIONEN (PFLICHT):
                GSAP (gsap), ScrollTrigger und TextPlugin sind global verfuegbar.
                JEDE Section MUSS mindestens eine GSAP-Animation enthalten — Scroll-Reveals,
                Fade-Ins, Slide-Ins, Typewriter-Effekte, Parallax oder Stagger-Animationen.
                Eine Seite ohne Animationen ist unvollstaendig.
                - Jeder <script>-Block MUSS das Attribut data-creative tragen.
                - Wrapping: Alle gsap-Aufrufe in document.addEventListener('DOMContentLoaded', function() { ... });
                - Erlaubte APIs: gsap.*, ScrollTrigger.*, TextPlugin.*,
                  document.querySelector/All, Standard-JS (const, let, =>, forEach).
                - VERBOTEN: fetch, XMLHttpRequest, eval, document.cookie,
                  localStorage, window.location, innerHTML und alle Netzwerk-APIs.
                - Verwende die CSS-Klassen-Praefixe der Section als Selektoren.
                - prefers-reduced-motion wird automatisch vom Loader behandelt,
                  du brauchst KEINE eigene Pruefung einzubauen.
                RULE;
        } else {
            $scriptRule = '3. KEIN JavaScript, KEINE <script>-Tags, KEINE Event-Handler.';
        }

        return <<<PROMPT
            {$template->systemPrompt}

            Briefing:
            {$briefing}
            {$colorBlock}

            --- KREATIV-MODUS: HTML + CSS + INLINE-SVG ---
            {$languageBlock}
            Du bist ein Webdesigner. Erstelle fuer jeden Inhaltsbereich ein eigenstaendiges
            HTML-Fragment mit eingebettetem CSS (<style>) und optionalen Inline-SVGs.

            Verfuegbare Inhaltsbereiche (Spalten):
            {$columnsBlock}

            ROLLE:
            Du bist ein erfahrener Webdesigner der Landingpages auf dem Niveau von
            Stripe, Linear, Vercel oder Apple gestaltet. Jede Section ist ein
            eigenstaendiges HTML-Fragment mit eigenem <style>-Block.

            VERBOTEN (Lazy Patterns — fuehren zu minderwertigen Ergebnissen):
            - Flache einfarbige Hintergruende ohne Gradient, Textur oder Tiefeneffekt
            - Sections die wie gestapelte Rechteck-Boxen wirken (harte Kante, Farbe, harte Kante)
            - Einheitliche Schriftgroessen und -gewichte ueber alle Elemente
            - Alles zentriert, alles gleich breit, alles symmetrisch

            PFLICHT (Qualitaetsmerkmale — jede Section muss diese erfuellen):
            - Visuelle Tiefe: Schatten, Layering, Ueberlappungen oder Transparenz einsetzen
            - Typografie-Hierarchie: Deutliche Unterschiede in Groesse, Gewicht und Spacing
              zwischen Headline, Subline und Fliesstext
            - Grosszuegiger Whitespace — lieber zu viel als zu wenig
            - Weiche Uebergaenge zwischen Sections — keine harten Farbwechsel-Kanten
            - Mindestens ein visueller Eyecatcher pro Section (SVG-Grafik, Formelement,
              Akzentlinie, Icon-Gruppe oder dekoratives Element)
            - Interaktive Hover-States fuer klickbare Elemente (Links, Buttons, Cards)

            OPTIONAL aber erwuenscht:
            - Dekorative SVG-Elemente (Wellen, Blobs, geometrische Formen) als
              Section-Trenner oder Hintergrund-Akzente
            - Subtile CSS-Animationen und Transitions
            - Asymmetrische Layouts wo es zum Inhalt passt

            {$scriptRule}

            FOTO-PLATZHALTER (WICHTIG):
            Wenn eine Section von einem Foto profitiert (Hero, Teaser, Portrait, Produkt),
            setze einen Platzhalter: <img data-image-slot="0" alt="Beschreibung">
            - KEIN src-Attribut — das System fuegt die URL automatisch ein.
            - Das alt-Attribut MUSS eine sinnvolle Beschreibung enthalten.
            - Genau EIN Platzhalter pro Section (nicht mehrere).
            - Liefere fuer JEDE Section mit Platzhalter imageKeywords und imagePrompt.
            - Nicht jede Section braucht ein Foto — dekorative Grafiken als Inline-SVG.
            - ABER: Wenn du ein Foto willst, MUSST du den Platzhalter setzen.
              Ohne Platzhalter wird kein Bild angezeigt.

            INHALTLICHE TIEFE (KRITISCH):
            Design ist wichtig, aber OHNE substanziellen Text ist die Seite wertlos.
            Jede Haupt-Section MUSS echten, ausfuehrlichen Inhalt haben:
            - Mehrere Absaetze (<p>) mit konkreten Informationen, Argumenten, Beispielen
            - NICHT nur eine Ueberschrift mit einem Satz darunter
            - Listen (<ul>/<ol>) ergaenzen den Fliesstext, ersetzen ihn nicht
            - Die gewuenschte Textlaenge ergibt sich aus dem System-Prompt des Templates.
              Wenn dort keine Angabe steht: mindestens 80-150 Woerter Text pro Haupt-Section.
            - Sidebar/Footer-Sections duerfen kompakter sein (30-60 Woerter)
            Der Text muss ZUERST stimmen — das CSS drum herum ist zweitrangig.

            TECHNISCHE REGELN:
            1. Verwende CSS-Klassen mit eindeutigem Praefix pro Section (z.B. .hero-*, .feat-*).
            2. KEIN CSS url() — keine externen Ressourcen.
            3. Barrierefrei und responsive.
            4. Nutze CSS Custom Properties (--primary, --secondary) aus dem Farbschema.

            TOKEN-BUDGET BEACHTEN:
            - Halte CSS kompakt: Shorthand-Properties, keine redundanten Regeln.
            - SVGs klein halten: Einfache, dekorative Grafiken, keine komplexen Pfade.
            - Pro Section max. 150 Zeilen HTML+CSS. Qualitaet vor Quantitaet.
            - Sidebar/Footer-Spalten besonders kompakt (max. 50 Zeilen).

            Antworte ausschliesslich als JSON-Objekt mit dem Schluessel "sections":
            {"sections": [
              {"section": "Hero", "colPos": 0, "header": "Titel",
               "bodytext": "<style>.hero { ... }</style><section class='hero'><img data-image-slot=\"0\" alt=\"Hero image\"><h1>...</h1></section>{$this->buildCreativeBodytextExample($template)}",
               "imageKeywords": ["keyword1", "keyword2"],
               "imagePrompt": "Detailed English image description"}
            ]}

            Das bodytext-Feld enthaelt das komplette HTML inkl. <style>-Block.
            Jede Section mit <img data-image-slot="0"> MUSS imageKeywords (3-5 englische
            Suchbegriffe fuer die Mediathek) und imagePrompt (detaillierter englischer
            Bild-Prompt) liefern. Ohne Platzhalter: leeres Array / leerer String.
            Erstelle fuer JEDEN colPos ({$this->formatColPosValues($columnMap)}) genau ein Element.
            PROMPT;
    }

    /**
     * Validate and sanitize creative mode LLM response.
     *
     * @param array<int, string> $columnMap
     * @return list<array{section: string, ctype: string, colPos: int, header: string, subheader: string, bodytext: string, imageKeywords: list<string>, imagePrompt: string}>
     */
    private function validateCreativeSections(mixed $response, array $columnMap, bool $allowScripts = false): array
    {
        if (!is_array($response)) {
            return [];
        }

        $validColPositions = array_keys($columnMap);
        $validated = [];

        foreach ($this->normalizeToItemList($response, ['section']) as $item) {
            if (!isset($item['section'])) {
                continue;
            }
            if (!is_string($item['section'])) {
                continue;
            }

            $bodytext = is_string($item['bodytext'] ?? null) ? $item['bodytext'] : '';
            $bodytext = $this->sanitizeCreativeHtml($bodytext, $allowScripts);

            $rawColPos = $item['colPos'] ?? 0;
            $colPos = is_int($rawColPos) ? $rawColPos : (is_numeric($rawColPos) ? (int) $rawColPos : 0);
            if ($validColPositions !== [] && !in_array($colPos, $validColPositions, true)) {
                $colPos = $validColPositions[0];
            }

            $imageKeywords = [];
            if (is_array($item['imageKeywords'] ?? null)) {
                foreach ($item['imageKeywords'] as $kw) {
                    if (is_string($kw) && trim($kw) !== '') {
                        $imageKeywords[] = trim($kw);
                    }
                }
            }

            $validated[] = [
                'section' => $item['section'],
                'ctype' => 'html',
                'colPos' => $colPos,
                'header' => is_string($item['header'] ?? null) ? $item['header'] : '',
                'subheader' => '',
                'bodytext' => $bodytext,
                'imageKeywords' => $imageKeywords,
                'imagePrompt' => is_string($item['imagePrompt'] ?? null) ? $item['imagePrompt'] : '',
            ];
        }

        return $validated;
    }

    /**
     * @return array{type?: string, duration?: float, delay?: float, stagger?: float}
     */
    private function validateAnimation(mixed $animation): array
    {
        if (!is_array($animation)) {
            return [];
        }

        $type = is_string($animation['type'] ?? null) ? $animation['type'] : '';
        if ($type === '') {
            return [];
        }

        $result = ['type' => $type];
        if (isset($animation['duration']) && is_numeric($animation['duration'])) {
            $result['duration'] = max(AnimationScriptBuilder::DURATION_MIN, min(AnimationScriptBuilder::DURATION_MAX, (float) $animation['duration']));
        }
        if (isset($animation['delay']) && is_numeric($animation['delay'])) {
            $result['delay'] = max(AnimationScriptBuilder::DELAY_MIN, min(AnimationScriptBuilder::DELAY_MAX, (float) $animation['delay']));
        }
        if (isset($animation['stagger']) && is_numeric($animation['stagger'])) {
            $result['stagger'] = max(AnimationScriptBuilder::STAGGER_MIN, min(AnimationScriptBuilder::STAGGER_MAX, (float) $animation['stagger']));
        }

        return $result;
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
