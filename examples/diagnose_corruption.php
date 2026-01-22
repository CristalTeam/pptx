<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Cristal\Presentation\PPTX;

/**
 * Script de diagnostic pour identifier les problèmes de corruption dans les fichiers PPTX fusionnés
 */
class PptxDiagnostic
{
    private string $filePath;
    private array $issues = [];
    private array $warnings = [];
    private ZipArchive $zip;

    public function __construct(string $filePath)
    {
        $this->filePath = $filePath;
        $this->zip = new ZipArchive();
        
        if ($this->zip->open($filePath) !== true) {
            throw new Exception("Cannot open file: $filePath");
        }
    }

    public function __destruct()
    {
        $this->zip->close();
    }

    /**
     * Run full diagnostic
     */
    public function diagnose(): array
    {
        echo "🔍 Diagnostic de: {$this->filePath}\n\n";

        $this->checkPresentationXml();
        $this->checkRelationshipsConsistency();
        $this->checkSlideLayoutMasterReferences();
        $this->checkDuplicateIds();
        $this->checkContentTypes();

        return [
            'issues' => $this->issues,
            'warnings' => $this->warnings,
            'valid' => empty($this->issues)
        ];
    }

    /**
     * Check presentation.xml structure
     */
    private function checkPresentationXml(): void
    {
        echo "📄 Vérification de presentation.xml...\n";
        
        $content = $this->zip->getFromName('ppt/presentation.xml');
        if ($content === false) {
            $this->issues[] = "presentation.xml n'existe pas";
            return;
        }

        $xml = simplexml_load_string($content);
        $xml->registerXPathNamespace('p', 'http://schemas.openxmlformats.org/presentationml/2006/main');
        $xml->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');

        // Check slides
        $slides = $xml->xpath('//p:sldId');
        echo "   ✓ Slides trouvées: " . count($slides) . "\n";

        // Check SlideMasters
        $masters = $xml->xpath('//p:sldMasterId');
        echo "   ✓ SlideMasters trouvés: " . count($masters) . "\n";

        // Check NotesMasters
        $notesMasters = $xml->xpath('//p:notesMasterId');
        echo "   ✓ NotesMasters trouvés: " . count($notesMasters) . "\n";

        echo "\n";
    }

    /**
     * Check for duplicate IDs
     */
    private function checkDuplicateIds(): void
    {
        echo "🔢 Vérification des IDs dupliqués...\n";
        
        $content = $this->zip->getFromName('ppt/presentation.xml');
        $xml = simplexml_load_string($content);
        $xml->registerXPathNamespace('p', 'http://schemas.openxmlformats.org/presentationml/2006/main');
        $xml->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');

        // Check slide IDs
        $slides = $xml->xpath('//p:sldId');
        $slideIds = [];
        $slideRIds = [];
        
        foreach ($slides as $slide) {
            $id = (string)$slide['id'];
            $rId = (string)$slide->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')->id;
            
            if (in_array($id, $slideIds)) {
                $this->issues[] = "ID de slide dupliqué: $id";
            }
            if (in_array($rId, $slideRIds)) {
                $this->issues[] = "Relationship ID de slide dupliqué: $rId";
            }
            
            $slideIds[] = $id;
            $slideRIds[] = $rId;
        }

        // Check SlideMaster IDs
        $masters = $xml->xpath('//p:sldMasterId');
        $masterIds = [];
        $masterRIds = [];
        
        foreach ($masters as $master) {
            $id = (string)$master['id'];
            $rId = (string)$master->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')->id;
            
            if (in_array($id, $masterIds)) {
                $this->issues[] = "ID de SlideMaster dupliqué: $id";
            }
            if (in_array($rId, $masterRIds)) {
                $this->issues[] = "Relationship ID de SlideMaster dupliqué: $rId";
            }
            
            $masterIds[] = $id;
            $masterRIds[] = $rId;
        }

        if (empty($this->issues)) {
            echo "   ✓ Aucun ID dupliqué trouvé\n";
        }
        
        echo "\n";
    }

    /**
     * Check relationships consistency
     */
    private function checkRelationshipsConsistency(): void
    {
        echo "🔗 Vérification de la cohérence des relationships...\n";
        
        // Load presentation.xml.rels
        $relsContent = $this->zip->getFromName('ppt/_rels/presentation.xml.rels');
        if ($relsContent === false) {
            $this->issues[] = "ppt/_rels/presentation.xml.rels n'existe pas";
            return;
        }

        $relsXml = simplexml_load_string($relsContent);
        $relsXml->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/package/2006/relationships');

        $relationships = $relsXml->xpath('//r:Relationship');
        $missingFiles = [];
        $checkedFiles = 0;

        foreach ($relationships as $rel) {
            $id = (string)$rel['Id'];
            $target = (string)$rel['Target'];
            $type = (string)$rel['Type'];
            
            // Skip external relationships
            if (isset($rel['TargetMode']) && (string)$rel['TargetMode'] === 'External') {
                continue;
            }

            // Construct full path
            $fullPath = 'ppt/' . $target;
            
            // Check if file exists
            if ($this->zip->locateName($fullPath) === false) {
                $missingFiles[] = "$id -> $fullPath";
                $this->issues[] = "Fichier manquant référencé: $fullPath (rId: $id)";
            }
            
            $checkedFiles++;
        }

        echo "   ✓ Relationships vérifiées: $checkedFiles\n";
        if (!empty($missingFiles)) {
            echo "   ✗ Fichiers manquants: " . count($missingFiles) . "\n";
            foreach ($missingFiles as $missing) {
                echo "     - $missing\n";
            }
        }
        
        echo "\n";
    }

