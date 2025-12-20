# Plan d'Évolution PPTX - Compatibilité ECMA-376/ISO 29500

> **Basé sur**: ECMA-376 5th Edition (2021), ISO/IEC 29500-1:2016  
> **Analyse réalisée le**: 2025-12-20

---

## 📊 État Actuel du Projet

### ✅ Fonctionnalités Supportées

| Composant | Content Type | Classe | Statut |
|-----------|-------------|--------|--------|
| Presentation | `presentationml.presentation.main+xml` | `Presentation.php` | ✅ Complet |
| Slide | `presentationml.slide+xml` | `Slide.php` | ✅ Complet |
| SlideLayout | `presentationml.slideLayout+xml` | `SlideLayout.php` | ✅ Basique |
| SlideMaster | `presentationml.slideMaster+xml` | `SlideMaster.php` | ✅ Basique |
| Theme | `presentationml.theme+xml` | `Theme.php` | ✅ Basique |
| NoteMaster | `presentationml.notesMaster+xml` | `NoteMaster.php` | ✅ Basique |
| NoteSlide | `presentationml.notesSlide+xml` | `NoteSlide.php` | ⚠️ Ignoré |
| HandoutMaster | `presentationml.handoutMaster+xml` | `HandoutMaster.php` | ✅ Basique |
| Images | `image/png`, `image/jpeg` | `Image.php` | ✅ Complet |
| External Links | N/A | `External.php` | ✅ Complet |

### ❌ Fonctionnalités Manquantes

| Composant | Content Type | Priorité |
|-----------|-------------|----------|
| Comments | `presentationml.comments+xml` | 🔴 Haute |
| CommentAuthors | `presentationml.commentAuthors+xml` | 🔴 Haute |
| Tags | `presentationml.tags+xml` | 🟡 Moyenne |
| Embedded Objects (OLE) | `oleObject` | 🟡 Moyenne |
| Audio | `audio/*` | 🟡 Moyenne |
| Video | `video/*` | 🟡 Moyenne |
| Charts | `drawingml.chart+xml` | 🟡 Moyenne |
| Diagrams (SmartArt) | `drawingml.diagram*+xml` | 🟢 Basse |
| Custom XML | `customXml` | 🟢 Basse |
| VBA Macros | `vbaProject.bin` | 🟢 Basse |

---

## 🎯 Évolutions Prioritaires

### Phase 1: Support Complet des Notes (Priorité: Haute)

Actuellement, les `NoteSlide` sont ignorés (voir `Slide.php:185-191`). Cela peut causer des pertes de données.

#### Fichiers à créer/modifier

**1. Améliorer `NoteSlide.php`**

```php
<?php

declare(strict_types=1);

namespace Cristal\Presentation\Resource;

/**
 * NoteSlide resource class for handling speaker notes.
 * 
 * @see ECMA-376 Part 1, Section 13.3.4 - Notes Slide Part
 */
class NoteSlide extends XmlResource
{
    /**
     * Get the speaker notes text content.
     *
     * @return string Plain text content of notes
     */
    public function getTextContent(): string
    {
        $text = [];
        $paragraphs = $this->content->xpath('//p:txBody//a:t');
        
        foreach ($paragraphs as $paragraph) {
            $text[] = (string) $paragraph;
        }
        
        return implode("\n", $text);
    }

    /**
     * Set the speaker notes text content.
     *
     * @param string $text Plain text to set
     */
    public function setTextContent(string $text): void
    {
        // Find the first text placeholder and update it
        $textNodes = $this->content->xpath('//p:txBody//a:t');
        if (!empty($textNodes)) {
            $textNodes[0][0] = $text;
            $this->save();
        }
    }

    /**
     * Check if notes have any content.
     */
    public function hasContent(): bool
    {
        return !empty(trim($this->getTextContent()));
    }
}
```

**2. Modifier `Slide.php` pour supporter les notes**

```php
// Supprimer le filtrage des NoteSlide dans mapResources()
// Ajouter une méthode pour accéder aux notes

/**
 * Get the speaker notes for this slide.
 *
 * @return NoteSlide|null
 */
public function getNotes(): ?NoteSlide
{
    foreach ($this->getResources() as $resource) {
        if ($resource instanceof NoteSlide) {
            return $resource;
        }
    }
    return null;
}

/**
 * Check if this slide has speaker notes.
 */
public function hasNotes(): bool
{
    $notes = $this->getNotes();
    return $notes !== null && $notes->hasContent();
}
```

---

### Phase 2: Support des Commentaires (Priorité: Haute)

