<?php

namespace Cristal\Presentation;

use Closure;
use Cristal\Presentation\Cache\ImageCache;
use Cristal\Presentation\Config\OptimizationConfig;
use Cristal\Presentation\Exception\FileOpenException;
use Cristal\Presentation\Exception\FileSaveException;
use Cristal\Presentation\Resource\ContentType;
use Cristal\Presentation\Resource\GenericResource;
use Cristal\Presentation\Resource\Image;
use Cristal\Presentation\Resource\Presentation;
use Cristal\Presentation\Resource\Slide;
use Cristal\Presentation\Resource\XmlResource;
use Cristal\Presentation\Stats\OptimizationStats;
use Cristal\Presentation\Validator\ImageValidator;
use Cristal\Presentation\Validator\PresentationValidator;
use Exception;
use ZipArchive;

class PPTX
{
    /**
     * @var ZipArchive
     */
    protected $archive;

    /**
     * @var Slide[]
     */
    protected $slides = [];

    /**
     * @var Presentation
     */
    protected $presentation;

    /**
     * @var string
     */
    protected $filename;

    /**
     * @var string
     */
    protected $tmpName;

    /**
     * @var ContentType
     */
    protected $contentType;

    /**
     * @var OptimizationConfig
     */
    protected $config;

    /**
     * @var ImageCache
     */
    protected $imageCache;

    /**
     * @var OptimizationStats
     */
    protected $stats;

    /**
     * @var PresentationValidator|null
     */
    protected $validator;

    /**
     * Presentation constructor.
     *
     * @param string $path Chemin vers le fichier PPTX
     * @param array $options Options d'optimisation (optionnel)
     * @throws Exception
     */
    public function __construct(string $path, array $options = [])
    {
        $this->filename = $path;
        $this->config = new OptimizationConfig($options);
        $this->imageCache = new ImageCache();
        $this->stats = new OptimizationStats();
        
        // Initialiser le validateur si activé
        if ($this->config->isEnabled('validate_images')) {
            $this->validator = new PresentationValidator($this->config);
        }

        if (!file_exists($path)) {
            throw new FileOpenException('Unable to open the source PPTX. Path does not exist.');
        }

        // Create tmp copy
        $this->tmpName = tempnam(sys_get_temp_dir(), 'PPTX_');

        copy($path, $this->tmpName);

        // Open copy
        $this->openFile($this->tmpName);
    }

    /**
     * Open a PPTX file.
     *
     * @throws FileOpenException
     */
    public function openFile(string $path): PPTX
    {
        $this->archive = new ZipArchive();
        $res = $this->archive->open($path);

        if ($res !== true) {
            throw new FileOpenException($res->getStatusString());
        }

        $this->contentType = new ContentType($this);
        $this->presentation = $this->contentType->getResource('ppt/presentation.xml');

        $this->loadSlides();

        return $this;
    }

    /**
     * Read existing slides.
     */
    protected function loadSlides(): PPTX
    {
        $this->slides = [];

        foreach ($this->presentation->content->xpath('p:sldIdLst/p:sldId') as $slide) {
            $id = $slide->xpath('@r:id')[0]['id'] . '';
            $this->slides[] = $this->presentation->getResource($id);
        }

        return $this;
    }

    /**
     * Get all slides available in the current presentation.
     *
     * @return Slide[]
     */
    public function getSlides(): array
    {
        return $this->slides;
    }


    /**
     * Import a single slide object.
     *
     * @throws Exception
     */
    public function addSlide(Slide $slide): PPTX
    {
        return $this->addResource($slide);
    }

