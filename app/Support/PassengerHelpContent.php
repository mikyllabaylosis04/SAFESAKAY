<?php

namespace App\Support;

use App\Models\Organization;

/**
 * Help, policy, and emergency content shown in the passenger mobile app.
 */
final class PassengerHelpContent
{
    /**
     * @return array<string, mixed>
     */
    public static function payload(?Organization $organization): array
    {
        $org = $organization?->displayName() ?? $organization?->name;

        return [
            'barangay' => [
                'name' => $org,
                'address' => $organization?->address,
                'contact_number' => $organization?->contact_number,
            ],
            'hotlines' => self::hotlines($organization),
            'faq' => self::faq(),
            'policies' => [
                [
                    'key' => 'rental',
                    'title' => 'Cancellation, Refund & No-Show Policy',
                    'summary' => RentalCheckoutPolicy::summary(),
                    'version' => RentalCheckoutPolicy::VERSION,
                    'sections' => RentalCheckoutPolicy::sectionsFor($org),
                ],
                [
                    'key' => 'terms',
                    'title' => 'Terms & Conditions',
                    'summary' => 'Rules for using SafeSakay to rent boats in your barangay.',
                    'version' => RentalCheckoutPolicy::VERSION,
                    'sections' => self::terms($org),
                ],
                [
                    'key' => 'privacy',
                    'title' => 'Privacy Policy',
                    'summary' => 'What personal information SafeSakay collects and how it is used, in line with the Data Privacy Act of 2012 (RA 10173).',
                    'version' => RentalCheckoutPolicy::VERSION,
                    'sections' => self::privacy($org),
                ],
            ],
        ];
    }

    /**
     * @return list<array{label: string, number: string, description: string}>
     */
    private static function hotlines(?Organization $organization): array
    {
        $lines = [];

        if (filled($organization?->contact_number)) {
            $lines[] = [
                'label' => ($organization->displayName() ?? 'Barangay').' Office',
                'number' => (string) $organization->contact_number,
                'description' => 'Rental concerns, refunds, and boat operator issues.',
            ];
        }

        return [
            ...$lines,
            [
                'label' => 'National Emergency Hotline',
                'number' => '911',
                'description' => 'Police, fire, and medical emergencies anywhere in the Philippines.',
            ],
            [
                'label' => 'Philippine Coast Guard',
                'number' => '09177243682',
                'description' => 'Sea emergencies, missing boats, and maritime accidents.',
            ],
            [
                'label' => 'Philippine Red Cross',
                'number' => '143',
                'description' => 'Rescue and medical assistance.',
            ],
        ];
    }

    /**
     * @return list<array{question: string, answer: string}>
     */
    private static function faq(): array
    {
        return [
            [
                'question' => 'How do I rent a boat?',
                'answer' => 'Open Rent a Boat, choose a boat, pick your trip date and time, then submit your request. The boat operator (or barangay admin) reviews it before you pay.',
            ],
            [
                'question' => 'When do I pay?',
                'answer' => 'After your request is approved, pay the downpayment or the full amount with GCash. Your rental is confirmed once the payment is verified.',
            ],
            [
                'question' => 'How do I pay the remaining balance?',
                'answer' => 'Pay the boat operator through GCash online or in cash on the day of the trip. It must be recorded before departure.',
            ],
            [
                'question' => 'Can I cancel my rental?',
                'answer' => 'Yes. Pending requests can be cancelled anytime. After paying, the downpayment is non-refundable when you cancel voluntarily. Full-payment rentals cancelled at least 24 hours before departure may request a refund from the barangay office.',
            ],
            [
                'question' => 'What happens if the weather is bad?',
                'answer' => 'The barangay may place your trip on weather hold. You can pick a new date or request a refund of eligible payments without penalty.',
            ],
            [
                'question' => 'What if the boat operator does not show up?',
                'answer' => 'Report it from your rental details. You can choose a replacement boat, a new schedule, or a refund.',
            ],
            [
                'question' => 'Where can I see my payments?',
                'answer' => 'Open Payment History from My Rentals or the side menu to see every downpayment, balance, and refund.',
            ],
        ];
    }

    /**
     * @return list<array{heading: string, body: string}>
     */
    private static function terms(?string $org): array
    {
        $org = filled($org) ? $org : 'your barangay organization';

        return [
            [
                'heading' => '1. Using SafeSakay',
                'body' => 'SafeSakay connects passengers with boat operators registered and verified by '.$org.'. You must give true and complete information when you create an account and when you book a rental.',
            ],
            [
                'heading' => '2. Bookings',
                'body' => 'A rental request is not confirmed until it is approved and the required payment is verified. The boat operator and the barangay may decline a request when the boat is unavailable or unsafe to travel.',
            ],
            [
                'heading' => '3. Payments',
                'body' => 'Online payments are processed through GCash. Prices shown in the app already include any barangay commission. Keep your payment reference until your trip is completed.',
            ],
            [
                'heading' => '4. Safety rules',
                'body' => 'Follow the boat operator and Coast Guard instructions at all times. Wear a life jacket, do not exceed the boat capacity, and arrive at the pickup point at least 30 minutes before departure.',
            ],
            [
                'heading' => '5. Cancellations and no-shows',
                'body' => 'Cancellations, refunds, weather holds, and no-shows follow the Cancellation, Refund & No-Show Policy shown in this app and at checkout.',
            ],
            [
                'heading' => '6. Account responsibility',
                'body' => 'Keep your password private. '.$org.' may deactivate accounts that give false information or misuse the service.',
            ],
        ];
    }

    /**
     * @return list<array{heading: string, body: string}>
     */
    private static function privacy(?string $org): array
    {
        $org = filled($org) ? $org : 'your barangay organization';

        return [
            [
                'heading' => '1. Information we collect',
                'body' => 'Your name, email, contact number, address, emergency contact, profile photo, trip details, and payment references.',
            ],
            [
                'heading' => '2. Why we collect it',
                'body' => 'To process your rentals and payments, let the boat operator and '.$org.' contact you about your trip, and reach your emergency contact during an emergency at sea.',
            ],
            [
                'heading' => '3. Who can see it',
                'body' => 'Only the assigned boat operator and authorized barangay staff see your booking details. GCash payments are handled by the payment provider; SafeSakay does not store your GCash PIN or card details.',
            ],
            [
                'heading' => '4. Your rights',
                'body' => 'Under the Data Privacy Act of 2012 (RA 10173), you may view and correct your information in Edit Profile and ask '.$org.' to correct or delete records you no longer need.',
            ],
            [
                'heading' => '5. Keeping records',
                'body' => 'Booking and payment records are kept for accounting and safety reports required by the barangay.',
            ],
        ];
    }
}
