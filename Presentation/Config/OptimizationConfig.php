<?php

namespace Cristal\Presentation\Config;

class OptimizationConfig
{
    /**
     * Options par défaut pour les optimisations
     */
    private const DEFAULTS = [
        // Optimisation des images
        'image_compression' => false,
        'image_quality' => 85,
        'max_image_width' => 1920,
        'max_image_height' => 1080,
        'convert_to_webp' => false,
        
        // Performance
        'lazy_loading' => true,              // Activé par défaut pour meilleures performances
        'cache_size' => 100,                 // Taille du cache LRU
        'deduplicate_images' => false,
        'use_lru_cache' => true,            // Utiliser cache LRU au lieu d'un tableau simple
        
        // Batch processing
        'batch_size' => 50,                  // Taille de batch recommandée
        
        // Validation
        'validate_images' => false,
        'max_image_size' => 10 * 1024 * 1024, // 10MB
        
        // Debug
        'collect_stats' => false,
    ];

    /**
     * @var array
     */
    private $options;

    /**
     * OptimizationConfig constructor.
     *
     * @param array $options Options de configuration
     */
    public function __construct(array $options = [])
    {
        $this->options = array_merge(self::DEFAULTS, $options);
        $this->validate();
    }

    /**
     * Valide les options de configuration
     *
     * @throws \InvalidArgumentException
     */
    private function validate(): void
    {
        if ($this->options['image_quality'] < 1 || $this->options['image_quality'] > 100) {
            throw new \InvalidArgumentException('image_quality doit être entre 1 et 100');
        }

        if ($this->options['max_image_width'] < 1 || $this->options['max_image_height'] < 1) {
            throw new \InvalidArgumentException('Les dimensions max doivent être positives');
        }

        if ($this->options['cache_size'] < 1) {
            throw new \InvalidArgumentException('cache_size doit être au moins 1');
        }

        if ($this->options['max_image_size'] < 1) {
            throw new \InvalidArgumentException('max_image_size doit être positif');
        }
    }

    /**
     * Récupère une option de configuration
     *
     * @param string $key Clé de l'option
     * @return mixed Valeur de l'option
     */
    public function get(string $key)
    {
        return $this->options[$key] ?? null;
    }

    /**
     * Définit une option de configuration
     *
     * @param string $key Clé de l'option
     * @param mixed $value Valeur de l'option
     */
    public function set(string $key, $value): void
    {
        $this->options[$key] = $value;
        $this->validate();
    }

    /**
     * Vérifie si une option est activée
     *
     * @param string $key Clé de l'option
     * @return bool
     */
    public function isEnabled(string $key): bool
    {
        return (bool) $this->get($key);
    }

    /**
     * Retourne toutes les options
     *
     * @return array
     */
    public function all(): array
    {
        return $this->options;
    }

    /**
     * Active les optimisations par défaut
     */
    public function enableOptimizations(): void
    {
        $this->options['image_compression'] = true;
        $this->options['deduplicate_images'] = true;
        $this->options['lazy_loading'] = true;
        $this->options['collect_stats'] = true;
    }
}