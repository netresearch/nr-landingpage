# Design: nr-landingpage – Landing Page Generator for TYPO3

## Konzept

Eine TYPO3-Extension die mithilfe von `nr-llm` automatisiert Landing Pages generiert.
Ein Backend-Wizard fuehrt Redakteure Schritt fuer Schritt durch den Prozess – von der
Template-Wahl bis zur fertigen Seite mit Content-Elementen und SEO-Konfiguration.

## Dependencies

### Explizite Abhaengigkeiten (composer.json)

- `netresearch/nr-llm` (>= 0.4.0) – LLM-Abstraktionsschicht
- `typo3/cms-core` (^13.4 || ^14.0)
- `typo3/cms-backend`
- `typo3/cms-workspaces` – suggest (optional, kein require)
- PHP ^8.2

### Implizite Abhaengigkeiten (ueber nr-llm)

- `netresearch/nr-vault` – Secrets Management (nicht direkt referenzieren!)

### Keine versteckten Abhaengigkeiten

- Kein hartes Require auf `b13/container`, `cs_seo`, Bootstrap Package o.ae.
- Referenzseiten-Klonen: Container-Support nur wenn Container-Extension geladen ist
  (Runtime-Check via `ExtensionManagementUtility::isLoaded()`, kein require)
- Page-Feld-Auswahl am Template liest TCA dynamisch → funktioniert mit jeder Extension
  die Felder an `pages` registriert

## Architektur-Ueberblick

```
Kontextmenue (Seitenbaum)
        |
        v
LandingPageWizardController (Backend-Modul, AJAX-Steps)
        |
        v
  +-----------+-----------+------------+------------+
  |           |           |            |            |
  v           v           v            v            v
Template    LLM via    Page/Content   Page-Feld  FAL Image
Service     nr-llm     Generator     Service     Search
  |                       |
  v                       v
TCA Records         DataHandler API
(Templates)       (pages + tt_content)
```

## Template-System

### TCA-Record: `tx_nrlandingpage_domain_model_template`

| Feld | Typ | Beschreibung |
|------|-----|-------------|
| title | string | Name des Templates ("Event-LP", "Produkt-LP") |
| identifier | slug | Eindeutiger Identifier |
| description | text | Beschreibung fuer Redakteure |
| llm_configuration | int (FK) | Referenz auf nr-llm Configuration Record |
| system_prompt | text | Ergaenzende Anweisungen fuer das LLM (Tonalitaet, Stil, Zielgruppe) |
| allowed_ctypes | text (JSON) | Multiselect: erlaubte Content-Typen aus TCA |
| page_fields | text (JSON) | Multiselect: welche pages-Felder das LLM befuellen soll (SEO-Felder vorselektiert) |
| reference_pages | group (pages) | Optionale Referenzseiten als Styling-Baukasten |
| briefing_mode | select | "none", "optional" (default), "required" |
| publish_mode | select | "hidden" (default), "visible" |
| be_groups | select (MM) | Zugriffsberechtigung: welche BE-Gruppen das Template sehen/nutzen |

### Page-Felder-Auswahl (SEO und mehr)

- Am Template eine Multiselect-Liste aller verfuegbaren Felder der `pages`-Tabelle aus dem TCA
- Anzeige: Feld-Label + Extension-Name (z.B. "Meta Title [seo]", "Open Graph Title [seo]", "Canonical [cs_seo]")
- SEO-Core-Felder vorselektiert: seo_title, description, og_title, og_description
- Template-Ersteller kann weitere Felder aktivieren (nav_title, abstract, cs_seo-Felder, etc.)
- Keine Abhaengigkeit zu spezifischen Extensions – alles was Felder an pages registriert taucht auf
- Im Wizard-SEO-Schritt: nur die am Template ausgewaehlten Felder werden angezeigt und vom LLM befuellt

### CType-Auswahl

- Am Template eine Multiselect-Liste aller verfuegbaren CTypes
- Template-Ersteller waehlt welche CTypes das LLM nutzen darf
- LLM entscheidet welches der erlaubten Elemente pro Sektion am besten passt

