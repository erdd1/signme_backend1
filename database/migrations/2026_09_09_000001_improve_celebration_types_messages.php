<?php

use App\Models\CelebrationType;
use Illuminate\Database\Migrations\Migration;

/**
 * Remplace les messages initiaux (trop courts/génériques) par des textes
 * plus chaleureux et personnels, et ajoute le placeholder {annee} pour le
 * Nouvel An — voir CelebrationType::renderMessage(). Migration de données
 * séparée de la seed initiale car celle-ci a déjà été exécutée en
 * production (`updateOrCreate` sur `key`, donc rejouable sans risque).
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach ($this->messages() as $key => $message) {
            CelebrationType::where('key', $key)->update([
                'titre' => $message['titre'],
                'messageTemplate' => $message['messageTemplate'],
            ]);
        }
    }

    public function down(): void
    {
        // Pas de retour arrière : les anciens messages n'ont pas vocation à
        // être restaurés (contenu éditorial, pas un schéma).
    }

    private function messages(): array
    {
        return [
            CelebrationType::KEY_ANNIVERSAIRE => [
                'titre' => 'Joyeux anniversaire 🎉',
                'messageTemplate' => "Bonjour {nom}, quel jour merveilleux ! Toute la famille {eglise} se joint à moi pour célébrer votre anniversaire avec une immense joie. Que cette nouvelle année de vie soit remplie de santé, de force, de succès dans tout ce que vous entreprendrez, et surtout d'une bénédiction débordante. Merci d'être une personne aussi précieuse pour notre communauté. Très joyeux anniversaire !",
            ],
            CelebrationType::KEY_NOUVEL_AN => [
                'titre' => 'Bonne année 🎆',
                'messageTemplate' => "Bonjour {nom}, alors que l'année {annee} commence, toute la famille {eglise} tient à vous exprimer sa gratitude de vous compter parmi nous. Que cette nouvelle année vous apporte une santé solide, une paix profonde, des projets qui aboutissent et des relations qui vous élèvent. Nous croyons que {annee} sera une année de grâce et de progrès pour vous et vos proches. Excellente et heureuse année {annee} !",
            ],
            CelebrationType::KEY_NOEL => [
                'titre' => 'Joyeux Noël 🎄',
                'messageTemplate' => "Bonjour {nom}, en ce jour de Noël si particulier, toute la famille {eglise} pense à vous avec beaucoup d'affection. Que la lumière de cette fête illumine votre foyer, que la paix habite votre cœur et que l'amour de vos proches vous entoure aujourd'hui et tout au long de l'année. Merci d'être une part importante de notre communauté. Joyeux Noël et que cette période vous comble !",
            ],
            CelebrationType::KEY_SAINT_VALENTIN => [
                'titre' => 'Bonne Saint-Valentin 💝',
                'messageTemplate' => "Bonjour {nom}, en ce jour dédié à l'amour, toute la famille {eglise} tient à vous rappeler combien vous êtes une personne précieuse et aimée. Que cette journée vous apporte de la douceur, de belles attentions, et vous rappelle la valeur des liens qui vous entourent. Très belle Saint-Valentin, remplie de tendresse !",
            ],
            CelebrationType::KEY_JOURNEE_FEMME => [
                'titre' => 'Bonne fête à vous 🌸',
                'messageTemplate' => "Bonjour {nom}, en cette journée internationale de la femme, toute la famille {eglise} tient à honorer votre force, votre courage et tout ce que vous accomplissez chaque jour, souvent dans l'ombre. Vous inspirez par votre détermination et votre générosité. Merci d'être la femme remarquable que vous êtes. Nous vous souhaitons une magnifique journée, pleine de reconnaissance et de fierté !",
            ],
            CelebrationType::KEY_FETE_TRAVAIL => [
                'titre' => 'Bonne fête du travail 🛠️',
                'messageTemplate' => "Bonjour {nom}, en cette fête du travail, toute la famille {eglise} tient à saluer votre engagement, votre persévérance et le sérieux avec lequel vous accomplissez vos tâches au quotidien, dans votre métier comme dans votre service à la communauté. Que vos efforts continuent d'être récompensés. Bonne fête du travail, et merci pour tout ce que vous faites !",
            ],
            CelebrationType::KEY_PAQUES => [
                'titre' => 'Joyeuses Pâques 🐣',
                'messageTemplate' => "Bonjour {nom}, en cette fête de Pâques, toute la famille {eglise} célèbre avec vous la victoire de la vie et l'espérance qu'elle nous offre. Que cette période vous apporte un renouveau intérieur, de la paix, et la certitude que de belles choses vous attendent. Joyeuses Pâques, remplies de foi et d'espérance renouvelée !",
            ],
            CelebrationType::KEY_FETE_UNITE => [
                'titre' => "Bonne fête de l'Unité 🇨🇲",
                'messageTemplate' => "Bonjour {nom}, en cette fête de l'Unité nationale, toute la famille {eglise} vous souhaite une belle journée sous le signe de la paix, de la fraternité et de l'unité entre tous. Que notre pays continue de grandir dans l'harmonie et le respect mutuel, et que vous soyez fier de la part que vous y prenez. Bonne fête de l'Unité !",
            ],
            CelebrationType::KEY_FETE_JEUNESSE => [
                'titre' => 'Bonne fête de la Jeunesse ✨',
                'messageTemplate' => "Bonjour {nom}, en cette fête de la Jeunesse, toute la famille {eglise} salue votre énergie, vos rêves et tout le potentiel que vous portez pour l'avenir. Continuez à avancer avec audace et détermination : de belles opportunités vous attendent. Magnifique fête de la Jeunesse, pleine d'enthousiasme et d'espoir !",
            ],
        ];
    }
};
