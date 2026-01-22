<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

class DeepPPTXValidator
{
    private ZipArchive $zip;
    private array $issues = [];
    private array $warnings = [];
    private array $files = [];
    private array $relationships = [];

    public function __construct(string $filePath)
    {
        $this->zip = new ZipArchive();
        if ($this->zip->open($filePath) !== true) {
            throw new Exception("Cannot open file: $filePath");
        }

        // Index all files
        for ($i = 0; $i < $this->zip->numFiles; $i++) {
            $this->files[] = $this->zip->getNameIndex($i);
        }
    }

    public function __destruct()
    {
        $this->zip->close();
    }

    public function validate(): array
    {
        echo "🔍 VALIDATION APPROFONDIE DU PPTX\n";
        echo str_repeat('=', 60) . "\n\n";

        $this->validateStructure();
        $this->validateContentTypes();
        $this->validateRelationships();
        $this->validatePresentationXml();
        $this->validateSlides();
        $this->validateMasters();
        $this->validateLayouts();
        $this->validateThemes();
        $this->checkOrphanFiles();

        return [
            'valid' => empty($this->issues),
            'issues' => $this->issues,
            'warnings' => $this->warnings,
        ];
    }

    private function validateStructure(): void
    {
        echo "📦 1. Validation de la structure de base...\n";
        
        $required = [
            '[Content_Types].xml',
            '_rels/.rels',
            'ppt/presentation.xml',
            'ppt/_rels/presentation.xml.rels',
        ];

        foreach ($required as $file) {
            if (!in_array($file, $this->files)) {
                $this->issues[] = "Fichier requis manquant: $file";
            }
        }

        echo "   ✓ Fichiers essentiels vérifiés\n\n";
    }

    private function validateContentTypes(): void
    {
        echo "📋 2. Validation de [Content_Types].xml...\n";
        
        $content = $this->zip->getFromName('[Content_Types].xml');
        $xml = simplexml_load_string($content);
        
        // Check for duplicate PartNames
        $partNames = [];
        foreach ($xml->Override as $override) {
            $partName = (string)$override['PartName'];
            if (in_array($partName, $partNames)) {
                $this->issues[] = "PartName dupliqué dans Content_Types: $partName";
            }
            $partNames[] = $partName;
        }

        // Check that all XML files are declared
        foreach ($this->files as $file) {
            if (str_ends_with($file, '.xml') && $file !== '[Content_Types].xml') {
                $partName = '/' . $file;
                $found = false;
                
                // Check in Overrides
                foreach ($xml->Override as $override) {
                    if ((string)$override['PartName'] === $partName) {
                        $found = true;
                        break;
                    }
                }
                
                // Check in Defaults by extension
                if (!$found) {
                    foreach ($xml->Default as $default) {
                        if ((string)$default['Extension'] === 'xml') {
                            $found = true;
                            break;
                        }
                    }
                }
                
                if (!$found && !str_ends_with($file, '.rels')) {
                    $this->warnings[] = "Fichier XML non déclaré dans Content_Types: $file";
                }
            }
        }

        echo "   ✓ Content_Types validé\n\n";
    }

    private function validateRelationships(): void
    {
        echo "🔗 3. Validation des relationships...\n";
        
        foreach ($this->files as $file) {
            if (str_ends_with($file, '.rels')) {
                $this->validateRelsFile($file);
            }
        }

        echo "   ✓ Relationships validées\n\n";
    }

    private function validateRelsFile(string $relsFile): void
    {
        $content = $this->zip->getFromName($relsFile);
        $xml = simplexml_load_string($content);
        $xml->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/package/2006/relationships');

        $basePath = dirname(dirname($relsFile)) . '/';
        $relationships = $xml->xpath('//r:Relationship');

        foreach ($relationships as $rel) {
            $id = (string)$rel['Id'];
            $target = (string)$rel['Target'];
            $type = (string)$rel['Type'];
            $targetMode = isset($rel['TargetMode']) ? (string)$rel['TargetMode'] : 'Internal';

            // Store relationship
            if (!isset($this->relationships[$relsFile])) {
                $this->relationships[$relsFile] = [];
            }
            $this->relationships[$relsFile][$id] = [
                'target' => $target,
                'type' => $type,
                'mode' => $targetMode,
            ];

            // Skip external relationships
            if ($targetMode === 'External') {
                continue;
            }

            // Resolve target path
            $targetPath = $basePath . $target;
            $targetPath = $this->resolvePath($targetPath);

            // Check if target exists
            if (!in_array($targetPath, $this->files)) {
                $this->issues[] = "Relationship cible manquante: $targetPath (de $relsFile, rId: $id)";
            }
        }
    }