### Prompt-Architektur (Schnittstelle zu nr-llm)

Die Prompt-Konfiguration ist dreistufig:

1. **nr-llm Configuration** = Basis-Persona ("Du bist ein Landing-Page-Experte") + Model/Provider
2. **Template `system_prompt`** = Ergaenzende Anweisungen (Sektionen, Stil, Zielgruppe)
3. **User-Briefing** = Thema, Keywords, Antworten auf LLM-generierte Fragen

### LLM-Output-Format

Die Extension steuert das JSON-Format automatisch – der Admin muss nichts ueber das Format wissen.
Bei aktivem Briefing haengt die Extension an den Prompt:

```
{Admin System-Prompt}

Basierend auf dem obigen Kontext: Stelle dem User die relevanten
Fragen um eine Landing Page zu erstellen.

Antworte ausschliesslich als JSON-Array:
[
  {"id": "string", "label": "string", "type": "text|textarea|select",
   "required": true|false, "placeholder": "string",
   "options": ["nur bei type=select"]}
]
```

Gleiches Prinzip fuer Content-Generierung und Page-Feld-Generierung.
Technisch: `completeJson()` von nr-llm + robuste Validierung.
Fallback bei Parse-Fehler: Retry mit schaerferem Prompt.

### Optionale Referenzseiten (Styling-Baukasten)

- Template kann eine oder mehrere TYPO3-Seiten als Referenz enthalten
- Referenz dient **nur zur Erstellung** – keine dauerhafte Verlinkung
- Seiten enthalten **einzelne Sektions-Bausteine** mit fertigem Styling
  (Container, Frames, Spacing, Hintergruende)
- Im Wizard: Redakteur sieht verfuegbare Bausteine zur Auswahl
- Bei Generierung: gewaehlte Bausteine werden geklont, LLM fuellt Texte
- **Generierte LPs koennen selbst als Referenz fuer neue LPs dienen**
  (iterativer Prozess: generieren → anpassen → als Referenz wiederverwenden)
- **Ohne Referenzseite**: LLM generiert Content in Standard-CTypes ohne Styling
- **Mit Referenzseite**: Reproduzierbare, konsistent gestylte Ergebnisse

#### Container-Klonen

- Erster Ansatz: TYPO3 DataHandler `copy`-Command testen – loest Relationen
  ggf. automatisch auf (inkl. b13/container Kinder)
- Fallback: Rekursives Klonen fuer b13/container selbst implementieren
  (Kinder per `tx_container_parent` finden, UIDs remappen)
- Kein hartes Require auf Container-Extensions – nur aktiv wenn geladen

### Berechtigungen

- Templates als DB-Records in Sysfolder → TYPO3 Page-Permissions greifen automatisch
- `be_groups`-Feld am Template → nur zugeordnete Gruppen sehen das Template im Wizard
- Query filtert nach Rechten des aktuellen BE-Users
- Kontextmenue-Item: nur sichtbar wenn User mindestens ein Template nutzen darf

## Wizard-Flow

### Einstiegspunkte

1. **Kontextmenue im Seitenbaum**: Rechtsklick auf Seite → "Landing Page erstellen"
   → Wizard oeffnet mit vorausgewaehlter Elternseite
2. **Backend-Modul**: Direkter Zugang zum Wizard
   (Elternseite wird im letzten Schritt gewaehlt)

### Wizard-State

- **State liegt im Frontend** (JavaScript) – Backend bleibt stateless
- Jeder Schritt speichert seine Daten client-seitig
- Redakteur kann jederzeit zurueck navigieren ohne Datenverlust
- Bei Browser-Refresh: State geht verloren (akzeptabel fuer v1)

### Schritte (AJAX-basiert, analog SetupWizardController in nr-llm)

#### Schritt 1: Template waehlen

- Liste der verfuegbaren Templates (gefiltert nach BE-User-Berechtigung)
- Vorschau der Beschreibung und Referenzseiten-Bausteine
- Template-Auswahl setzt den Rahmen fuer alle folgenden Schritte
- Zwei Optionen (je nach `briefing_mode`):
  - **"Briefing starten"** → weiter zu Schritt 2
  - **"Direkt erstellen"** → springt zu Schritt 3 (nur Thema/Keywords als Minimalangabe)
