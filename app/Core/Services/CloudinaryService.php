<?php

namespace App\Core\Services;

use Cloudinary\Api\ApiResponse;
use Cloudinary\Cloudinary;
use Illuminate\Http\UploadedFile;
use App\Core\Exceptions\ApiException;

/**
 * Service de gestion des médias avec Cloudinary.
 *
 * Centralise tous les uploads/suppressions de photos dans l'application.
 * Utilisé principalement pour les photos de profil des paroissiens/membres.
 *
 * @package App\Core\Services
 */
class CloudinaryService
{
    private const FOLDER_PROFILES = 'eec_signcard/profiles';

    public function __construct(
        private readonly Cloudinary $cloudinary,
    ) {}

    /**
     * Upload une photo de profil utilisateur sur Cloudinary.
     *
     * @param UploadedFile $file       Fichier image uploadé
     * @param string       $publicId   Identifiant unique (ex: user_{id}_timestamp)
     * @return string URL sécurisée Cloudinary
     * @throws ApiException si l'upload échoue
     */
    public function uploadProfilePhoto(UploadedFile $file, string $publicId): string
    {
        $this->assertCloudinaryConfigured();

        $path = $file->getRealPath();
        if ($path === false || !is_readable($path)) {
            throw ApiException::badRequest('Fichier image illisible ou introuvable.');
        }

        try {
            /** @var ApiResponse $response */
            $response = $this->cloudinary->uploadApi()->upload($path, [
                'folder'        => self::FOLDER_PROFILES,
                'public_id'     => $publicId,
                'overwrite'     => true,
                'resource_type' => 'image',
                'width'         => 512,
                'height'        => 512,
                'crop'          => 'fill',
                'gravity'       => 'face',
                'quality'       => 'auto:good',
                'format'        => 'jpg',
            ]);

            $secureUrl = $this->resolveSecureUrl($response);
            if ($secureUrl === null || $secureUrl === '') {
                throw new ApiException(
                    'Upload Cloudinary sans URL de retour. Vérifiez les clés API sur le dashboard Cloudinary.',
                    500
                );
            }

            return $this->ensureHttpsUrl($secureUrl);
        } catch (ApiException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ApiException(
                'Erreur lors de l\'upload de la photo : ' . $e->getMessage(),
                500
            );
        }
    }

    private function assertCloudinaryConfigured(): void
    {
        $cloudName = $this->cloudinary->configuration->cloud->cloudName ?? null;
        if (empty($cloudName)) {
            throw new ApiException(
                'Cloudinary non configuré. Définissez CLOUDINARY_API_KEY, CLOUDINARY_API_SECRET et CLOUDINARY_CLOUD_NAME dans .env.',
                500
            );
        }
    }

    private function ensureHttpsUrl(string $url): string
    {
        return str_starts_with($url, 'http://')
            ? 'https://' . substr($url, 7)
            : $url;
    }

    /**
     * @param ApiResponse|array<string, mixed>|object $result
     */
    private function resolveSecureUrl(mixed $result): ?string
    {
        if ($result instanceof ApiResponse) {
            return $result['secure_url'] ?? $result['url'] ?? null;
        }

        if (is_array($result)) {
            return $result['secure_url'] ?? $result['url'] ?? null;
        }

        if (is_object($result) && method_exists($result, 'getArrayCopy')) {
            $array = $result->getArrayCopy();

            return $array['secure_url'] ?? $array['url'] ?? null;
        }

        return null;
    }

    /**
     * Supprime une image Cloudinary par son URL.
     *
     * @param string $photoUrl URL complète de la photo Cloudinary
     */
    public function deleteByUrl(string $photoUrl): void
    {
        try {
            $publicId = $this->extractPublicIdFromUrl($photoUrl);
            if ($publicId) {
                $this->cloudinary->uploadApi()->destroy($publicId, [
                    'resource_type' => 'image',
                ]);
            }
        } catch (\Throwable $e) {
            \Log::warning('Impossible de supprimer la photo Cloudinary : ' . $e->getMessage());
        }
    }

    /**
     * Supprime une image Cloudinary par son public_id.
     *
     * @param string $publicId Public ID Cloudinary
     */
    public function deleteByPublicId(string $publicId): void
    {
        try {
            $this->cloudinary->uploadApi()->destroy($publicId, [
                'resource_type' => 'image',
            ]);
        } catch (\Throwable $e) {
            \Log::warning('Impossible de supprimer la photo Cloudinary : ' . $e->getMessage());
        }
    }

    /**
     * Extrait le public_id depuis une URL Cloudinary.
     *
     * @param string $url URL Cloudinary complète
     * @return string|null
     */
    private function extractPublicIdFromUrl(string $url): ?string
    {
        if (!str_contains($url, 'cloudinary.com')) {
            return null;
        }

        $pattern = '/\/upload\/(?:v\d+\/)?(.+?)(?:\.[a-z]+)?$/i';
        if (preg_match($pattern, $url, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Génère un public_id unique pour un utilisateur.
     *
     * @param int $userId ID de l'utilisateur
     * @return string
     */
    public function generateUserPublicId(int $userId): string
    {
        return "user_{$userId}_" . time();
    }

    /**
     * Vérifie si un fichier est une image valide (MIME ou extension, utile sur mobile).
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
        if ($mime !== null && in_array($mime, $allowedMimes, true)) {
            return true;
        }

        $extension = strtolower($file->getClientOriginalExtension());

        return in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true);
    }
}
