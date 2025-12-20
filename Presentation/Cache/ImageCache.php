<?php

namespace Cristal\Presentation\Cache;

use Cristal\Presentation\Resource\Image;

class ImageCache
{
    /**
     * @var array Cache des hashes d'images
     */
    private $cache = [];

    /**
     * @var int Nombre de doublons détectés
     */
    private $duplicatesFound = 0;

    /**
     * Calcule un hash rapide basé sur les premiers et derniers octets
     * Plus rapide que md5() sur l'intégralité du contenu
     *
     * @param string $content Contenu de l'image
     * @return string Hash rapide
     */
    public function fastHash(string $content): string
    {
        $length = strlen($content);
        
        // Pour les petits fichiers, hash complet
        if ($length < 16384) {
            return md5($content);
        }
        
        // Pour les gros fichiers, hash partiel (premiers 8KB + derniers 8KB + taille)
        $start = substr($content, 0, 8192);
        $end = substr($content, -8192);
        
        return md5($start . $end . $length);
    }

    /**
     * Cherche une image dupliquée dans le cache
     *
     * @param string $content Contenu de l'image
     * @return Image|null Image existante si trouvée, null sinon
     */
    public function findDuplicate(string $content): ?Image
    {
        $hash = $this->fastHash($content);
        
        if (isset($this->cache[$hash])) {
            $this->duplicatesFound++;
            return $this->cache[$hash];
        }
        
        return null;
    }

    /**
     * Enregistre une image dans le cache
     *
     * @param string $hash Hash de l'image
     * @param Image $image Instance de l'image
     */
    public function register(string $hash, Image $image): void
    {
        $this->cache[$hash] = $image;
    }

    /**
     * Enregistre une image avec calcul automatique du hash
     *
     * @param string $content Contenu de l'image
     * @param Image $image Instance de l'image
     * @return string Hash calculé
     */
    public function registerWithContent(string $content, Image $image): string
    {
        $hash = $this->fastHash($content);
        $this->register($hash, $image);
        return $hash;
    }

    /**
     * Vérifie si un hash existe dans le cache
     *
     * @param string $hash Hash à vérifier
     * @return bool
     */
    public function has(string $hash): bool
    {
        return isset($this->cache[$hash]);
    }

    /**
     * Récupère une image depuis le cache
     *
     * @param string $hash Hash de l'image
     * @return Image|null
     */
    public function get(string $hash): ?Image
    {
        return $this->cache[$hash] ?? null;
    }

    /**
     * Vide le cache
     */
    public function clear(): void
    {
        $this->cache = [];
        $this->duplicatesFound = 0;
    }

    /**
     * Retourne le nombre d'images en cache
     *
     * @return int
     */
    public function count(): int
    {
        return count($this->cache);
    }

    /**
     * Retourne le nombre de doublons détectés
     *
     * @return int
     */
    public function getDuplicatesFound(): int
    {
        return $this->duplicatesFound;
    }

    /**
     * Retourne des statistiques sur le cache
     *
     * @return array
     */
    public function getStats(): array
    {
        return [
            'cached_images' => $this->count(),
            'duplicates_found' => $this->duplicatesFound,
            'memory_keys' => count($this->cache),
        ];
    }
}