- Bei `briefing_mode: required` ist nur "Briefing starten" verfuegbar
- Bei `briefing_mode: none` wird Schritt 2 uebersprungen

#### Schritt 2: Briefing (optional/required je Template)

- LLM generiert dynamisch template-spezifische Fragen
- Fragen werden als **Formularfelder** dargestellt (alle auf einmal, nicht conversational)
- **Thema/Titel** ist immer Pflichtfeld (statisch, nicht LLM-generiert)
- Weitere Fragen vom LLM je nach Template-Kontext
- Redakteur fuellt aus → Antworten fliessen in die Content-Generierung

**Fehlertoleranz:**
- LLM-Fehler: Retry / Skip (bei Skip nur Thema+Keywords)
- Ungültiges JSON: Retry mit schaerferem Prompt / Skip
- Max-Anzahl Fragen im Prompt begrenzt (z.B. "max 8 Fragen")

#### Schritt 3: Page-Felder / SEO-Konfiguration

- LLM generiert Vorschlaege fuer alle am Template konfigurierten Page-Felder
- Typische Felder (vorselektiert): seo_title, description, og_title, og_description
- Zeichenzaehler fuer Meta-Title (< 60) und Meta-Description (< 160)
- **Slug** wird aus Titel generiert (TYPO3 SlugHelper)
- Redakteur kann alle Werte anpassen

**Fehlertoleranz:**
- LLM-Fehler: Retry / Skip (Felder bleiben leer, manuell fuellbar)
- Slug-Konflikt: SlugHelper haengt automatisch Suffix an

#### Schritt 4: Content-Vorschau

- LLM generiert Inhalte fuer Sektionen basierend auf erlaubten CTypes
- Falls Referenzseiten vorhanden: Redakteur waehlt Bausteine aus dem Baukasten
- Vorschau zeigt: Sektionsname + generierter Inhalt + Content-Typ
- Redakteur kann:
  - Einzelne Sektionen neu generieren lassen
  - Texte direkt bearbeiten
  - Optionale Sektionen ein-/ausschalten

**Fehlertoleranz:**
- LLM-Fehler: Retry / Skip (leere Sektionen, manuell fuellbar)
- JSON-Parse-Fehler: Retry / Skip
- Fehlende Sektionen: einzeln nochmal anfordern
- Content zu lang: maxTokens im Prompt begrenzt
- HTML/Markdown-Mix: Normalisierung durch HTML-Sanitizer
- Referenzseiten-Klonen fehlgeschlagen: Fallback auf Standard-CType

#### Schritt 5: Platzierung & Generierung

- **Elternseite waehlen** (vorbelegt wenn via Kontextmenue gestartet)
  - v1: einfaches Number-Input fuer Page-UID
  - **v1.1: Dropdown mit Autocomplete** — alle fuer den User sichtbaren Seiten als
    durchsuchbare Liste (Suche nach Seitentitel). Neuer AJAX-Endpoint `searchPagesAction`
    mit `LIKE`-Query auf `pages.title`, gefiltert nach Schreibrecht (`getPagePermsClause(2)`).
    Debounced Input (300ms) → AJAX → Dropdown. Keine FormEngine-Abhaengigkeit noetig.
- Berechtigungspruefung: User hat Schreibrecht auf Elternseite?
- Workspace-Check: wenn User in Workspace → dort erstellen
- Zusammenfassung aller Einstellungen
- "Generieren"-Button erstellt:
  - `pages`-Record (hidden/visible je nach Template, Workspace wenn aktiv)
  - `tt_content`-Records (geklont von Referenz oder Standard-CTypes)
  - Page-Felder (SEO etc.) am Page-Record
  - Slug-Generierung
  - FAL-Referenzen fuer passende Bilder

