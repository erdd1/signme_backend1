<?php

namespace App\Core\Services;

use App\Core\Exceptions\ApiException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;

/**
 * Service de gestion des médias — stockage local (disque public Laravel,
 * servi directement par nginx).
 *
 * Centralise tous les uploads/suppressions d'images de l'application.
 * Remplace l'ancien CloudinaryService : les fichiers sont désormais
 * redimensionnés/compressés côté serveur avec Intervention Image (driver GD)
 * puis enregistrés sur `storage/app/public/...` (symlinké en `public/storage`),
 * au lieu d'être envoyés à un service tiers.
 *
 * @package App\Core\Services
 */
class ImageStorageService
{
    private const DIR_PROFILES = 'profiles';
    private const DIR_EVENEMENTS = 'evenements';
    private const DIR_ABOUT_CHURCH = 'about_church';

    private readonly ImageManager $manager;

    public function __construct()
    {
        $this->manager = new ImageManager(new Driver());
    }

    /**
     * Enregistre une photo de profil utilisateur.
     *
     * Recadrée en carré 512×512 centré (Cloudinary faisait un recadrage centré
     * sur le visage via détection IA — non reproduit ici, recadrage centré
     * classique en remplacement).
     *
     * @param UploadedFile $file     Fichier image uploadé
     * @param string       $filename Nom de fichier unique (ex: user_{id}_timestamp), sans extension
     * @return string URL publique de l'image enregistrée
     * @throws ApiException si l'enregistrement échoue
     */
    public function uploadProfilePhoto(UploadedFile $file, string $filename): string
    {
        return $this->processAndStore(
            $file,
            self::DIR_PROFILES,
            $filename,
            fn ($image) => $image->cover(512, 512),
            'de la photo',
        );
    }

    /**
     * Enregistre une image d'événement (module collecte de fonds).
     *
     * Contrairement à la photo de profil, on ne recadre pas : on garde le
     * cadrage d'origine et on limite juste la largeur/le poids.
     *
     * @param UploadedFile $file     Fichier image uploadé
     * @param string       $filename Nom de fichier unique (ex: evenement_{uuid}), sans extension
     * @return string URL publique de l'image enregistrée
     * @throws ApiException si l'enregistrement échoue
     */
    public function uploadEvenementImage(UploadedFile $file, string $filename): string
    {
        return $this->processAndStore(
            $file,
            self::DIR_EVENEMENTS,
            $filename,
            fn ($image) => $image->scaleDown(width: 1024),
            "de l'image de l'événement",
        );
    }

    /**
     * Enregistre une photo d'article "À propos de mon Église".
     *
     * Même stratégie que les images d'événement (pas de recadrage, largeur
     * limitée) : ces photos sont éditoriales (pasteurs, groupes, activités),
     * pas des portraits de profil.
     *
     * @param UploadedFile $file     Fichier image uploadé
     * @param string       $filename Nom de fichier unique (ex: about_church_{uuid}_{index}), sans extension
     * @return string URL publique de l'image enregistrée
     * @throws ApiException si l'enregistrement échoue
     */
    public function uploadAboutChurchArticleImage(UploadedFile $file, string $filename): string
    {
        return $this->processAndStore(
            $file,
            self::DIR_ABOUT_CHURCH,
            $filename,
            fn ($image) => $image->scaleDown(width: 1024),
            "de l'image de l'article",
        );
    }