    private function resolvePath(string $path): string
    {
        $parts = explode('/', $path);
        $resolved = [];
        
        foreach ($parts as $part) {
            if ($part === '.' || $part === '') {
                continue;
            }
            if ($part === '..') {
                array_pop($resolved);
            } else {
                $resolved[] = $part;
            }
        }
        
        return implode('/', $resolved);
    }

    private function validatePresentationXml(): void
    {
        echo "📄 4. Validation de presentation.xml...\n";
        
        $content = $this->zip->getFromName('ppt/presentation.xml');
        $xml = simplexml_load_string($content);
        $xml->registerXPathNamespace('p', 'http://schemas.openxmlformats.org/presentationml/2006/main');
        $xml->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');

        // Check for duplicate slide IDs
        $slideIds = [];
        $slides = $xml->xpath('//p:sldId');
        foreach ($slides as $slide) {
            $id = (string)$slide['id'];
            if (in_array($id, $slideIds)) {
                $this->issues[] = "ID de slide dupliqué: $id";
            }
            $slideIds[] = $id;
        }

        // Check for duplicate slideMaster IDs
        $masterIds = [];
        $masters = $xml->xpath('//p:sldMasterId');
        foreach ($masters as $master) {
            $id = (string)$master['id'];
            if (in_array($id, $masterIds)) {
                $this->issues[] = "ID de slideMaster dupliqué: $id";
            }
            $masterIds[] = $id;
        }

        echo "   ✓ presentation.xml validé\n\n";
    }

    private function validateSlides(): void
    {
        echo "📊 5. Validation des slides...\n";
        
        $slideFiles = array_filter($this->files, fn($f) => preg_match('#ppt/slides/slide\d+\.xml$#', $f));
        
        foreach ($slideFiles as $slideFile) {
            $this->validateSlideFile($slideFile);
        }

        echo "   ✓ " . count($slideFiles) . " slides validées\n\n";
    }

    private function validateSlideFile(string $slideFile): void
    {
        $content = $this->zip->getFromName($slideFile);
        $xml = simplexml_load_string($content);
        $xml->registerXPathNamespace('p', 'http://schemas.openxmlformats.org/presentationml/2006/main');
        $xml->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');

        // Check that slide has a layout reference
        $layoutRefs = $xml->xpath('//p:sldLayout');
        if (empty($layoutRefs)) {
            $this->issues[] = "$slideFile: Pas de référence vers un slideLayout";
        }
    }

    private function validateMasters(): void
    {
        echo "🎨 6. Validation des slideMasters...\n";
        
        $masterFiles = array_filter($this->files, fn($f) => preg_match('#ppt/slideMasters/slideMaster\d+\.xml$#', $f));
        
        foreach ($masterFiles as $masterFile) {
            $this->validateMasterFile($masterFile);
        }

        echo "   ✓ " . count($masterFiles) . " masters validés\n\n";
    }

    private function validateMasterFile(string $masterFile): void
    {
        // Check that master has a .rels file
        $relsFile = dirname($masterFile) . '/_rels/' . basename($masterFile) . '.rels';
        if (!in_array($relsFile, $this->files)) {
            $this->issues[] = "$masterFile: Fichier .rels manquant ($relsFile)";
            return;
        }

        // Check that master references a theme
        $content = $this->zip->getFromName($relsFile);
        if (strpos($content, 'officeDocument/2006/relationships/theme') === false) {
            $this->issues[] = "$masterFile: Ne référence pas de theme";
        }
    }