**Fehlertoleranz:**
- Kein Schreibrecht: Fehlermeldung, Elternseite aendern
- DataHandler-Fehler bei Page: Fehlermeldung, nichts aufzuraeumen
- DataHandler-Fehler bei Content: Seite existiert (hidden), Fehlermeldung
  + Link zur unvollstaendigen Seite. Kein automatisches Cleanup in v1 –
  Redakteur entscheidet: weiterpflegen oder loeschen
- Workspace nicht beschreibbar: Fehlermeldung

### Uebergreifende Fehlertoleranz

| Thema | Massnahme |
|-------|-----------|
| Jeder Wizard-Schritt | Retry + Skip + Zurueck moeglich |
| Browser-Refresh | State verloren, akzeptabel v1 |
| Parallele Sessions | Kein Problem (stateless Backend) |
| nr-llm nicht konfiguriert | Backend-Modul zeigt Hinweis |
| LLM-Config am Template ungueltig | Fehler bei Template-Auswahl |

## Technische Komponenten

### Controller

**`LandingPageWizardController`** (extends `ActionController`)
- Backend-Modul registriert via `Configuration/Backend/Modules.php` (TYPO3 v13+ Standard)
- AJAX-Routen via `Configuration/Backend/AjaxRoutes.php`
- CSRF-Protection via `@\TYPO3\CMS\Backend\Attribute\AsController` + nonce
- `indexAction()` – Wizard-UI rendern
- `templatesAction()` – Verfuegbare Templates laden (AJAX)
- `generateBriefingAction()` – LLM-generierte Fragen fuer Template (AJAX)
- `generatePageFieldsAction()` – Page-Feld-Vorschlaege via LLM (AJAX)
- `generateContentAction()` – Content fuer Sektionen via LLM (AJAX)
- `regenerateSectionAction()` – Einzelne Sektion neu generieren (AJAX)
- `saveAction()` – Seite + Content anlegen (AJAX)

### Services (alle via Constructor DI, registriert in Services.yaml)

**`TemplateService`**
- Templates laden mit Berechtigungspruefung
- Referenzseiten-Bausteine auslesen und aufbereiten
- Verfuegbare CTypes und Page-Felder aus TCA lesen

**`BriefingService`**
- Template-Definition an LLM senden (via nr-llm `completeJson()`)
- JSON-Format-Anweisung automatisch an Prompt anhaengen
- Briefing-Fragen aus Response parsen und validieren

**`ContentGeneratorService`**
- Briefing + Template-Prompt → LLM-Request zusammenbauen
- JSON-Format-Anweisung fuer Content automatisch anhaengen
- LLM-Response → strukturierte Content-Bloecke parsen und validieren
- Bei Referenzseiten: Content-Element klonen und Texte ersetzen
- HTML-Sanitizer fuer generierten Content

**`PageCreatorService`**
- TYPO3 DataHandler nutzen fuer pages + tt_content Erstellung
- Workspace-Handling: bestehenden Workspace des Users respektieren
- Slug-Generierung via SlugHelper
- Page-Felder setzen (SEO etc.)
- Erstellte UIDs tracken fuer Fehlermeldung

**`ImageSearchService`**
- Keyword-Suche auf `sys_file_metadata` (title, description, alternative, Dateiname)
- LIKE-Query, kein Embedding in v1
- Fallback: kein Bild wenn nichts passt

### Kontextmenue-Integration

Registrierung via `Configuration/Backend/ContextMenu/ItemProviders.php` (TYPO3 v13+):

```php
return [
    \Netresearch\NrLandingpage\ContextMenu\LandingPageItemProvider::class,
];
```

ItemProvider prueft: hat der User mindestens ein Template verfuegbar?

### Frontend (JavaScript)

- ES6-Module (TYPO3 v13+ Standard, kein RequireJS)
- Import via `@typo3/nr-landingpage/` Namespace
- Lit/Web Components oder vanilla JS fuer Wizard-Steps
- TYPO3 Modal API fuer Dialoge
- TYPO3 Notification API fuer Fehler/Erfolg
- State-Management client-seitig (kein Backend-Session)

## Workspace-Support