    /**
     * Check SlideLayout to SlideMaster references
     */
    private function checkSlideLayoutMasterReferences(): void
    {
        echo "🎨 Vérification des références SlideLayout -> SlideMaster...\n";
        
        // Find all slideLayouts
        for ($i = 0; $i < $this->zip->numFiles; $i++) {
            $filename = $this->zip->getNameIndex($i);
            
            if (preg_match('#ppt/slideLayouts/slideLayout\d+\.xml$#', $filename)) {
                $this->checkSingleSlideLayout($filename);
            }
        }
        
        echo "\n";
    }

    /**
     * Check a single slideLayout file
     */
    private function checkSingleSlideLayout(string $filename): void
    {
        $content = $this->zip->getFromName($filename);
        if ($content === false) {
            return;
        }

        $xml = simplexml_load_string($content);
        $xml->registerXPathNamespace('p', 'http://schemas.openxmlformats.org/presentationml/2006/main');
        $xml->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');

        // Get the sldMasterId reference
        $masterRefs = $xml->xpath('//p:sldLayout/p:cSld/@name');
        
        // Check corresponding rels file
        $relsFilename = dirname($filename) . '/_rels/' . basename($filename) . '.rels';
        $relsContent = $this->zip->getFromName($relsFilename);
        
        if ($relsContent === false) {
            $this->warnings[] = "Fichier .rels manquant: $relsFilename";
            return;
        }

        $relsXml = simplexml_load_string($relsContent);
        $relsXml->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/package/2006/relationships');

        // Find slideMaster relationship
        $masterRels = $relsXml->xpath('//r:Relationship[contains(@Type, "slideMaster")]');
        
        if (empty($masterRels)) {
            $this->issues[] = "$filename: Aucune référence vers un SlideMaster trouvée";
        } else {
            $target = (string)$masterRels[0]['Target'];
            $fullPath = dirname(dirname($filename)) . '/' . $target;
            
            if ($this->zip->locateName($fullPath) === false) {
                $this->issues[] = "$filename: SlideMaster référencé n'existe pas: $fullPath";
            }
        }
    }

    /**
     * Check [Content_Types].xml
     */
    private function checkContentTypes(): void
    {
        echo "📦 Vérification de [Content_Types].xml...\n";
        
        $content = $this->zip->getFromName('[Content_Types].xml');
        if ($content === false) {
            $this->issues[] = "[Content_Types].xml n'existe pas";
            return;
        }

        $xml = simplexml_load_string($content);
        
        // Count overrides
        $overrides = $xml->xpath('//Override');
        echo "   ✓ Override entries: " . count($overrides) . "\n";

        // Check for duplicates
        $parts = [];
        foreach ($overrides as $override) {
            $partName = (string)$override['PartName'];
            if (in_array($partName, $parts)) {
                $this->issues[] = "PartName dupliqué dans Content_Types: $partName";
            }
            $parts[] = $partName;
        }
        
        echo "\n";
    }

    /**
     * Display results
     */
    public function displayResults(): void
    {
        echo "\n" . str_repeat('=', 60) . "\n";
        echo "📊 RÉSULTATS DU DIAGNOSTIC\n";
        echo str_repeat('=', 60) . "\n\n";

        if (empty($this->issues) && empty($this->warnings)) {
            echo "✅ Aucun problème détecté !\n";
            echo "Le fichier semble valide selon les critères PowerPoint.\n";
        } else {
            if (!empty($this->issues)) {
                echo "❌ PROBLÈMES CRITIQUES (" . count($this->issues) . "):\n";
                foreach ($this->issues as $i => $issue) {
                    echo "   " . ($i + 1) . ". $issue\n";
                }
                echo "\n";
            }

            if (!empty($this->warnings)) {
                echo "⚠️  AVERTISSEMENTS (" . count($this->warnings) . "):\n";
                foreach ($this->warnings as $i => $warning) {
                    echo "   " . ($i + 1) . ". $warning\n";
                }
                echo "\n";
            }
        }
    }
}

// Usage
if ($argc < 2) {
    echo "Usage: php diagnose_corruption.php <path-to-pptx-file>\n";
    exit(1);
}

$filePath = $argv[1];

if (!file_exists($filePath)) {
    echo "❌ Fichier non trouvé: $filePath\n";
    exit(1);
}

try {
    $diagnostic = new PptxDiagnostic($filePath);
    $results = $diagnostic->diagnose();
    $diagnostic->displayResults();
    
    exit($results['valid'] ? 0 : 1);
} catch (Exception $e) {
    echo "❌ Erreur: " . $e->getMessage() . "\n";
    exit(1);
}