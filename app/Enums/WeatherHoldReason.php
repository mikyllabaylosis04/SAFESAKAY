<?php

namespace App\Enums;

enum WeatherHoldReason: string
{
    case BadWeather = 'bad_weather';
    case RoughSeas = 'rough_seas';

    public function label(): string
    {
        return match ($this) {
            self::BadWeather => 'Bad Weather',
            self::RoughSeas => 'Rough Seas',
        };
    }
}