- Wenn der BE-User in einem Workspace arbeitet, werden Seite und Content dort erstellt
- Kein automatisches Erstellen von Workspaces
- Standard ohne Workspace: Seite mit `hidden=1`, Content-Elemente sichtbar
  → Backend-Preview funktioniert
- `typo3/cms-workspaces` als `suggest` in composer.json, nicht als `require`

## Testing-Strategie

Orientiert am Test-Stack von nr-llm. Ziel: hohe Testabdeckung (>70%), PHPStan Level 10.

### Infrastruktur

- `typo3/testing-framework` fuer Functional Tests
- `Build/Scripts/runTests.sh` (Docker-basiert, analog nr-llm)
- PHPUnit 10+ mit Data Providers und Named Datasets
- LLM-Mock-Adapter: deterministische Responses fuer alle Tests
  (nr-llm Interface mocken, keine echten API-Calls)
- CSV-Fixtures fuer Templates, Seiten, Content-Elemente, BE-User/Gruppen

### Test-Schichten

**Unit Tests** (`Tests/Unit/`)

| Service | Test-Fokus |
|---------|------------|
| TemplateService | Berechtigungsfilterung, TCA-Parsing, CType-/Page-Feld-Auswahl |
| BriefingService | JSON-Format-Anweisung korrekt angehaengt, Response-Parsing, Validierung, Fehler-Handling |
| ContentGeneratorService | Prompt-Zusammenbau, Response-Parsing, CType-Mapping, HTML-Sanitizing |
| PageCreatorService | DataHandler-Map korrekt aufgebaut, Slug-Logik, Workspace-Erkennung |
| ImageSearchService | Query-Building, Keyword-Extraktion, Fallback bei leerem Ergebnis |
| LandingPageItemProvider | Kontextmenue-Sichtbarkeit basierend auf Berechtigungen |

Edge Cases: leere Briefings, fehlende Referenzseiten, ungueltige Templates,
LLM liefert kein JSON, LLM liefert unerwartete Felder, leere CType-Auswahl.

**Functional Tests** (`Tests/Functional/`)

- TYPO3 Testing Framework mit vollstaendigem Bootstrap
- DataHandler-Integration: pages + tt_content korrekt angelegt?
- Template-Records: CRUD, Berechtigungsfilterung mit echten BE-Usern/Gruppen
- Workspace-Integration: Records korrekt im Workspace erstellt?
- Slug-Generierung: Eindeutigkeit, Konflikt-Handling
- FAL-Suche: Keyword-Match auf sys_file_metadata Fixtures
- Referenzseiten-Kloning: Elemente korrekt dupliziert inkl. Felder?
- Container-Kloning (wenn b13/container in Test-Setup): Parent-UIDs korrekt remapped?
- Page-Felder: alle konfigurierten Felder korrekt am Page-Record gesetzt?

**Architecture Tests** (`Tests/Architecture/`) – phpat

