<?php

namespace App\Enums;

enum SafetyEquipment: string
{
    case LifeJackets = 'life_jackets';
    case FireExtinguisher = 'fire_extinguisher';
    case FirstAidKit = 'first_aid_kit';
    case MarineRadio = 'marine_radio';
    case NavigationLights = 'navigation_lights';
    case AnchorAndRope = 'anchor_and_rope';
    case Flashlight = 'flashlight';
    case EmergencySignaling = 'emergency_signaling_device';

    public function label(): string
    {
        return match ($this) {
            self::LifeJackets => 'Life Jackets',
            self::FireExtinguisher => 'Fire Extinguisher',
            self::FirstAidKit => 'First-Aid Kit',
            self::MarineRadio => 'Marine Radio / Communication Device',
            self::NavigationLights => 'Navigation Lights',
            self::AnchorAndRope => 'Anchor & Anchor Rope',
            self::Flashlight => 'Flashlight',
            self::EmergencySignaling => 'Emergency Signaling Device',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * @param  list<string>|null  $selected
     */
    public static function summarize(?array $selected, ?int $lifeJacketQuantity): ?string
    {
        $selected = array_values(array_unique($selected ?? []));

        if ($selected === []) {
            return null;
        }

        $lines = [];

        foreach (self::cases() as $equipment) {
            if (! in_array($equipment->value, $selected, true)) {
                continue;
            }

            if ($equipment === self::LifeJackets && $lifeJacketQuantity) {
                $lines[] = $equipment->label().' ('.$lifeJacketQuantity.')';

                continue;
            }

            $lines[] = $equipment->label();
        }

        return $lines === [] ? null : implode("\n", $lines);
    }
}
