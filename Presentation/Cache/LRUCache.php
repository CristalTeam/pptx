<?php

namespace Cristal\Presentation\Cache;

class LRUCache
{
    /**
     * @var int Taille maximale du cache
     */
    private $maxSize;

    /**
     * @var array Cache des éléments [clé => valeur]
     */
    private $cache = [];

    /**
     * @var array Ordre d'accès [clé => timestamp]
     */
    private $order = [];

    /**
     * @var int Compteur pour l'ordre d'accès
     */
    private $counter = 0;

    /**
     * @var int Nombre d'évictions effectuées
     */
    private $evictions = 0;

    /**
     * @var int Nombre de hits
     */
    private $hits = 0;

    /**
     * @var int Nombre de miss
     */
    private $misses = 0;

    /**
     * LRUCache constructor.
     *
     * @param int $maxSize Taille maximale du cache
     */
    public function __construct(int $maxSize = 100)
    {
        $this->maxSize = $maxSize;
    }

    /**
     * Récupère une valeur du cache
     *
     * @param string $key Clé à récupérer
     * @return mixed|null Valeur ou null si non trouvée
     */
    public function get(string $key)
    {
        if (!isset($this->cache[$key])) {
            $this->misses++;
            return null;
        }

        // Mettre à jour l'ordre d'accès (marquer comme récemment utilisé)
        $this->order[$key] = ++$this->counter;
        $this->hits++;

        return $this->cache[$key];
    }

    /**
     * Ajoute ou met à jour une valeur dans le cache
     *
     * @param string $key Clé
     * @param mixed $value Valeur
     */
    public function set(string $key, $value): void
    {
        // Si la clé existe déjà, mettre à jour
        if (isset($this->cache[$key])) {
            $this->cache[$key] = $value;
            $this->order[$key] = ++$this->counter;
            return;
        }

        // Si le cache est plein, évincer le plus ancien
        if (count($this->cache) >= $this->maxSize) {
            $this->evict();
        }

        // Ajouter le nouvel élément
        $this->cache[$key] = $value;
        $this->order[$key] = ++$this->counter;
    }

    /**
     * Évince l'élément le moins récemment utilisé
     */
    private function evict(): void
    {
        if (empty($this->order)) {
            return;
        }

        // Trouver la clé avec le plus petit timestamp
        $oldestKey = array_search(min($this->order), $this->order);

        unset($this->cache[$oldestKey]);
        unset($this->order[$oldestKey]);
        $this->evictions++;
    }

    /**
     * Vérifie si une clé existe dans le cache
     *
     * @param string $key Clé à vérifier
     * @return bool
     */
    public function has(string $key): bool
    {
        return isset($this->cache[$key]);
    }

    /**
     * Supprime une clé du cache
     *
     * @param string $key Clé à supprimer
     */
    public function delete(string $key): void
    {
        unset($this->cache[$key]);
        unset($this->order[$key]);
    }

    /**
     * Vide complètement le cache
     */
    public function clear(): void
    {
        $this->cache = [];
        $this->order = [];
        $this->counter = 0;
        $this->evictions = 0;
        $this->hits = 0;
        $this->misses = 0;
    }

    /**
     * Retourne le nombre d'éléments dans le cache
     *
     * @return int
     */
    public function count(): int
    {
        return count($this->cache);
    }

    /**
     * Retourne la taille maximale du cache
     *
     * @return int
     */
    public function getMaxSize(): int
    {
        return $this->maxSize;
    }

    /**
     * Modifie la taille maximale du cache
     *
     * @param int $maxSize Nouvelle taille max
     */
    public function setMaxSize(int $maxSize): void
    {
        $this->maxSize = $maxSize;

        // Évincer si nécessaire pour respecter la nouvelle taille
        while (count($this->cache) > $this->maxSize) {
            $this->evict();
        }
    }

    /**
     * Retourne les statistiques du cache
     *
     * @return array
     */
    public function getStats(): array
    {
        $total = $this->hits + $this->misses;
        $hitRate = $total > 0 ? ($this->hits / $total) * 100 : 0;

        return [
            'size' => count($this->cache),
            'max_size' => $this->maxSize,
            'hits' => $this->hits,
            'misses' => $this->misses,
            'hit_rate' => round($hitRate, 2),
            'evictions' => $this->evictions,
            'usage_percent' => round((count($this->cache) / $this->maxSize) * 100, 2),
        ];
    }

    /**
     * Retourne toutes les clés du cache
     *
     * @return array
     */
    public function keys(): array
    {
        return array_keys($this->cache);
    }

    /**
     * Retourne toutes les valeurs du cache
     *
     * @return array
     */
    public function values(): array
    {
        return array_values($this->cache);
    }

    /**
     * Retourne un tableau associatif de tout le cache
     *
     * @return array
     */
    public function all(): array
    {
        return $this->cache;
    }
}