- Service-Layer darf nicht direkt auf Controller zugreifen
- Kein direkter Zugriff auf nr-vault (nur ueber nr-llm)
- Kein `GeneralUtility::makeInstance()` fuer eigene Services
- Kein `$GLOBALS['TYPO3_DB']` oder Legacy-DB-Zugriff
- Namespace-Konventionen: `Netresearch\NrLandingpage\`

**E2E Tests** (`Tests/E2E/`) – Playwright

- Wizard-Flow komplett: Template → Briefing → Page-Felder → Preview → Generierung
- Kontextmenue-Einstiegspunkt
- Berechtigungen: User sieht nur erlaubte Templates
- Briefing-Modi: none/optional/required korrekt im UI
- Direkter Schnellmodus ohne Briefing
- Skip-Verhalten bei LLM-Fehler (Mock-Provider der Fehler wirft)
- Zurueck-Navigation ohne Datenverlust

**Integration Tests** (`Tests/Integration/`) – optional, CI-separat

- Echte LLM-Calls gegen Test-Provider
- Struktur-Validierung: generierter Output hat erwartete JSON-Struktur
- Nicht auf exakten Text pruefen (nicht deterministisch)

### Quality Tools

- PHPStan Level 10 (max)
- PHP-CS-Fixer (PSR-12 + TYPO3 CGL)
- Rector (TYPO3 Rector Rules)
- ESLint fuer JavaScript/ES6
- CI: Unit + Functional + Architecture bei jedem Push
- CI: E2E + Integration nightly / vor Release

## TYPO3-Conformance

### Architektur-Standards

- [x] `declare(strict_types=1)` in allen PHP-Dateien
- [x] Constructor Dependency Injection fuer alle Services
- [x] Services.yaml fuer DI-Konfiguration (autowire + autoconfigure)
- [x] PSR-14 Events fuer Erweiterbarkeit (z.B. `BeforePageCreationEvent`, `AfterContentGenerationEvent`)
- [x] Backend-Modul via `Configuration/Backend/Modules.php` (nicht ext_tables.php)
- [x] AJAX-Routen via `Configuration/Backend/AjaxRoutes.php`
- [x] Kontextmenue via ItemProvider-Registrierung
- [x] TCA in `Configuration/TCA/` und `Configuration/TCA/Overrides/`
- [x] Kein `$GLOBALS` Zugriff in eigenen Klassen (ausser in TCA/ext_localconf.php)
- [x] Kein `GeneralUtility::makeInstance()` fuer eigene Services
- [x] DataHandler fuer alle DB-Schreiboperationen (kein direktes SQL INSERT)

### Frontend-Standards (v13+)

- [x] ES6-Module (kein RequireJS, kein jQuery)
- [x] TYPO3 Modal API
- [x] TYPO3 Notification API
- [x] CSRF-Protection (nonce-basiert)
- [x] Accessibility: ARIA-Labels, Keyboard-Navigation im Wizard

### Dateistruktur

```
nr-landingpage/
├── Classes/
│   ├── Controller/
│   │   └── Backend/
│   │       └── LandingPageWizardController.php
│   ├── ContextMenu/
│   │   └── LandingPageItemProvider.php
│   ├── Domain/
│   │   └── Model/
│   │       └── Template.php
│   ├── Event/
│   │   ├── BeforePageCreationEvent.php
│   │   └── AfterContentGenerationEvent.php
│   └── Service/
│       ├── BriefingService.php
│       ├── ContentGeneratorService.php
│       ├── ImageSearchService.php
│       ├── PageCreatorService.php
│       └── TemplateService.php
├── Configuration/
│   ├── Backend/
│   │   ├── AjaxRoutes.php
│   │   └── Modules.php
│   ├── Services.yaml
│   ├── TCA/
│   │   └── tx_nrlandingpage_domain_model_template.php
│   └── TCA/Overrides/
├── Resources/
│   ├── Private/
│   │   ├── Language/
│   │   └── Templates/
│   │       └── Backend/
│   └── Public/
│       └── JavaScript/
│           └── wizard.js
├── Tests/
│   ├── Architecture/
│   ├── E2E/
│   ├── Functional/
│   ├── Integration/
│   └── Unit/
├── Build/
│   └── Scripts/
│       └── runTests.sh
├── Documentation/
├── composer.json
├── ext_emconf.php
└── ext_localconf.php
```

## Abgrenzung / Out of Scope (v1)

- Kein Refinement/Regenerieren bestehender Seiten
- Keine Bild-Generierung (nur FAL-Keyword-Suche)
- Kein Multi-Language-Support bei Generierung (Default-Sprache)
- Keine A/B-Testing-Integration
- Kein automatisches Cleanup bei fehlgeschlagener Erstellung
- Kein Browser-State-Persistence (LocalStorage)
- Keine Embedding-basierte Bildsuche

## Erweiterbarkeit (spaetere Versionen)

- **Refinement**: bestehende Seiten durch LLM ueberarbeiten lassen
- **Bild-Generierung**: via Image-Generation-Provider (DALL-E etc.)
- **Multi-Language**: Seite in mehreren Sprachen generieren via TranslationService
- **Embedding-Bildsuche**: FAL-Suche via Embeddings statt Keywords
- **LocalStorage**: Wizard-State persistent im Browser
- **Import/Export**: von Templates (YAML/JSON)
