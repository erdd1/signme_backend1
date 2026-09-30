<?php

use App\Models\CelebrationType;
use Illuminate\Database\Migrations\Migration;

/**
 * Données de référence — les 9 occasions gérées par le module Célébrations.
 * Une migration de données (pas un seeder) : ces lignes sont indispensables
 * au fonctionnement du module, elles doivent exister après `migrate` en
 * production sans dépendre d'un `db:seed` séparé.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach ($this->types() as $type) {
            CelebrationType::updateOrCreate(['key' => $type['key']], $type);
        }
    }

    public function down(): void
    {
        CelebrationType::whereIn('key', array_column($this->types(), 'key'))->delete();
    }

    private function types(): array
    {
        return [
            [
                'key' => CelebrationType::KEY_ANNIVERSAIRE,
                'label' => 'Anniversaire',
                'titre' => 'Joyeux anniversaire 🎉',
                'messageTemplate' => "Bonjour {nom}, quel jour merveilleux ! Toute la famille {eglise} se joint à moi pour célébrer votre anniversaire avec une immense joie. Que cette nouvelle année de vie soit remplie de santé, de force, de succès dans tout ce que vous entreprendrez, et surtout d'une bénédiction débordante. Merci d'être une personne aussi précieuse pour notre communauté. Très joyeux anniversaire !",
                'dateRuleType' => CelebrationType::RULE_BIRTHDAY,
                'fixedMonth' => null,
                'fixedDay' => null,
                'genderFilter' => null,
                'animationKey' => 'birthday',
                'active' => true,
            ],
            [
                'key' => CelebrationType::KEY_NOUVEL_AN,
                'label' => 'Nouvel An',
                'titre' => 'Bonne année 🎆',
                'messageTemplate' => "Bonjour {nom}, alors que l'année {annee} commence, toute la famille {eglise} tient à vous exprimer sa gratitude de vous compter parmi nous. Que cette nouvelle année vous apporte une santé solide, une paix profonde, des projets qui aboutissent et des relations qui vous élèvent. Nous croyons que {annee} sera une année de grâce et de progrès pour vous et vos proches. Excellente et heureuse année {annee} !",
                'dateRuleType' => CelebrationType::RULE_FIXED,
                'fixedMonth' => 1,
                'fixedDay' => 1,
                'genderFilter' => null,
                'animationKey' => 'new_year',
                'active' => true,
            ],
            [
                'key' => CelebrationType::KEY_NOEL,
                'label' => 'Noël',
                'titre' => 'Joyeux Noël 🎄',
                'messageTemplate' => "Bonjour {nom}, en ce jour de Noël si particulier, toute la famille {eglise} pense à vous avec beaucoup d'affection. Que la lumière de cette fête illumine votre foyer, que la paix habite votre cœur et que l'amour de vos proches vous entoure aujourd'hui et tout au long de l'année. Merci d'être une part importante de notre communauté. Joyeux Noël et que cette période vous comble !",
                'dateRuleType' => CelebrationType::RULE_FIXED,
                'fixedMonth' => 12,
                'fixedDay' => 25,
                'genderFilter' => null,
                'animationKey' => 'christmas',
                'active' => true,
            ],
            [
                'key' => CelebrationType::KEY_SAINT_VALENTIN,
                'label' => 'Saint-Valentin',
                'titre' => 'Bonne Saint-Valentin 💝',
                'messageTemplate' => "Bonjour {nom}, en ce jour dédié à l'amour, toute la famille {eglise} tient à vous rappeler combien vous êtes une personne précieuse et aimée. Que cette journée vous apporte de la douceur, de belles attentions, et vous rappelle la valeur des liens qui vous entourent. Très belle Saint-Valentin, remplie de tendresse !",
                'dateRuleType' => CelebrationType::RULE_FIXED,
                'fixedMonth' => 2,
                'fixedDay' => 14,
                'genderFilter' => null,
                'animationKey' => 'valentine',
                'active' => true,
            ],
            [
                'key' => CelebrationType::KEY_JOURNEE_FEMME,
                'label' => 'Journée internationale de la femme',
                'titre' => 'Bonne fête à vous 🌸',
                'messageTemplate' => "Bonjour {nom}, en cette journée internationale de la femme, toute la famille {eglise} tient à honorer votre force, votre courage et tout ce que vous accomplissez chaque jour, souvent dans l'ombre. Vous inspirez par votre détermination et votre générosité. Merci d'être la femme remarquable que vous êtes. Nous vous souhaitons une magnifique journée, pleine de reconnaissance et de fierté !",
                'dateRuleType' => CelebrationType::RULE_FIXED,
                'fixedMonth' => 3,
                'fixedDay' => 8,
                'genderFilter' => 'femme',
                'animationKey' => 'women_day',
                'active' => true,
            ],
            [
                'key' => CelebrationType::KEY_FETE_TRAVAIL,
                'label' => 'Fête du travail',
                'titre' => 'Bonne fête du travail 🛠️',
                'messageTemplate' => "Bonjour {nom}, en cette fête du travail, toute la famille {eglise} tient à saluer votre engagement, votre persévérance et le sérieux avec lequel vous accomplissez vos tâches au quotidien, dans votre métier comme dans votre service à la communauté. Que vos efforts continuent d'être récompensés. Bonne fête du travail, et merci pour tout ce que vous faites !",
                'dateRuleType' => CelebrationType::RULE_FIXED,
                'fixedMonth' => 5,
                'fixedDay' => 1,
                'genderFilter' => null,
                'animationKey' => 'labor_day',
                'active' => true,
            ],
            [
                'key' => CelebrationType::KEY_PAQUES,
                'label' => 'Pâques',
                'titre' => 'Joyeuses Pâques 🐣',
                'messageTemplate' => "Bonjour {nom}, en cette fête de Pâques, toute la famille {eglise} célèbre avec vous la victoire de la vie et l'espérance qu'elle nous offre. Que cette période vous apporte un renouveau intérieur, de la paix, et la certitude que de belles choses vous attendent. Joyeuses Pâques, remplies de foi et d'espérance renouvelée !",
                'dateRuleType' => CelebrationType::RULE_EASTER,
                'fixedMonth' => null,
                'fixedDay' => null,
                'genderFilter' => null,
                'animationKey' => 'easter',
                'active' => true,
            ],
            [
                'key' => CelebrationType::KEY_FETE_UNITE,
                'label' => "Fête de l'Unité",
                'titre' => "Bonne fête de l'Unité 🇨🇲",
                'messageTemplate' => "Bonjour {nom}, en cette fête de l'Unité nationale, toute la famille {eglise} vous souhaite une belle journée sous le signe de la paix, de la fraternité et de l'unité entre tous. Que notre pays continue de grandir dans l'harmonie et le respect mutuel, et que vous soyez fier de la part que vous y prenez. Bonne fête de l'Unité !",
                'dateRuleType' => CelebrationType::RULE_FIXED,
                'fixedMonth' => 5,
                'fixedDay' => 20,
                'genderFilter' => null,
                'animationKey' => 'unity_day',
                'active' => true,
            ],
            [
                'key' => CelebrationType::KEY_FETE_JEUNESSE,
                'label' => 'Fête de la Jeunesse',
                'titre' => 'Bonne fête de la Jeunesse ✨',
                'messageTemplate' => "Bonjour {nom}, en cette fête de la Jeunesse, toute la famille {eglise} salue votre énergie, vos rêves et tout le potentiel que vous portez pour l'avenir. Continuez à avancer avec audace et détermination : de belles opportunités vous attendent. Magnifique fête de la Jeunesse, pleine d'enthousiasme et d'espoir !",
                'dateRuleType' => CelebrationType::RULE_FIXED,
                'fixedMonth' => 2,
                'fixedDay' => 11,
                'genderFilter' => null,
                'animationKey' => 'youth_day',
                'active' => true,
            ],
        ];
    }
};