    /**
     * Add a resource and its dependency inside this document.
     */
    public function addResource(GenericResource $res): PPTX
    {
        $tree = $this->getResourceTree($res);

        /** @var GenericResource[] $clonedResources */
        $clonedResources = [];

        // Clone, rename, and set new destination...

        foreach($tree as $originalResource){

            if(!$originalResource instanceof GenericResource){
                $resource = clone $originalResource;
                $clonedResources[$originalResource->getTarget()] = $resource;
                continue;
            }

            // Vérifier si c'est une image et si la déduplication est activée
            if ($originalResource instanceof Image && $this->config->isEnabled('deduplicate_images')) {
                $duplicate = $this->imageCache->findDuplicate($originalResource->getContent());
                if ($duplicate !== null) {
                    $clonedResources[$originalResource->getTarget()] = $duplicate;
                    if ($this->config->isEnabled('collect_stats')) {
                        $this->stats->recordDeduplication();
                    }
                    continue;
                }
            }

            // Check if resource already exists in the document.
            $existingResource = $this->getContentType()->lookForSimilarFile($originalResource);

            if(null === $existingResource || $originalResource instanceof XmlResource) {
                $resource = clone $originalResource;
                $resource->setDocument($this);
                $resource->rename(basename($this->getContentType()->findAvailableName($resource->getPatternPath())));
                $this->contentType->addResource($resource);

                // Configurer l'optimisation pour les images
                if ($resource instanceof Image) {
                    $resource->setOptimizationConfig($this->config);
                    $this->imageCache->registerWithContent($resource->getContent(), $resource);
                }

                $clonedResources[$originalResource->getTarget()] = $resource;
            } else {
                $clonedResources[$originalResource->getTarget()] = $existingResource;
            }
        }

        // After the resource is renamed, replace existing "rIds" by the corresponding new resource...

        foreach($clonedResources as $resource){
            if($resource instanceof XmlResource){
                foreach($resource->resources as $rId => $subResource){
                    $resource->resources[$rId] = $clonedResources[$subResource->getTarget()];
                }
            }

            // Also, notify the Presentation that have a new interesting object...
            $this->presentation->addResource($resource);

            if($resource instanceof Slide){
                $this->slides[] = $res;
            }
        }

        // Finally, save all new resources.
        foreach($clonedResources as $resource){
            // Collecter les stats pour les images si activé
            if ($resource instanceof Image && $this->config->isEnabled('collect_stats')) {
                $originalSize = $resource->getOriginalSize();
                $compressedSize = $resource->getCompressedSize();
                if ($originalSize && $compressedSize && $originalSize !== $compressedSize) {
                    $type = $resource->detectImageType($resource->getContent()) ?? 'unknown';
                    $this->stats->recordCompression($originalSize, $compressedSize, $type);
                }
            }
            
            $resource->save();
        }

        // And the presentation.
        $this->presentation->save();
        $this->contentType->save();

        $this->refreshSource();

        return $this;
    }

    /**
     * @throws FileSaveException
     * @throws FileOpenException
     */
    public function refreshSource(): void
    {
        $this->close();
        $this->openFile($this->tmpName);
    }

    /**
     * Import multiple slides object.
     *
     * @throws Exception
     */
    public function addSlides(array $slides): PPTX
    {
        foreach ($slides as $slide) {
            $this->addSlide($slide);
        }

        return $this;
    }

    /**
     * Traitement par batch optimisé pour l'ajout de multiples slides
     * Plus rapide que addSlides car ne rafraîchit qu'une seule fois
     *
     * @param array $slides Tableau de slides à ajouter
     * @param array $options Options de traitement batch
     * @return PPTX
     * @throws Exception
     */
    public function addSlidesBatch(array $slides, array $options = []): PPTX
    {
        $defaultOptions = [
            'refresh_at_end' => true,      // Rafraîchir à la fin du batch
            'save_incrementally' => false, // Sauvegarder après chaque slide
            'collect_stats' => $this->config->isEnabled('collect_stats'),
        ];
        
        $options = array_merge($defaultOptions, $options);
        
        // Compteurs pour stats
        $addedCount = 0;
        $errorCount = 0;
        $errors = [];
        
        foreach ($slides as $index => $slide) {
            try {
                // Désactiver le refresh automatique temporairement
                $this->addResourceWithoutRefresh($slide);
                $addedCount++;
                
                // Sauvegarder incrémentalement si demandé
                if ($options['save_incrementally']) {
                    $this->presentation->save();
                    $this->contentType->save();
                }
            } catch (\Exception $e) {
                $errorCount++;
                $errors[] = [
                    'index' => $index,
                    'slide' => $slide,
                    'error' => $e->getMessage(),
                ];
                
                // En cas d'erreur, continuer ou arrêter selon l'option
                if (!isset($options['continue_on_error']) || !$options['continue_on_error']) {
                    throw $e;
                }
            }
        }
        
        // Sauvegarder et rafraîchir une seule fois à la fin
        if ($addedCount > 0) {
            $this->presentation->save();
            $this->contentType->save();
            
            if ($options['refresh_at_end']) {
                $this->refreshSource();
            }
        }
        
        // Enregistrer les stats si activé
        if ($options['collect_stats'] && $this->stats) {
            // Les stats sont déjà collectées par addResourceWithoutRefresh
        }
        
        return $this;
    }