Les commentaires sont essentiels pour la collaboration et le review de présentations.

#### Fichiers à créer

**1. `Presentation/Resource/Comment.php`**

```php
<?php

declare(strict_types=1);

namespace Cristal\Presentation\Resource;

/**
 * Comment resource class for handling presentation comments.
 * 
 * @see ECMA-376 Part 1, Section 13.3.2 - Comments Part
 */
class Comment extends XmlResource
{
    /**
     * Get all comments.
     *
     * @return array<int, array{author: string, text: string, date: string, slideIdx: int}>
     */
    public function getComments(): array
    {
        $comments = [];
        
        foreach ($this->content->xpath('//p:cm') as $cm) {
            $comments[] = [
                'authorId' => (int) $cm['authorId'],
                'text' => (string) $cm->xpath('p:text')[0] ?? '',
                'date' => (string) $cm['dt'] ?? '',
                'position' => [
                    'x' => (int) $cm->xpath('p:pos/@x')[0] ?? 0,
                    'y' => (int) $cm->xpath('p:pos/@y')[0] ?? 0,
                ],
            ];
        }
        
        return $comments;
    }

    /**
     * Add a new comment.
     *
     * @param int $authorId Author ID
     * @param string $text Comment text
     * @param int $x X position
     * @param int $y Y position
     */
    public function addComment(int $authorId, string $text, int $x = 0, int $y = 0): void
    {
        $ns = $this->getNamespaces();
        $cmLst = $this->content->xpath('//p:cmLst')[0] ?? $this->content->addChild('cmLst', '', $ns['p']);
        
        $cm = $cmLst->addChild('cm', '', $ns['p']);
        $cm->addAttribute('authorId', (string) $authorId);
        $cm->addAttribute('dt', date('c'));
        $cm->addAttribute('idx', (string) (count($this->getComments()) + 1));
        
        $pos = $cm->addChild('pos', '', $ns['p']);
        $pos->addAttribute('x', (string) $x);
        $pos->addAttribute('y', (string) $y);
        
        $cm->addChild('text', $text, $ns['p']);
        
        $this->save();
    }
}
```

**2. `Presentation/Resource/CommentAuthor.php`**

```php
<?php

declare(strict_types=1);

namespace Cristal\Presentation\Resource;

/**
 * CommentAuthor resource class for handling comment authors.
 * 
 * @see ECMA-376 Part 1, Section 13.3.1 - Comment Authors Part
 */
class CommentAuthor extends XmlResource
{
    /**
     * Get all authors.
     *
     * @return array<int, array{id: int, name: string, initials: string}>
     */
    public function getAuthors(): array
    {
        $authors = [];
        
        foreach ($this->content->xpath('//p:cmAuthor') as $author) {
            $authors[] = [
                'id' => (int) $author['id'],
                'name' => (string) $author['name'],
                'initials' => (string) $author['initials'],
                'lastIdx' => (int) $author['lastIdx'],
            ];
        }
        
        return $authors;
    }

    /**
     * Add or get an author.
     *
     * @param string $name Author name
     * @param string $initials Author initials
     * @return int Author ID
     */
    public function addAuthor(string $name, string $initials = ''): int
    {
        // Check if author exists
        foreach ($this->getAuthors() as $author) {
            if ($author['name'] === $name) {
                return $author['id'];
            }
        }
        
        // Create new author
        $ns = $this->getNamespaces();
        $cmAuthorLst = $this->content->xpath('//p:cmAuthorLst')[0];
        
        $newId = count($this->getAuthors());
        $author = $cmAuthorLst->addChild('cmAuthor', '', $ns['p']);
        $author->addAttribute('id', (string) $newId);
        $author->addAttribute('name', $name);
        $author->addAttribute('initials', $initials ?: substr($name, 0, 2));
        $author->addAttribute('lastIdx', '1');
        $author->addAttribute('clrIdx', (string) $newId);
        
        $this->save();
        
        return $newId;
    }
}
```

**3. Mettre à jour `ContentType.php`**

```php
// Ajouter dans CLASSES:
'application/vnd.openxmlformats-officedocument.presentationml.comments+xml' => Comment::class,
'application/vnd.openxmlformats-officedocument.presentationml.commentAuthors+xml' => CommentAuthor::class,
```

---

### Phase 3: Support Multimédia (Priorité: Moyenne)

#### 3.1 Support Audio

**`Presentation/Resource/Audio.php`**

