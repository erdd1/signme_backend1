<?php

namespace Tests\Feature\Broadcasting;

use App\Models\Church;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Régression — deux bugs cumulés qui empêchaient TOUT temps réel de
 * fonctionner :
 *
 * 1. config/broadcasting.php n'existait pas dans ce projet : sans lui,
 *    BROADCAST_CONNECTION (.env) n'est jamais lu et Laravel bascule
 *    silencieusement sur le driver "null" (aucun événement jamais diffusé,
 *    aucune autorisation de canal jamais vérifiée) — voir phpunit.xml pour
 *    l'activation du driver "reverb" en environnement de test.
 * 2. routes/channels.php autorisait le canal privé "church.{id}" via
 *    `$user->church_id` (snake_case) alors que le modèle User n'expose que
 *    `churchId` (camelCase) — cette comparaison valait donc toujours
 *    `0 === $churchId`, rejetant TOUT utilisateur réel.
 */
class ChurchChannelAuthTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Active réellement le driver "reverb" (PusherBroadcaster) UNIQUEMENT
     * pour cette classe de test — jamais globalement dans phpunit.xml, ce
     * qui a fait échouer en cascade des dizaines de tests sans rapport
     * (toute action déclenchant un ShouldBroadcastNow essayait alors de
     * contacter un vrai serveur Reverb inexistant). authorizeChannel() est
     * un calcul de signature 100% local (HMAC), aucun réseau requis ici.
     */
    public function createApplication()
    {
        putenv('BROADCAST_CONNECTION=reverb');
        putenv('REVERB_APP_ID=testing_app');
        putenv('REVERB_APP_KEY=testing_key');
        putenv('REVERB_APP_SECRET=testing_secret');
        putenv('REVERB_HOST=localhost');
        putenv('REVERB_PORT=8080');
        putenv('REVERB_SCHEME=http');
        $_ENV['BROADCAST_CONNECTION'] = 'reverb';
        $_ENV['REVERB_APP_ID'] = 'testing_app';
        $_ENV['REVERB_APP_KEY'] = 'testing_key';
        $_ENV['REVERB_APP_SECRET'] = 'testing_secret';
        $_ENV['REVERB_HOST'] = 'localhost';
        $_ENV['REVERB_PORT'] = '8080';
        $_ENV['REVERB_SCHEME'] = 'http';

        return parent::createApplication();
    }

    /**
     * putenv()/$_ENV persistent tant que le processus PHPUnit tourne (le
     * chargement .env de Laravel est IMMUTABLE : il n'écrase jamais une
     * valeur déjà positionnée) — sans ce nettoyage, ces variables de test
     * resteraient actives pour TOUTES les classes de test suivantes dans la
     * même exécution `php artisan test`.
     */
    protected function tearDown(): void
    {
        foreach (['BROADCAST_CONNECTION', 'REVERB_APP_ID', 'REVERB_APP_KEY', 'REVERB_APP_SECRET', 'REVERB_HOST', 'REVERB_PORT', 'REVERB_SCHEME'] as $key) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }

        parent::tearDown();
    }

    private function church(): Church
    {
        return Church::factory()->create();
    }

    public function test_member_is_authorized_on_own_church_channel(): void
    {
        $church = $this->church();
        $user = User::factory()->create(['role' => 'paroissien', 'churchId' => $church->id, 'actif' => true]);
        Sanctum::actingAs($user);

        $this->postJson('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => "private-church.{$church->id}",
        ])->assertStatus(200);
    }

    public function test_member_is_forbidden_on_another_churchs_channel(): void
    {
        $church1 = $this->church();
        $church2 = $this->church();
        $user = User::factory()->create(['role' => 'paroissien', 'churchId' => $church1->id, 'actif' => true]);
        Sanctum::actingAs($user);

        $this->postJson('/broadcasting/auth', [
            'channel_name' => "private-church.{$church2->id}",
        ])->assertStatus(403);
    }

    public function test_admin_is_authorized_on_any_churchs_channel(): void
    {
        $church = $this->church();
        $admin = User::factory()->admin()->create(['churchId' => null, 'actif' => true]);
        Sanctum::actingAs($admin);

        $this->postJson('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => "private-church.{$church->id}",
        ])->assertStatus(200);
    }

    public function test_member_is_authorized_on_own_personal_channel(): void
    {
        $user = User::factory()->create(['role' => 'paroissien', 'churchId' => $this->church()->id, 'actif' => true]);
        Sanctum::actingAs($user);

        $this->postJson('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => "private-user.{$user->id}",
        ])->assertStatus(200);
    }

    public function test_member_is_forbidden_on_another_users_personal_channel(): void
    {
        $church = $this->church();
        $user = User::factory()->create(['role' => 'paroissien', 'churchId' => $church->id, 'actif' => true]);
        $other = User::factory()->create(['role' => 'paroissien', 'churchId' => $church->id, 'actif' => true]);
        Sanctum::actingAs($user);

        $this->postJson('/broadcasting/auth', [
            'channel_name' => "private-user.{$other->id}",
        ])->assertStatus(403);
    }
}