    /**
     * Ajoute une ressource sans rafraîchir la source (pour traitement batch)
     *
     * @param GenericResource $res
     * @return PPTX
     * @throws Exception
     */
    protected function addResourceWithoutRefresh(GenericResource $res): PPTX
    {
        $tree = $this->getResourceTree($res);

        /** @var GenericResource[] $clonedResources */
        $clonedResources = [];

        // Clone, rename, and set new destination...

        foreach($tree as $originalResource){

            if(!$originalResource instanceof GenericResource){
                $resource = clone $originalResource;
                $clonedResources[$originalResource->getTarget()] = $resource;
                continue;
            }

            // Vérifier si c'est une image et si la déduplication est activée
            if ($originalResource instanceof Image && $this->config->isEnabled('deduplicate_images')) {
                $duplicate = $this->imageCache->findDuplicate($originalResource->getContent());
                if ($duplicate !== null) {
                    $clonedResources[$originalResource->getTarget()] = $duplicate;
                    if ($this->config->isEnabled('collect_stats')) {
                        $this->stats->recordDeduplication();
                    }
                    continue;
                }
            }

            // Check if resource already exists in the document.
            $existingResource = $this->getContentType()->lookForSimilarFile($originalResource);

            if(null === $existingResource || $originalResource instanceof XmlResource) {
                $resource = clone $originalResource;
                $resource->setDocument($this);
                $resource->rename(basename($this->getContentType()->findAvailableName($resource->getPatternPath())));
                $this->contentType->addResource($resource);

                // Configurer l'optimisation pour les images
                if ($resource instanceof Image) {
                    $resource->setOptimizationConfig($this->config);
                    $this->imageCache->registerWithContent($resource->getContent(), $resource);
                }

                $clonedResources[$originalResource->getTarget()] = $resource;
            } else {
                $clonedResources[$originalResource->getTarget()] = $existingResource;
            }
        }

        // After the resource is renamed, replace existing "rIds" by the corresponding new resource...

        foreach($clonedResources as $resource){
            if($resource instanceof XmlResource){
                foreach($resource->resources as $rId => $subResource){
                    $resource->resources[$rId] = $clonedResources[$subResource->getTarget()];
                }
            }

            // Also, notify the Presentation that have a new interesting object...
            $this->presentation->addResource($resource);

            if($resource instanceof Slide){
                $this->slides[] = $res;
            }
        }

        // Finally, save all new resources.
        foreach($clonedResources as $resource){
            // Collecter les stats pour les images si activé
            if ($resource instanceof Image && $this->config->isEnabled('collect_stats')) {
                $originalSize = $resource->getOriginalSize();
                $compressedSize = $resource->getCompressedSize();
                if ($originalSize && $compressedSize && $originalSize !== $compressedSize) {
                    $type = $resource->detectImageType($resource->getContent()) ?? 'unknown';
                    $this->stats->recordCompression($originalSize, $compressedSize, $type);
                }
            }
            
            $resource->save();
        }

        // Ne pas sauvegarder ni rafraîchir ici (fait en batch)

        return $this;
    }

    /**
     * @return ResourceInterface[]
     */
    public function getResourceTree(ResourceInterface $resource, array &$resourceList = []): array
    {
        if(in_array($resource, $resourceList, true)){
            return $resourceList;
        }

        $resourceList[] = $resource;

        if ($resource instanceof XmlResource) {
            foreach($resource->getResources() as $subResource){
                $this->getResourceTree($subResource, $resourceList);
            }
        }

        return $resourceList;
    }