```php
<?php

declare(strict_types=1);

namespace Cristal\Presentation\Resource;

/**
 * Audio resource class for handling audio files in PPTX.
 * 
 * @see ECMA-376 Part 1, Section 20.1.3.1 - audioFile
 */
class Audio extends GenericResource
{
    /**
     * Supported audio formats.
     */
    public const SUPPORTED_FORMATS = [
        'audio/mpeg' => 'mp3',
        'audio/wav' => 'wav',
        'audio/x-wav' => 'wav',
        'audio/mp4' => 'm4a',
        'audio/x-m4a' => 'm4a',
    ];

    /**
     * Get the audio duration in milliseconds (if available in metadata).
     */
    public function getDuration(): ?int
    {
        // Would require parsing audio metadata
        return null;
    }

    /**
     * Check if the audio format is supported.
     */
    public function isSupported(): bool
    {
        return isset(self::SUPPORTED_FORMATS[$this->contentType]);
    }
}
```

#### 3.2 Support Vidéo

**`Presentation/Resource/Video.php`**

```php
<?php

declare(strict_types=1);

namespace Cristal\Presentation\Resource;

/**
 * Video resource class for handling video files in PPTX.
 * 
 * @see ECMA-376 Part 1, Section 20.1.3.6 - videoFile
 */
class Video extends GenericResource
{
    /**
     * Supported video formats.
     */
    public const SUPPORTED_FORMATS = [
        'video/mp4' => 'mp4',
        'video/x-ms-wmv' => 'wmv',
        'video/avi' => 'avi',
        'video/x-msvideo' => 'avi',
        'video/quicktime' => 'mov',
    ];

    /**
     * Get video thumbnail if embedded.
     */
    public function getThumbnail(): ?Image
    {
        // Look for associated thumbnail in relationships
        return null;
    }
}
```

**Mettre à jour `ContentType.php`**

```php
// Ajouter dans CLASSES:
'audio/mpeg' => Audio::class,
'audio/wav' => Audio::class,
'audio/x-wav' => Audio::class,
'audio/mp4' => Audio::class,
'video/mp4' => Video::class,
'video/x-ms-wmv' => Video::class,
'video/avi' => Video::class,
'video/quicktime' => Video::class,
```

---

### Phase 4: Support des Graphiques (Priorité: Moyenne)

#### `Presentation/Resource/Chart.php`

```php
<?php

declare(strict_types=1);

namespace Cristal\Presentation\Resource;

/**
 * Chart resource class for handling DrawingML charts.
 * 
 * @see ECMA-376 Part 1, Section 21.2 - DrawingML - Charts
 */
class Chart extends XmlResource
{
    /**
     * Chart types supported by PresentationML.
     */
    public const CHART_TYPES = [
        'bar' => 'barChart',
        'pie' => 'pieChart',
        'line' => 'lineChart',
        'area' => 'areaChart',
        'scatter' => 'scatterChart',
        'doughnut' => 'doughnutChart',
        'radar' => 'radarChart',
    ];

    /**
     * Get the chart type.
     */
    public function getChartType(): ?string
    {
        $ns = $this->getNamespaces();
        
        foreach (self::CHART_TYPES as $type => $xmlTag) {
            if (!empty($this->content->xpath("//c:$xmlTag"))) {
                return $type;
            }
        }
        
        return null;
    }

    /**
     * Get chart title.
     */
    public function getTitle(): ?string
    {
        $title = $this->content->xpath('//c:title//c:tx//a:t');
        return !empty($title) ? (string) $title[0] : null;
    }

    /**
     * Get chart data.
     *
     * @return array<string, array<int, mixed>>
     */
    public function getData(): array
    {
        // Extract data from c:numCache or c:strCache
        $data = [];
        
        foreach ($this->content->xpath('//c:ser') as $series) {
            $seriesName = (string) ($series->xpath('c:tx//c:v')[0] ?? 'Series');
            $values = [];
            
            foreach ($series->xpath('.//c:val//c:v') as $v) {
                $values[] = (float) $v;
            }
            
            $data[$seriesName] = $values;
        }
        
        return $data;
    }

    /**
     * Update chart data.
     *
     * @param array<string, array<int, mixed>> $data
     */
    public function setData(array $data): void
    {
        // Update data in the chart XML
        // This is complex and requires careful XML manipulation
    }
}
```

---

### Phase 5: Métadonnées et Propriétés (Priorité: Moyenne)

#### `Presentation/Resource/CoreProperties.php`

