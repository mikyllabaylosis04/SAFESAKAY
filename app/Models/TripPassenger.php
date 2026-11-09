<?php

namespace App\Models;

use App\Enums\PassengerFareType;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $organization_id
 * @property int $regular_trip_id
 * @property string $full_name
 * @property int $age
 * @property PassengerFareType $fare_type
 * @property string $gender
 * @property string $contact_number
 * @property string|null $remarks
 * @property int $sort_order
 */
#[Fillable([
    'organization_id',
    'regular_trip_id',
    'full_name',
    'age',
    'fare_type',
    'gender',
    'contact_number',
    'remarks',
    'sort_order',
])]
class TripPassenger extends Model
{
    use BelongsToOrganization;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'age' => 'integer',
            'fare_type' => PassengerFareType::class,
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<RegularTrip, $this>
     */
    public function fareType(): PassengerFareType
    {
        return PassengerFareType::tryFromValue($this->fare_type);
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(RegularTrip::class, 'regular_trip_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function toRecord(): array
    {
        return [
            'id' => $this->id,
            'full_name' => $this->full_name,
            'age' => $this->age,
            'fare_type' => $this->fareType()->value,
            'fare_type_label' => $this->fareType()->label(),
            'gender' => $this->gender,
            'contact_number' => $this->contact_number,
            'remarks' => $this->remarks,
        ];
    }

    /**
     * @return list<array{full_name: string, age: int, gender: string, contact_number: string}>
     */
    public static function sampleRecords(int $count, int $seed = 1): array
    {
        $people = [
            ['Juan Dela Cruz', 'Male'],
            ['Maria Santos', 'Female'],
            ['Pedro Ramirez', 'Male'],
            ['Ana Reyes', 'Female'],
            ['Jose Mendoza', 'Male'],
            ['Liza Navarro', 'Female'],
            ['Carlo Villanueva', 'Male'],
            ['Rosa Dela Peña', 'Female'],
            ['Mark Anthony Flores', 'Male'],
            ['Jennylyn Castro', 'Female'],
            ['Roberto Sanchez', 'Male'],
            ['Cristina Bautista', 'Female'],
            ['Daniel Torres', 'Male'],
            ['Andrea Villar', 'Female'],
            ['Paolo Gutierrez', 'Male'],
            ['Michelle Ramos', 'Female'],
            ['Ramon Aguilar', 'Male'],
            ['Katrina Lopez', 'Female'],
            ['Francis Ortega', 'Male'],
            ['Sharon Diaz', 'Female'],
            ['Alvin Cruz', 'Male'],
            ['Maricel Fontanilla', 'Female'],
            ['Eric Salvador', 'Male'],
            ['Joyce Alvarado', 'Female'],
        ];

        $records = [];

        for ($index = 0; $index < $count; $index++) {
            [$name, $gender] = $people[($seed + $index) % count($people)];

            $records[] = [
                'full_name' => $name,
                'age' => 18 + (($seed * 3 + $index * 7) % 47),
                'gender' => $gender,
                'contact_number' => '09'.str_pad((string) ((200000000 + ($seed * 137) + ($index * 913)) % 1000000000), 9, '0', STR_PAD_LEFT),
            ];
        }

        return $records;
    }
}
