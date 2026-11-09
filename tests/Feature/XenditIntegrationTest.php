<?php

namespace Tests\Feature;

use App\Enums\BoatBookingStatus;
use App\Enums\BoatService;
use App\Enums\BoatStatus;
use App\Enums\BookingPaymentStatus;
use App\Enums\UserRole;
use App\Models\Boat;
use App\Models\BoatBooking;
use App\Models\Organization;
use App\Models\RentalPayment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use App\Support\RentalCheckoutPolicy;
use App\Support\XenditMoney;
use Tests\TestCase;

class XenditIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('services.xendit.enabled', true);
        Config::set('services.xendit.secret_key', 'xnd_development_test_key');
        Config::set('services.xendit.webhook_token', 'xendit_webhook_test_token');
        Config::set('services.xendit.success_redirect_url', 'https://example.test/payment/success');
        Config::set('services.xendit.failure_redirect_url', 'https://example.test/payment/cancel');
        Config::set('services.xendit.test_mode_only', true);
        Config::set('services.xendit.session_duration_seconds', 7200);
    }

    public function test_downpayment_checkout_creates_pending_rental_payment(): void
    {
        Http::fake([
            'https://api.xendit.co/v3/payment_requests' => Http::response([
                'payment_session_id' => 'ps-test-123',
                'payment_link_url' => 'https://xen.to/test-checkout',
                'reference_id' => 'SS-1-DP-test',
                'amount' => 45000,
                'currency' => 'PHP',
            ], 201),
        ]);

        [$operator, $booking, $passenger] = $this->linkedRental();
        $this->approveBooking($operator, $booking, $passenger);

        $this->actingAs($passenger, 'sanctum')
            ->postJson("/api/bookings/{$booking->id}/xendit/checkout", $this->checkoutPayload('downpayment'))
            ->assertOk()
            ->assertJsonPath('checkout_session_id', 'ps-test-123')
            ->assertJsonPath('checkout_url', 'https://xen.to/test-checkout');

        $this->assertDatabaseHas('rental_payments', [
            'boat_booking_id' => $booking->id,
            'provider' => 'xendit',
            'payment_type' => 'downpayment',
            'status' => 'pending',
            'amount' => 45000,
            'checkout_session_id' => 'ps-test-123',
        ]);
    }

    public function test_unauthorized_passenger_cannot_create_checkout(): void
    {
        [$operator, $booking, $passenger] = $this->linkedRental();
        $this->approveBooking($operator, $booking);

        $other = User::factory()->create([
            'organization_id' => $booking->organization_id,
            'role' => UserRole::Passenger,
        ]);

        $this->actingAs($other, 'sanctum')
            ->postJson("/api/bookings/{$booking->id}/xendit/checkout", $this->checkoutPayload('downpayment'))
            ->assertForbidden();
    }

    public function test_duplicate_checkout_creates_fresh_xendit_session(): void
    {
        Http::fake([
            'https://api.xendit.co/v3/payment_requests' => Http::sequence()
                ->push([
                    'payment_session_id' => 'ps-test-dup-1',
                    'payment_link_url' => 'https://xen.to/test-dup-1',
                ], 201)
                ->push([
                    'payment_session_id' => 'ps-test-dup-2',
                    'payment_link_url' => 'https://xen.to/test-dup-2',
                ], 201),
        ]);

        [$operator, $booking, $passenger] = $this->linkedRental();
        $this->approveBooking($operator, $booking, $passenger);

        $this->actingAs($passenger, 'sanctum')
            ->postJson("/api/bookings/{$booking->id}/xendit/checkout", $this->checkoutPayload('downpayment'))
            ->assertOk()
            ->assertJsonPath('checkout_session_id', 'ps-test-dup-1');

        Http::assertSentCount(1);

        $this->actingAs($passenger, 'sanctum')
            ->postJson("/api/bookings/{$booking->id}/xendit/checkout", $this->checkoutPayload('downpayment'))
            ->assertOk()
            ->assertJsonPath('checkout_session_id', 'ps-test-dup-2');

        Http::assertSentCount(2);
        $this->assertSame(1, RentalPayment::query()->where('status', 'pending')->count());
        $this->assertSame(1, RentalPayment::query()->where('status', 'expired')->count());
    }

    public function test_full_rental_checkout_sends_peso_amount_for_two_thousand_two_hundred_rental(): void
    {
        Http::fake([
            'https://api.xendit.co/v3/payment_requests' => Http::response([
                'payment_session_id' => 'ps-2200-full',
                'payment_link_url' => 'https://xen.to/2200-full',
                'amount' => 2200,
            ], 201),
        ]);

        [$operator, $booking, $passenger] = $this->linkedRentalWithPricing(
            rentalPrice: 2200,
            downPaymentPercent: 50,
            downPaymentAmount: 1100,
            remainingBalance: 1100,
        );
        $this->approveBooking($operator, $booking, $passenger);

        $this->actingAs($passenger, 'sanctum')
            ->postJson("/api/bookings/{$booking->id}/xendit/checkout", $this->checkoutPayload('full_rental'))
            ->assertOk();

        Http::assertSent(function ($request): bool {
            $body = $request->data();

            return $request->url() === 'https://api.xendit.co/v3/payment_requests'
                && $request->hasHeader('api-version', '2024-11-11')
                && ($body['request_amount'] ?? null) === 2200.0
                && ($body['type'] ?? null) === 'PAY'
                && ($body['channel_code'] ?? null) === 'GCASH'
                && ($body['currency'] ?? null) === 'PHP'
                && ($body['country'] ?? null) === 'PH';
        });

        $this->assertDatabaseHas('rental_payments', [
            'boat_booking_id' => $booking->id,
            'payment_type' => 'full_rental',
            'amount' => 220000,
        ]);
    }

    public function test_downpayment_checkout_sends_half_of_two_thousand_two_hundred_as_eleven_hundred_pesos(): void
    {
        Http::fake([
            'https://api.xendit.co/v3/payment_requests' => Http::response([
                'payment_session_id' => 'ps-1100-dp',
                'payment_link_url' => 'https://xen.to/1100-dp',
            ], 201),
        ]);

        [$operator, $booking, $passenger] = $this->linkedRentalWithPricing(
            rentalPrice: 2200,
            downPaymentPercent: 50,
            downPaymentAmount: 1100,
            remainingBalance: 1100,
        );
        $this->approveBooking($operator, $booking, $passenger);

        $this->actingAs($passenger, 'sanctum')
            ->postJson("/api/bookings/{$booking->id}/xendit/checkout", $this->checkoutPayload('downpayment'))
            ->assertOk();

        Http::assertSent(function ($request): bool {
            $body = $request->data();

            return ($body['request_amount'] ?? null) === 1100.0
                && ($body['channel_code'] ?? null) === 'GCASH';
        });
    }

    public function test_balance_checkout_sends_remaining_unpaid_balance_in_pesos(): void
    {
        Http::fake([
            'https://api.xendit.co/v3/payment_requests' => Http::response([
                'payment_session_id' => 'ps-balance',
                'payment_link_url' => 'https://xen.to/balance',
            ], 201),
        ]);

        [$operator, $booking, $passenger] = $this->linkedRentalWithPricing(
            rentalPrice: 2200,
            downPaymentPercent: 50,
            downPaymentAmount: 1100,
            remainingBalance: 1100,
        );
        $this->approveBooking($operator, $booking, $passenger);

        $booking->update([
            'payment_status' => BookingPaymentStatus::Verified,
            'status' => BoatBookingStatus::Confirmed,
        ]);

        $this->actingAs($passenger, 'sanctum')
            ->postJson("/api/bookings/{$booking->id}/xendit/checkout", $this->checkoutPayload('full_payment'))
            ->assertOk();

        Http::assertSent(function ($request): bool {
            return ($request->data()['request_amount'] ?? null) === 1100.0;
        });
    }

    public function test_failed_webhook_does_not_mark_booking_paid(): void
    {
        [$operator, $booking] = $this->linkedRental();
        $this->approveBooking($operator, $booking);

        RentalPayment::query()->create([
            'organization_id' => $booking->organization_id,
            'boat_booking_id' => $booking->id,
            'provider' => 'xendit',
            'payment_type' => 'downpayment',
            'amount' => 45000,
            'commission_amount' => 0,
            'operator_amount' => 45000,
            'provider_fee' => 0,
            'currency' => 'PHP',
            'status' => 'pending',
            'checkout_session_id' => 'ps-test-failed',
            'checkout_url' => 'https://xen.to/test-failed',
            'checkout_expires_at' => now()->addHour(),
        ]);

        $payload = [
            'event' => 'payment_session.failed',
            'data' => [
                'payment_session_id' => 'ps-test-failed',
                'amount' => 450.0,
                'currency' => 'PHP',
                'status' => 'FAILED',
            ],
        ];

        $this->postWebhook($payload)->assertOk();

        $booking->refresh();
        $this->assertSame(BoatBookingStatus::AwaitingPayment, $booking->status);
        $this->assertSame('pending', RentalPayment::query()->first()?->status);
    }

    public function test_manual_gcash_proof_is_blocked_while_checkout_is_pending(): void
    {
        Storage::fake('local');
        Http::fake([
            'https://api.xendit.co/v3/payment_requests' => Http::response([
                'payment_session_id' => 'ps-test-block',
                'payment_link_url' => 'https://xen.to/test-block',
            ], 201),
        ]);

        [$operator, $booking, $passenger] = $this->linkedRental();
        $this->approveBooking($operator, $booking, $passenger);

        $this->actingAs($passenger, 'sanctum')
            ->postJson("/api/bookings/{$booking->id}/xendit/checkout", $this->checkoutPayload('downpayment'))
            ->assertOk();

        $this->actingAs($passenger, 'sanctum')
            ->post("/api/bookings/{$booking->id}/payment-proof", [
                'gcash_reference' => 'GCASH-BLOCK-123456',
                'payment_amount' => '450',
                'receipt' => UploadedFile::fake()->image('receipt.jpg'),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['receipt']);
    }

    public function test_v3_payment_capture_webhook_confirms_booking(): void
    {
        [$operator, $booking] = $this->linkedRental();
        $this->approveBooking($operator, $booking);

        RentalPayment::query()->create([
            'organization_id' => $booking->organization_id,
            'boat_booking_id' => $booking->id,
            'provider' => 'xendit',
            'payment_type' => 'downpayment',
            'amount' => 45000,
            'commission_amount' => 0,
            'operator_amount' => 60000,
            'provider_fee' => 0,
            'currency' => 'PHP',
            'status' => 'pending',
            'checkout_session_id' => 'pr-test-capture',
            'checkout_url' => 'https://xen.to/test-capture',
            'checkout_expires_at' => now()->addHour(),
        ]);

        $payload = [
            'event' => 'payment.capture',
            'created' => now()->toIso8601String(),
            'data' => [
                'payment_id' => 'py-test-capture',
                'payment_request_id' => 'pr-test-capture',
                'reference_id' => 'SS-67-DP-test',
                'type' => 'PAY',
                'country' => 'PH',
                'currency' => 'PHP',
                'request_amount' => 450,
                'channel_code' => 'GCASH',
                'status' => 'SUCCEEDED',
                'captures' => [
                    [
                        'capture_id' => 'cptr-test-capture',
                        'capture_amount' => 450,
                    ],
                ],
                'payment_details' => [
                    'authorization_data' => [
                        'authorization_code' => '831000',
                        'network_response_code' => '00',
                    ],
                ],
                'updated' => now()->toIso8601String(),
            ],
        ];

        $this->postWebhook($payload)
            ->assertOk()
            ->assertJsonPath('booking_confirmed', true);

        $this->assertSame('paid', RentalPayment::query()->first()?->status);
        $this->assertSame('py-test-capture', RentalPayment::query()->first()?->provider_payment_id);
    }

    public function test_verified_webhook_confirms_booking_without_admin_verifier(): void
    {
        [$operator, $booking, $passenger] = $this->linkedRental();
        $this->approveBooking($operator, $booking);

        RentalPayment::query()->create([
            'organization_id' => $booking->organization_id,
            'boat_booking_id' => $booking->id,
            'provider' => 'xendit',
            'payment_type' => 'downpayment',
            'amount' => 45000,
            'commission_amount' => 0,
            'operator_amount' => 45000,
            'provider_fee' => 0,
            'currency' => 'PHP',
            'status' => 'pending',
            'checkout_session_id' => 'ps-test-paid',
            'checkout_url' => 'https://xen.to/test-paid',
            'checkout_expires_at' => now()->addHour(),
        ]);

        $payload = $this->completedWebhookPayload('ps-test-paid', 'pr-test-abc', 45000);

        $this->postWebhook($payload)
            ->assertOk()
            ->assertJsonPath('booking_confirmed', true);

        $booking->refresh();
        $this->assertSame(BoatBookingStatus::Confirmed, $booking->status);
        $this->assertSame(BookingPaymentStatus::Verified, $booking->paymentStatus());
        $this->assertSame('paid', RentalPayment::query()->first()?->status);
    }

    public function test_invalid_webhook_token_is_rejected(): void
    {
        $payload = $this->completedWebhookPayload('ps-test-bad', 'pr-test-bad', 45000);

        $this->call(
            'POST',
            '/api/xendit/webhook',
            [],
            [],
            [],
            [
                'HTTP_x-callback-token' => 'wrong-token',
                'CONTENT_TYPE' => 'application/json',
            ],
            json_encode($payload, JSON_THROW_ON_ERROR),
        )->assertUnauthorized();
    }

    public function test_duplicate_webhook_delivery_is_idempotent(): void
    {
        [$operator, $booking] = $this->linkedRental();
        $this->approveBooking($operator, $booking);

        RentalPayment::query()->create([
            'organization_id' => $booking->organization_id,
            'boat_booking_id' => $booking->id,
            'provider' => 'xendit',
            'payment_type' => 'downpayment',
            'amount' => 45000,
            'commission_amount' => 0,
            'operator_amount' => 45000,
            'provider_fee' => 0,
            'currency' => 'PHP',
            'status' => 'pending',
            'checkout_session_id' => 'ps-test-retry',
            'checkout_url' => 'https://xen.to/test-retry',
            'checkout_expires_at' => now()->addHour(),
        ]);

        $payload = $this->completedWebhookPayload('ps-test-retry', 'pr-test-retry', 45000);

        $this->postWebhook($payload)->assertOk();
        $this->postWebhook($payload)
            ->assertOk()
            ->assertJsonPath('already_processed', true);

        $this->assertSame(1, RentalPayment::query()->where('provider_payment_id', 'pr-test-retry')->count());
    }

    public function test_amount_mismatch_is_rejected(): void
    {
        [$operator, $booking] = $this->linkedRental();
        $this->approveBooking($operator, $booking);

        RentalPayment::query()->create([
            'organization_id' => $booking->organization_id,
            'boat_booking_id' => $booking->id,
            'provider' => 'xendit',
            'payment_type' => 'downpayment',
            'amount' => 45000,
            'commission_amount' => 0,
            'operator_amount' => 45000,
            'provider_fee' => 0,
            'currency' => 'PHP',
            'status' => 'pending',
            'checkout_session_id' => 'ps-test-mismatch',
            'checkout_url' => 'https://xen.to/test-mismatch',
            'checkout_expires_at' => now()->addHour(),
        ]);

        $payload = $this->completedWebhookPayload('ps-test-mismatch', 'pr-test-mismatch', 10000);
        $this->postWebhook($payload)->assertStatus(409);
    }

    public function test_expired_session_webhook_marks_pending_payment_expired(): void
    {
        [$operator, $booking] = $this->linkedRental();
        $this->approveBooking($operator, $booking);

        RentalPayment::query()->create([
            'organization_id' => $booking->organization_id,
            'boat_booking_id' => $booking->id,
            'provider' => 'xendit',
            'payment_type' => 'downpayment',
            'amount' => 45000,
            'commission_amount' => 0,
            'operator_amount' => 45000,
            'provider_fee' => 0,
            'currency' => 'PHP',
            'status' => 'pending',
            'checkout_session_id' => 'ps-test-expired',
            'checkout_url' => 'https://xen.to/test-expired',
            'checkout_expires_at' => now()->addHour(),
        ]);

        $payload = [
            'event' => 'payment_session.expired',
            'data' => [
                'payment_session_id' => 'ps-test-expired',
            ],
        ];

        $this->postWebhook($payload)->assertOk();
        $this->assertSame('expired', RentalPayment::query()->first()?->status);
    }

    /**
     * @return array{0: User, 1: BoatBooking, 2: User, 3?: User}
     */
    private function linkedRental(): array
    {
        $org = Organization::factory()->create([
            'gcash_account_name' => 'Barangay GCash',
        ]);
        $operator = User::factory()->create([
            'organization_id' => $org->id,
            'role' => UserRole::Operator,
        ]);
        $passenger = User::factory()->create([
            'organization_id' => $org->id,
            'role' => UserRole::Passenger,
        ]);
        $admin = User::factory()->create([
            'organization_id' => $org->id,
            'role' => UserRole::Administrator,
        ]);
        $boat = Boat::factory()->create([
            'organization_id' => $org->id,
            'status' => BoatStatus::OnDuty,
            'service_type' => 'both',
            'service_availability' => BoatService::values(),
        ]);
        $boat->operator?->update(['user_id' => $operator->id]);

        $booking = BoatBooking::query()->create([
            'organization_id' => $org->id,
            'user_id' => $passenger->id,
            'boat_id' => $boat->id,
            'customer_name' => $passenger->name,
            'contact_number' => '09170000002',
            'booking_date' => now()->addDays(3),
            'start_time' => '17:00:00',
            'destination' => 'Test Island',
            'package' => 'Test Island',
            'passenger_count' => 8,
            'status' => BoatBookingStatus::Pending,
            'payment_status' => BookingPaymentStatus::Unpaid,
            'rental_price' => 1500,
            'down_payment_percent' => 30,
            'down_payment_amount' => 450,
            'remaining_balance' => 1050,
        ]);

        return [$operator, $booking, $passenger, $admin];
    }

    /**
     * @return array{0: User, 1: BoatBooking, 2: User, 3?: User}
     */
    private function linkedRentalWithPricing(
        float $rentalPrice,
        int $downPaymentPercent,
        float $downPaymentAmount,
        float $remainingBalance,
    ): array {
        [$operator, $booking, $passenger, $admin] = $this->linkedRental();

        $booking->update([
            'rental_price' => $rentalPrice,
            'down_payment_percent' => $downPaymentPercent,
            'down_payment_amount' => $downPaymentAmount,
            'remaining_balance' => $remainingBalance,
        ]);

        return [$operator, $booking->fresh(), $passenger, $admin];
    }

    private function approveBooking(User $operator, BoatBooking $booking, ?User $passenger = null): void
    {
        $this->actingAs($operator, 'sanctum')
            ->postJson("/api/bookings/{$booking->id}/approve")
            ->assertOk();

        $booking->refresh();
        $this->assertSame(BoatBookingStatus::AwaitingPayment, $booking->status);

        if ($passenger instanceof User) {
            $this->acceptCheckoutPolicy($passenger, $booking);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function checkoutPayload(string $paymentType): array
    {
        return [
            'payment_type' => $paymentType,
            'policy_version' => RentalCheckoutPolicy::VERSION,
            'policy_accepted' => true,
        ];
    }

    private function acceptCheckoutPolicy(User $passenger, BoatBooking $booking): void
    {
        $this->actingAs($passenger, 'sanctum')
            ->postJson("/api/bookings/{$booking->id}/checkout-policy/accept", [
                'policy_version' => RentalCheckoutPolicy::VERSION,
            ])
            ->assertOk();
    }

    public function test_checkout_uses_app_url_redirect_defaults_when_env_empty(): void
    {
        Config::set('services.xendit.success_redirect_url', '');
        Config::set('services.xendit.failure_redirect_url', '');
        Config::set('app.url', 'https://example.test');

        Http::fake([
            'https://api.xendit.co/v3/payment_requests' => Http::response([
                'payment_session_id' => 'ps-default-redirect',
                'payment_link_url' => 'https://xen.to/default',
            ], 201),
        ]);

        [$operator, $booking, $passenger] = $this->linkedRental();
        $this->approveBooking($operator, $booking, $passenger);

        $this->actingAs($passenger, 'sanctum')
            ->postJson("/api/bookings/{$booking->id}/xendit/checkout", $this->checkoutPayload('downpayment'))
            ->assertOk();

        Http::assertSent(function ($request): bool {
            $body = $request->data();

            return $request->url() === 'https://api.xendit.co/v3/payment_requests'
                && ($body['request_amount'] ?? null) === 450.0
                && str_starts_with(
                    (string) ($body['channel_properties']['success_return_url'] ?? ''),
                    'https://example.test/payment/xendit/success?',
                )
                && str_contains(
                    (string) ($body['channel_properties']['success_return_url'] ?? ''),
                    'booking_id=',
                )
                && str_starts_with(
                    (string) ($body['channel_properties']['failure_return_url'] ?? ''),
                    'https://example.test/payment/xendit/cancel?',
                )
                && str_contains(
                    (string) ($body['channel_properties']['failure_return_url'] ?? ''),
                    'booking_id=',
                );
        });
    }

    public function test_gateway_validation_error_is_not_a_generic_checkout_failure(): void
    {
        Http::fake([
            'https://api.xendit.co/v3/payment_requests' => Http::response([
                'error_code' => 'API_VALIDATION_ERROR',
                'message' => 'Please provide a valid HTTPS URL',
            ], 400),
        ]);

        [$operator, $booking, $passenger] = $this->linkedRental();
        $this->approveBooking($operator, $booking, $passenger);

        $this->actingAs($passenger, 'sanctum')
            ->postJson("/api/bookings/{$booking->id}/xendit/checkout", $this->checkoutPayload('downpayment'))
            ->assertStatus(502)
            ->assertJsonPath(
                'message',
                'GCash needs public HTTPS return addresses. Update the payment return URLs, then try again.',
            );
    }

    public function test_checkout_returns_503_when_secret_key_missing(): void
    {
        Config::set('services.xendit.secret_key', '');

        [$operator, $booking, $passenger] = $this->linkedRental();
        $this->approveBooking($operator, $booking, $passenger);

        $this->actingAs($passenger, 'sanctum')
            ->postJson("/api/bookings/{$booking->id}/xendit/checkout", $this->checkoutPayload('downpayment'))
            ->assertStatus(503)
            ->assertJsonPath(
                'message',
                'Xendit secret key is not configured on the server (XENDIT_SECRET_KEY).',
            );
    }

    public function test_full_rental_checkout_creates_pending_rental_payment(): void
    {
        Http::fake([
            'https://api.xendit.co/v3/payment_requests' => Http::response([
                'payment_session_id' => 'ps-full-rental',
                'payment_link_url' => 'https://xen.to/full-rental',
            ], 201),
        ]);

        [$operator, $booking, $passenger] = $this->linkedRental();
        $this->approveBooking($operator, $booking, $passenger);

        $this->actingAs($passenger, 'sanctum')
            ->postJson("/api/bookings/{$booking->id}/xendit/checkout", $this->checkoutPayload('full_rental'))
            ->assertOk()
            ->assertJsonPath('checkout_session_id', 'ps-full-rental');

        $this->assertDatabaseHas('rental_payments', [
            'boat_booking_id' => $booking->id,
            'provider' => 'xendit',
            'payment_type' => 'full_rental',
            'status' => 'pending',
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function postWebhook(array $payload): \Illuminate\Testing\TestResponse
    {
        return $this->call(
            'POST',
            '/api/xendit/webhook',
            [],
            [],
            [],
            [
                'HTTP_x-callback-token' => (string) config('services.xendit.webhook_token'),
                'CONTENT_TYPE' => 'application/json',
            ],
            json_encode($payload, JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function completedWebhookPayload(
        string $sessionId,
        string $paymentRequestId,
        int $amountMinor
    ): array {
        return [
            'event' => 'payment_session.completed',
            'mode' => 'TEST',
            'data' => [
                'payment_session_id' => $sessionId,
                'latest_payment_request_id' => $paymentRequestId,
                'amount' => \App\Support\XenditMoney::toApiAmountFromMinor($amountMinor),
                'currency' => 'PHP',
                'status' => 'COMPLETED',
                'updated' => now()->toIso8601String(),
            ],
        ];
    }
}