    /**
     * Fill data to each slide.
     *
     * @param array|Closure $data
     *
     * @throws FileOpenException
     * @throws FileSaveException
     */
    public function template($data): PPTX
    {
        foreach ($this->getSlides() as $slide) {
            $slide->template($data);
        }

        $this->refreshSource();
        return $this;
    }

    public function table(Closure $data, Closure $finder): PPTX
    {
        foreach ($this->getSlides() as $slide) {
            $slide->table($data, $finder);
        }

        $this->refreshSource();
        return $this;
    }

    /**
     * Update the images in the slide.
     *
     * @param mixed $data Closure or array which returns: key should match the descr attribute, value is the raw content of the image.
     */
    public function images($data): PPTX
    {
        foreach ($this->getSlides() as $slide) {
            $slide->images($data);
        }

        return $this;
    }

    /**
     * Save.
     *
     * @param $target
     *
     * @throws FileSaveException
     * @throws Exception
     */
    public function saveAs($target): void
    {
        $this->close();

        if (!copy($this->tmpName, $target)) {
            throw new FileSaveException('Unable to save the final PPTX. Error during the copying.');
        }

        $this->openFile($this->tmpName);
    }

    /**
     * Overwrites the open file with the news.
     *
     * @throws Exception
     */
    public function save(): void
    {
        $this->saveAs($this->filename);
    }

    /**
     * @throws FileSaveException
     */
    public function __destruct()
    {
        $this->close();
        unlink($this->tmpName);
    }

    /**
     * @throws FileSaveException
     */
    protected function close(): void
    {
        if (!@$this->archive->close()) {
            throw new FileSaveException('Unable to close the source PPTX.');
        }
    }

    public function getArchive(): ZipArchive
    {
        return $this->archive;
    }

    /**
     * @return ContentType
     */
    public function getContentType(): ContentType
    {
        return $this->contentType;
    }

    /**
     * Retourne la configuration d'optimisation
     *
     * @return OptimizationConfig
     */
    public function getConfig(): OptimizationConfig
    {
        return $this->config;
    }

    /**
     * Retourne le cache d'images
     *
     * @return ImageCache
     */
    public function getImageCache(): ImageCache
    {
        return $this->imageCache;
    }

    /**
     * Retourne les statistiques d'optimisation
     *
     * @return array
     */
    public function getOptimizationStats(): array
    {
        $stats = $this->stats->getReport();
        $cacheStats = $this->imageCache->getStats();
        
        return array_merge($stats, [
            'cache_stats' => $cacheStats,
        ]);
    }

    /**
     * Retourne un résumé des optimisations
     *
     * @return string
     */
    public function getOptimizationSummary(): string
    {
        return $this->stats->getSummary();
    }

    /**
     * Valide la présentation (slides et ressources)
     *
     * @return array Rapport de validation
     */
    public function validate(): array
    {
        if (!$this->validator) {
            $this->validator = new PresentationValidator($this->config);
        }

        $resources = [];
        foreach ($this->slides as $slide) {
            $resources = array_merge($resources, array_values($slide->getResources()));
        }

        return $this->validator->validatePresentation($this->slides, $resources);
    }

    /**
     * Valide uniquement les images
     *
     * @return array Rapport de validation des images
     */
    public function validateImages(): array
    {
        $imageValidator = new ImageValidator($this->config);
        $report = [
            'total' => 0,
            'valid' => 0,
            'invalid' => 0,
            'details' => [],
        ];

        foreach ($this->slides as $slide) {
            foreach ($slide->getResources() as $resource) {
                if ($resource instanceof Image) {
                    $report['total']++;
                    try {
                        $content = $resource->getContent();
                        $imageReport = $imageValidator->validateWithReport($content);
                        
                        $report['details'][$resource->getTarget()] = $imageReport;
                        
                        if ($imageReport['valid']) {
                            $report['valid']++;
                        } else {
                            $report['invalid']++;
                        }
                    } catch (\Exception $e) {
                        $report['invalid']++;
                        $report['details'][$resource->getTarget()] = [
                            'valid' => false,
                            'errors' => [$e->getMessage()],
                        ];
                    }
                }
            }
        }

        return $report;
    }
}