    private function validateLayouts(): void
    {
        echo "📐 7. Validation des slideLayouts...\n";
        
        $layoutFiles = array_filter($this->files, fn($f) => preg_match('#ppt/slideLayouts/slideLayout\d+\.xml$#', $f));
        
        foreach ($layoutFiles as $layoutFile) {
            $this->validateLayoutFile($layoutFile);
        }

        echo "   ✓ " . count($layoutFiles) . " layouts validés\n\n";
    }

    private function validateLayoutFile(string $layoutFile): void
    {
        // Check that layout has a .rels file
        $relsFile = dirname($layoutFile) . '/_rels/' . basename($layoutFile) . '.rels';
        if (!in_array($relsFile, $this->files)) {
            $this->issues[] = "$layoutFile: Fichier .rels manquant ($relsFile)";
            return;
        }

        // Check that layout references a slideMaster
        $content = $this->zip->getFromName($relsFile);
        if (strpos($content, 'officeDocument/2006/relationships/slideMaster') === false) {
            $this->issues[] = "$layoutFile: Ne référence pas de slideMaster";
        }
    }

    private function validateThemes(): void
    {
        echo "🎨 8. Validation des themes...\n";
        
        $themeFiles = array_filter($this->files, fn($f) => preg_match('#ppt/theme/theme\d+\.xml$#', $f));
        
        // Check for orphan themes
        $referencedThemes = [];
        foreach ($this->relationships as $relsFile => $rels) {
            foreach ($rels as $rel) {
                if (strpos($rel['type'], 'theme') !== false && $rel['mode'] === 'Internal') {
                    $basePath = dirname(dirname($relsFile)) . '/';
                    $themePath = $this->resolvePath($basePath . $rel['target']);
                    $referencedThemes[] = $themePath;
                }
            }
        }
        $referencedThemes = array_unique($referencedThemes);

        foreach ($themeFiles as $themeFile) {
            if (!in_array($themeFile, $referencedThemes)) {
                $this->issues[] = "Theme orphelin (non référencé): $themeFile";
            }
        }

        echo "   ✓ " . count($themeFiles) . " themes validés\n\n";
    }

    private function checkOrphanFiles(): void
    {
        echo "🗑️  9. Vérification des fichiers orphelins...\n";
        
        // Collect all referenced files
        $referenced = [];
        foreach ($this->relationships as $rels) {
            foreach ($rels as $rel) {
                if ($rel['mode'] === 'Internal') {
                    $referenced[] = $rel['target'];
                }
            }
        }

        // Check for unreferenced files (excluding known system files)
        $systemFiles = ['[Content_Types].xml', '_rels/.rels', 'ppt/presentation.xml'];
        
        foreach ($this->files as $file) {
            if (in_array($file, $systemFiles) || str_ends_with($file, '.rels')) {
                continue;
            }
            
            $isReferenced = false;
            foreach ($referenced as $ref) {
                if (strpos($ref, basename($file)) !== false) {
                    $isReferenced = true;
                    break;
                }
            }
            
            if (!$isReferenced && !str_ends_with($file, '/')) {
                $this->warnings[] = "Fichier potentiellement orphelin: $file";
            }
        }

        echo "   ✓ Vérification terminée\n\n";
    }

    public function displayResults(): void
    {
        echo str_repeat('=', 60) . "\n";
        echo "📊 RÉSULTATS DE LA VALIDATION\n";
        echo str_repeat('=', 60) . "\n\n";

        if (empty($this->issues) && empty($this->warnings)) {
            echo "✅ AUCUN PROBLÈME DÉTECTÉ !\n";
            echo "Le fichier semble valide selon les spécifications PPTX.\n";
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
    echo "Usage: php deep_validation.php <path-to-pptx-file>\n";
    exit(1);
}

$filePath = $argv[1];

if (!file_exists($filePath)) {
    echo "❌ Fichier non trouvé: $filePath\n";
    exit(1);
}

try {
    $validator = new DeepPPTXValidator($filePath);
    $results = $validator->validate();
    $validator->displayResults();
    
    exit($results['valid'] ? 0 : 1);
} catch (Exception $e) {
    echo "❌ Erreur: " . $e->getMessage() . "\n";
    exit(1);
}