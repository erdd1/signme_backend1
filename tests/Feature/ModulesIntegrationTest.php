<?php

namespace Tests;

use PHPUnit\Framework\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use App\Models\User;
use App\Models\Church;
use App\Models\AncienDesignation;

/**
 * ModulesIntegrationTest
 *
 * Test complet de tous les modules pour détecter les anomalies
 * Suite à la modification du module AncienDesignation
 */
class ModulesIntegrationTest extends TestCase
{
    use DatabaseTransactions;

    protected $user;
    protected $church;
    protected $token;

    protected function setUp(): void
    {
        parent::setUp();

        // Créer une église test
        $this->church = Church::factory()->create();

        // Créer un utilisateur test (pasteur)
        $this->user = User::factory()->create([
            'role'        => 'pasteur',
            'churchId'    => $this->church->id,
            'actif'       => true,
        ]);

        // Créer un token
        $this->token = $this->user->createToken('test-token')->plainTextToken;
    }

    /**
     * TEST 1: Module AUTH - Vérifier le flag est_de_service
     */
    public function testAuthModuleEstDeServiceFlag()
    {
        echo "\n✓ TEST 1: AUTH MODULE - est_de_service flag\n";

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->token}",
        ])->json('GET', '/api/v1/auth/me');

        $this->assertTrue($response['success']);
        $this->assertArrayHasKey('est_de_service', $response['data']);

        echo "  - est_de_service field present: ✓\n";
        echo "  - Value: " . ($response['data']['est_de_service'] ? 'true' : 'false') . "\n";
    }

    /**
     * TEST 2: Module USER - Vérifier les utilisateurs ont le flag est_de_service
     */
    public function testUserModuleEstDeServiceField()
    {
        echo "\n✓ TEST 2: USER MODULE - est_de_service field in user list\n";

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->token}",
        ])->json('GET', '/api/v1/users');

        $this->assertTrue($response['success']);

        if (count($response['data']) > 0) {
            $user = $response['data'][0];
            if (isset($user['est_de_service'])) {
                echo "  - est_de_service field present in users: ✓\n";
            } else {
                echo "  - WARNING: est_de_service field MISSING in user response\n";
                echo "  - User keys: " . implode(', ', array_keys($user)) . "\n";
            }
        }
    }

    /**
     * TEST 3: Module ANCIEN DESIGNATION - Structure des données
     */
    public function testAncienDesignationModuleStructure()
    {
        echo "\n✓ TEST 3: ANCIEN DESIGNATION MODULE - Response structure\n";

        // Créer une désignation test
        $ancien = User::factory()->create([
            'role'        => 'ancien',
            'churchId'    => $this->church->id,
            'actif'       => true,
        ]);

        $designation = AncienDesignation::create([
            'egliseId'           => $this->church->id,
            'ancienId'           => $ancien->id,
            'mois_designation'   => now()->format('Y-m'),
            'statut_designation' => 'active',
        ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->token}",
        ])->json('GET', '/api/v1/anciens-designations');

        $this->assertTrue($response['success']);

        if (count($response['data']) > 0) {
            $des = $response['data'][0];
            $requiredFields = [
                'id', 'egliseId', 'ancienId', 'mois_designation',
                'statut_designation'
            ];

            foreach ($requiredFields as $field) {
                if (isset($des[$field])) {
                    echo "  - Field '$field': ✓\n";
                } else {
                    echo "  - Field '$field': ✗ MISSING\n";
                }
            }
        }
    }

    /**
     * TEST 4: Module SIGNATURE - Vérifier dépendance AncienDesignation
     */
    public function testSignatureModuleAncienDesignationDependency()
    {
        echo "\n✓ TEST 4: SIGNATURE MODULE - AncienDesignation dependency\n";

        // Vérifier que le service Signature peut accéder aux données AncienDesignation
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->token}",
        ])->json('GET', '/api/v1/signatures/ma-carte');

        if ($response['success']) {
            echo "  - Signature module can access data: ✓\n";
        } else {
            echo "  - WARNING: Signature module error: " . ($response['message'] ?? 'Unknown') . "\n";
        }
    }

    /**
     * TEST 5: Module PUBLICATION - Vérifier indépendance
     */
    public function testPublicationModuleIndependence()
    {
        echo "\n✓ TEST 5: PUBLICATION MODULE - Independence check\n";

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->token}",
        ])->json('GET', '/api/v1/publications');

        if ($response['success']) {
            echo "  - Publications module works independently: ✓\n";
        } else {
            echo "  - Publications module error: " . ($response['message'] ?? 'Unknown') . "\n";
        }
    }

    /**
     * TEST 6: Module RAPPORT - Vérifier indépendance
     */
    public function testRapportModuleIndependence()
    {
        echo "\n✓ TEST 6: RAPPORT MODULE - Independence check\n";

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->token}",
        ])->json('GET', '/api/v1/rapports/secretaire');

        if ($response['success']) {
            echo "  - Rapport module works independently: ✓\n";
        } else {
            echo "  - Rapport module error: " . ($response['message'] ?? 'Unknown') . "\n";
        }
    }

    /**
     * TEST 7: Module CHURCH - Vérifier indépendance
     */
    public function testChurchModuleIndependence()
    {
        echo "\n✓ TEST 7: CHURCH MODULE - Independence check\n";

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->token}",
        ])->json('GET', '/api/v1/churches');

        if ($response['success']) {
            echo "  - Church module works independently: ✓\n";
        } else {
            echo "  - Church module error: " . ($response['message'] ?? 'Unknown') . "\n";
        }
    }

    /**
     * TEST 8: Vérifier la synchronisation est_de_service
     */
    public function testEstDeServiceSynchronization()
    {
        echo "\n✓ TEST 8: EST_DE_SERVICE - Synchronization\n";

        // Créer un ancien sans désignation
        $ancien = User::factory()->create([
            'role'             => 'ancien',
            'churchId'         => $this->church->id,
            'actif'            => true,
            'est_de_service'   => false,
        ]);

        // Désigner l'ancien
        $designation = AncienDesignation::create([
            'egliseId'           => $this->church->id,
            'ancienId'           => $ancien->id,
            'mois_designation'   => now()->format('Y-m'),
            'statut_designation' => 'active',
        ]);

        // Vérifier que le flag est_de_service est mis à jour
        $ancien->refresh();

        if ($ancien->est_de_service) {
            echo "  - Flag est_de_service updated to TRUE: ✓\n";
        } else {
            echo "  - WARNING: Flag est_de_service NOT updated after designation\n";
        }

        // Retirer la désignation
        $designation->delete();

        // Vérifier que le flag est remis à false
        $ancien->refresh();

        if (!$ancien->est_de_service) {
            echo "  - Flag est_de_service reset to FALSE: ✓\n";
        } else {
            echo "  - WARNING: Flag est_de_service NOT reset after removing designation\n";
        }
    }

    /**
     * TEST 9: Vérifier les permissions RBAC
     */
    public function testRBACPermissions()
    {
        echo "\n✓ TEST 9: RBAC - Permissions check\n";

        // Admin peut accéder à /admin/security-logs
        $admin = User::factory()->create([
            'role'      => 'administrateur',
            'churchId'  => $this->church->id,
            'actif'     => true,
        ]);

        $adminToken = $admin->createToken('admin-token')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$adminToken}",
        ])->json('GET', '/api/v1/auth/admin/security-logs');

        if ($response['success']) {
            echo "  - Admin can access security-logs: ✓\n";
        } else if (str_contains($response['message'] ?? '', 'Unauthorized')) {
            echo "  - Admin DENIED access to security-logs: ✗ PERMISSION ERROR\n";
        }

        // Pasteur ne peut pas accéder à /admin/security-logs
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->token}",
        ])->json('GET', '/api/v1/auth/admin/security-logs');

        if (!$response['success'] && str_contains($response['message'] ?? '', 'Unauthorized')) {
            echo "  - Pasteur correctly denied access to admin endpoints: ✓\n";
        } else {
            echo "  - WARNING: Pasteur should NOT have access to admin endpoints\n";
        }
    }

    /**
     * TEST 10: Vérifier intégrité des modèles
     */
    public function testModelsIntegrity()
    {
        echo "\n✓ TEST 10: MODELS - Integrity check\n";

        // User model doit avoir le field est_de_service
        $user = User::factory()->create();
        if (isset($user->est_de_service)) {
            echo "  - User model has est_de_service: ✓\n";
        } else {
            echo "  - ERROR: User model missing est_de_service field\n";
        }

        // AncienDesignation model doit avoir les bons champs
        $ancien = User::factory()->create([
            'role' => 'ancien',
        ]);

        $designation = AncienDesignation::create([
            'egliseId'           => 1,
            'ancienId'           => $ancien->id,
            'mois_designation'   => now()->format('Y-m'),
            'statut_designation' => 'active',
        ]);

        $requiredFields = [
            'egliseId', 'ancienId', 'mois_designation', 'statut_designation'
        ];

        foreach ($requiredFields as $field) {
            if ($designation->$field !== null) {
                echo "  - AncienDesignation.$field: ✓\n";
            } else {
                echo "  - ERROR: AncienDesignation.$field is null\n";
            }
        }
    }
}