    /**
     * Traite (redimensionne/compresse) et enregistre une image sur le disque
     * public, partagé par les trois méthodes d'upload ci-dessus — seule la
     * transformation (recadrage vs redimensionnement simple) diffère.
     */
    private function processAndStore(
        UploadedFile $file,
        string $directory,
        string $filename,
        callable $transform,
        string $context,
    ): string {
        $path = $file->getRealPath();
        if ($path === false || !is_readable($path)) {
            throw ApiException::badRequest('Fichier image illisible ou introuvable.');
        }

        try {
            $image = $this->manager->read($path);
            $transform($image);
            $encoded = $image->toJpeg(quality: 85);

            $relativePath = "{$directory}/{$filename}.jpg";

            // Le disque 'public' est configuré avec 'throw' => false (voir
            // config/filesystems.php) : un échec d'écriture (permissions,
            // disque plein...) ne lève PAS d'exception, put() retourne
            // simplement false. Sans cette vérification explicite, un échec
            // silencieux produisait quand même une URL "valide" en réponse —
            // l'utilisateur voyait "photo mise à jour" alors qu'aucun
            // fichier n'avait réellement été enregistré.
            if (Storage::disk('public')->put($relativePath, (string) $encoded) === false) {
                throw new ApiException(
                    "Échec de l'enregistrement {$context} sur le disque (vérifiez les permissions du dossier de stockage).",
                    500
                );
            }

            return $this->ensureHttpsUrl(Storage::disk('public')->url($relativePath));
        } catch (ApiException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ApiException(
                "Erreur lors de l'enregistrement {$context} : " . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Génère un nom de fichier unique pour une image d'événement.
     */
    public function generateEvenementFilename(): string
    {
        return 'evenement_' . (string) Str::uuid();
    }

    /**
     * Génère un nom de fichier unique pour une image d'article "À propos de
     * mon Église". $index distingue les jusqu'à 3 photos d'un même article.
     *
     * Le suffixe temporel est indispensable : AboutChurchArticleService::update()
     * enregistre les nouvelles photos AVANT de supprimer les anciennes (pour ne
     * jamais perdre d'image si la sauvegarde échoue). Sans ce suffixe, un
     * remplacement de photo au même index réutiliserait le même nom de
     * fichier, et la suppression de "l'ancienne" image après coup supprimerait
     * en réalité celle qui vient d'être enregistrée.
     */
    public function generateAboutChurchArticleFilename(string $articleUuid, int $index): string
    {
        return "about_church_{$articleUuid}_{$index}_" . now()->getTimestampMs();
    }

    /**
     * Génère un nom de fichier unique pour un utilisateur.
     */
    public function generateUserFilename(int $userId): string
    {
        return "user_{$userId}_" . time();
    }

    private function ensureHttpsUrl(string $url): string
    {
        return str_starts_with($url, 'http://')
            ? 'https://' . substr($url, 7)
            : $url;
    }

    /**
     * Supprime une image enregistrée localement par son URL publique.
     * N'échoue jamais bruyamment : une image déjà absente ou une URL
     * inattendue est simplement journalisée (même contrat que l'ancien
     * CloudinaryService, dont les appelants comptent sur ce comportement).
     *
     * @param string $url URL publique complète de l'image
     */
    public function deleteByUrl(string $url): void
    {
        try {
            $path = $this->extractPathFromUrl($url);
            if ($path !== null && Storage::disk('public')->exists($path)) {
                Storage::disk('public')->delete($path);
            }
        } catch (\Throwable $e) {
            \Log::warning("Impossible de supprimer l'image locale : " . $e->getMessage());
        }
    }

    /**
     * Extrait le chemin relatif (sur le disque `public`) depuis une URL
     * publique générée par `Storage::disk('public')->url(...)`.
     */
    private function extractPathFromUrl(string $url): ?string
    {
        $marker = '/storage/';
        $position = strpos($url, $marker);
        if ($position === false) {
            return null;
        }

        return substr($url, $position + strlen($marker));
    }

    /**
     * Vérifie si un fichier est une image valide — se base UNIQUEMENT sur le
     * type MIME détecté côté serveur (contenu réel du fichier), jamais sur
     * l'extension fournie par le client (attaquant-contrôlée, ne prouve rien
     * sur le contenu réel).
     *
     * @param UploadedFile $file
     * @return bool
     */
    public static function isValidImage(UploadedFile $file): bool
    {
        $allowedMimes = [
            'image/jpeg',
            'image/jpg',
            'image/png',
            'image/webp',
            'image/gif',
        ];

        $mime = $file->getMimeType();

        return $mime !== null && in_array($mime, $allowedMimes, true);
    }
}