```php
<?php

declare(strict_types=1);

namespace Cristal\Presentation\Resource;

/**
 * Core properties resource class for Dublin Core metadata.
 * 
 * @see ECMA-376 Part 2, Section 11 - Core Properties
 */
class CoreProperties extends XmlResource
{
    /**
     * Get all core properties.
     *
     * @return array<string, string|null>
     */
    public function getProperties(): array
    {
        return [
            'title' => $this->getProperty('dc:title'),
            'subject' => $this->getProperty('dc:subject'),
            'creator' => $this->getProperty('dc:creator'),
            'keywords' => $this->getProperty('cp:keywords'),
            'description' => $this->getProperty('dc:description'),
            'lastModifiedBy' => $this->getProperty('cp:lastModifiedBy'),
            'revision' => $this->getProperty('cp:revision'),
            'created' => $this->getProperty('dcterms:created'),
            'modified' => $this->getProperty('dcterms:modified'),
            'category' => $this->getProperty('cp:category'),
        ];
    }

    /**
     * Get a specific property.
     */
    public function getProperty(string $name): ?string
    {
        $nodes = $this->content->xpath("//$name");
        return !empty($nodes) ? (string) $nodes[0] : null;
    }

    /**
     * Set a core property.
     */
    public function setProperty(string $name, string $value): void
    {
        $nodes = $this->content->xpath("//$name");
        if (!empty($nodes)) {
            $nodes[0][0] = $value;
        }
        $this->save();
    }

    /**
     * Update modification timestamp.
     */
    public function touch(): void
    {
        $this->setProperty('dcterms:modified', date('c'));
    }
}
```

---

### Phase 6: Formats d'Image Modernes (Priorité: Moyenne)

#### Ajouter dans `ContentType.php`

```php
// Nouveaux formats d'image supportés depuis Office 2019/365
'image/webp' => Image::class,
'image/avif' => Image::class,
'image/heif' => Image::class,
'image/heic' => Image::class,
'image/svg+xml' => SvgImage::class,  // Nouveau type
```

#### `Presentation/Resource/SvgImage.php`

```php
<?php

declare(strict_types=1);

namespace Cristal\Presentation\Resource;

/**
 * SVG image resource class.
 * 
 * SVG support added in Office 365 (2016+)
 * @see https://support.microsoft.com/en-us/office/edit-svg-images-in-microsoft-365
 */
class SvgImage extends GenericResource
{
    /**
     * Check if the SVG is valid.
     */
    public function isValid(): bool
    {
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($this->getContent());
        libxml_clear_errors();
        
        return $xml !== false && $xml->getName() === 'svg';
    }

    /**
     * Get SVG dimensions.
     *
     * @return array{width: int|null, height: int|null}
     */
    public function getDimensions(): array
    {
        $xml = simplexml_load_string($this->getContent());
        
        return [
            'width' => $xml ? (int) $xml['width'] : null,
            'height' => $xml ? (int) $xml['height'] : null,
        ];
    }

    /**
     * Convert SVG to PNG for fallback.
     *
     * @param int $width Target width
     * @param int $height Target height
     * @return string|null PNG content or null if GD/Imagick not available
     */
    public function toPng(int $width = 800, int $height = 600): ?string
    {
        if (!extension_loaded('imagick')) {
            return null;
        }
        
        $imagick = new \Imagick();
        $imagick->readImageBlob($this->getContent());
        $imagick->setImageFormat('png');
        $imagick->resizeImage($width, $height, \Imagick::FILTER_LANCZOS, 1);
        
        return $imagick->getImageBlob();
    }
}
```

---

### Phase 7: Animations et Transitions (Priorité: Basse)

Les animations sont complexes mais importantes pour une compatibilité complète.

#### Structure XML des Animations

```xml
<!-- p:timing dans slide.xml -->
<p:timing>
    <p:tnLst>
        <p:par>
            <p:cTn id="1" dur="indefinite" restart="never" nodeType="tmRoot">
                <!-- Animation timeline -->
            </p:cTn>
        </p:par>
    </p:tnLst>
</p:timing>
```

#### `Presentation/Resource/Animation/Timeline.php`

```php
<?php

declare(strict_types=1);

namespace Cristal\Presentation\Resource\Animation;

/**
 * Animation timeline for slide animations.
 * 
 * @see ECMA-376 Part 1, Section 19.5 - Animation
 */
class Timeline
{
    /**
     * Animation effect types.
     */
    public const EFFECT_TYPES = [
        'entrance' => 'entr',
        'exit' => 'exit',
        'emphasis' => 'emph',
        'motion' => 'path',
    ];

    /**
     * Common animation presets.
     */
    public const PRESETS = [
        'fade' => ['presetID' => 10, 'presetClass' => 'entr'],
        'fly_in' => ['presetID' => 2, 'presetClass' => 'entr'],
        'wipe' => ['presetID' => 22, 'presetClass' => 'entr'],
        'zoom' => ['presetID' => 23, 'presetClass' => 'entr'],
    ];
}
```

---

### Phase 8: Diagrammes SmartArt (Priorité: Basse)

SmartArt utilise DrawingML Diagrams, très complexe.

#### Structure

```
ppt/diagrams/
├── data1.xml          # Données du diagramme
├── colors1.xml        # Schéma de couleurs
├── quickStyle1.xml    # Style rapide
└── layout1.xml        # Mise en page
```

---

## 📋 Roadmap de Développement

### Version 2.0 (Q1 2025)
- [ ] Support complet des NoteSlide
- [ ] Support des commentaires
- [ ] Métadonnées Dublin Core

### Version 2.1 (Q2 2025)
- [ ] Support audio/vidéo
- [ ] Support SVG
- [ ] Support WebP/AVIF

### Version 2.2 (Q3 2025)
- [ ] Support des graphiques (lecture)
- [ ] Tags personnalisés

### Version 3.0 (Q4 2025)
- [ ] Animations (lecture/écriture basique)
- [ ] SmartArt (lecture seule)
- [ ] OLE Objects

---

## 🔧 Améliorations Techniques

### 1. Validation XML Schema

Ajouter la validation contre les schémas XSD officiels :

```php
class XmlValidator
{
    private const SCHEMA_PATH = __DIR__ . '/schemas/';
    
    public function validate(string $xml, string $schemaFile): array
    {
        libxml_use_internal_errors(true);
        
        $doc = new \DOMDocument();
        $doc->loadXML($xml);
        
        $valid = $doc->schemaValidate(self::SCHEMA_PATH . $schemaFile);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        
        return [
            'valid' => $valid,
            'errors' => array_map(fn($e) => $e->message, $errors),
        ];
    }
}
```

### 2. Gestion des Relations

Améliorer le mapping des relations :

```php
class RelationshipManager
{
    public const RELATIONSHIP_TYPES = [
        'slide' => 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/slide',
        'slideLayout' => 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideLayout',
        'slideMaster' => 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideMaster',
        'theme' => 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/theme',
        'noteSlide' => 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/notesSlide',
        'comments' => 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/comments',
        'chart' => 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/chart',
        'image' => 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/image',
        'audio' => 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/audio',
        'video' => 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/video',
    ];
}
```

### 3. Streaming pour Gros Fichiers

Pour les présentations volumineuses (> 100 Mo) :

```php
class StreamingPptx
{
    public function streamSlides(string $path): \Generator
    {
        $archive = new ZipArchive();
        $archive->open($path);
        
        $contentTypes = $this->parseContentTypes($archive);
        
        foreach ($contentTypes as $path => $type) {
            if ($type === 'slide') {
                yield $path => $archive->getFromName($path);
            }
        }
    }
}
```

---

## 📚 Références

- [ECMA-376 5th Edition](https://www.ecma-international.org/publications-and-standards/standards/ecma-376/)
- [ISO/IEC 29500](https://www.iso.org/standard/71691.html)
- [Microsoft Open XML SDK Documentation](https://docs.microsoft.com/en-us/office/open-xml/open-xml-sdk)
- [Office File Formats Documentation](https://docs.microsoft.com/en-us/openspecs/office_file_formats/ms-pptx/)

---

## ✅ Checklist de Conformité ECMA-376

### Open Packaging Conventions (Part 2)
- [x] Gestion des Content Types
- [x] Gestion des Relations (.rels)
- [ ] Digital Signatures
- [ ] Core Properties (Dublin Core)
- [ ] Custom Properties

### PresentationML (Part 1, Chapter 13)
- [x] Presentation Part
- [x] Slide Part
- [x] Slide Layout Part
- [x] Slide Master Part
- [x] Theme Part
- [ ] Notes Slide Part (à améliorer)
- [ ] Notes Master Part
- [ ] Handout Master Part
- [ ] Comment Authors Part
- [ ] Comments Part
- [ ] Tags Part
- [ ] User-Defined Tags Part

### DrawingML (Part 1, Chapters 20-21)
- [x] Images (basique)
- [ ] SVG
- [ ] Charts
- [ ] Diagrams (SmartArt)
- [ ] 3D Effects

### Animations (Part 1, Chapter 19)
- [ ] Timing
- [ ] Build Steps
- [ ] Transitions