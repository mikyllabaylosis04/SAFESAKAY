<?php

namespace Tests\Feature;

use App\Enums\BoatBookingStatus;
use App\Enums\BoatService;
use App\Enums\BoatStatus;
use App\Enums\BookingPaymentStatus;
use App\Enums\OperatorGcashStatus;
use App\Enums\UserRole;
use App\Models\Boat;
use App\Models\BoatBooking;
use App\Models\OperatorGcashAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OperatorGcashAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_operator_starts_not_registered(): void
    {
        $operator = $this->linkedOperator();

        $this->actingAs($operator, 'sanctum')
            ->getJson('/api/operator/gcash')
            ->assertOk()
            ->assertJsonPath('data.status', 'not_registered')
            ->assertJsonPath('data.status_label', 'Not Registered')
            ->assertJsonPath('data.can_approve', false)
            ->assertJsonPath('data.form', null);
    }

    public function test_operator_submission_is_pending_approval(): void
    {
        Storage::fake('public');
        $operator = $this->linkedOperator();

        $this->actingAs($operator, 'sanctum')
            ->post('/api/operator/gcash', [
                'account_name' => 'Juan Dela Cruz',
                'mobile_number' => '09171234567',
                'qr' => UploadedFile::fake()->image('gcash-qr.png', 400, 400),
            ])
            ->assertOk()
            ->assertJsonPath('message', 'GCash details submitted for approval.')
            ->assertJsonPath('data.status', 'pending_approval')
            ->assertJsonPath('data.status_label', 'Pending Approval')
            ->assertJsonPath('data.pending.account_name', 'Juan Dela Cruz')
            ->assertJsonPath('data.pending.mobile_number', '09171234567')
            ->assertJsonPath('data.approved', null);

        $this->assertDatabaseHas('operator_gcash_accounts', [
            'operator_id' => $operator->operatorProfiles()->first()?->id,
            'account_name' => 'Juan Dela Cruz',
            'mobile_number' => '09171234567',
            'status' => OperatorGcashStatus::PendingApproval->value,
        ]);
    }

    public function test_operator_cannot_self_approve(): void
    {
        Storage::fake('public');
        $operator = $this->linkedOperator();

        $this->actingAs($operator, 'sanctum')
            ->post('/api/operator/gcash', [
                'account_name' => 'Juan Dela Cruz',
                'mobile_number' => '09171234567',
                'status' => 'approved',
                'qr' => UploadedFile::fake()->image('gcash-qr.png', 400, 400),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    }

    public function test_editing_approved_account_creates_pending_without_replacing_live_account(): void
    {
        Storage::fake('public');
        $operator = $this->linkedOperator();
        $profile = $operator->operatorProfiles()->firstOrFail();
        $approvedPath = UploadedFile::fake()->image('approved.png')->store('operator-gcash/'.$profile->id, 'public');

        $approved = OperatorGcashAccount::query()->create([
            'operator_id' => $profile->id,
            'organization_id' => $operator->organization_id,
            'account_name' => 'Approved Name',
            'mobile_number' => '09170000000',
            'qr_path' => $approvedPath,
            'status' => OperatorGcashStatus::Approved,
            'submitted_at' => now()->subDay(),
            'reviewed_at' => now()->subDay(),
        ]);

        $this->actingAs($operator, 'sanctum')
            ->post('/api/operator/gcash', [
                'account_name' => 'Updated Name',
                'mobile_number' => '09179999999',
                'qr' => UploadedFile::fake()->image('pending.png', 400, 400),
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'pending_approval')
            ->assertJsonPath('data.pending.account_name', 'Updated Name')
            ->assertJsonPath('data.pending.mobile_number', '09179999999')
            ->assertJsonPath('data.approved.account_name', 'Approved Name')
            ->assertJsonPath('data.approved.mobile_number', '09170000000');

        $this->assertSame('Approved Name', $approved->fresh()->account_name);
        $this->assertSame(OperatorGcashStatus::Approved, $approved->fresh()->status);
        $this->assertSame($approvedPath, $approved->fresh()->qr_path);
        $this->assertNotSame($approvedPath, OperatorGcashAccount::query()
            ->where('status', OperatorGcashStatus::PendingApproval)
            ->value('qr_path'));
    }

    public function test_passenger_cannot_manage_operator_gcash(): void
    {
        $operator = $this->linkedOperator();
        $passenger = User::factory()->create([
            'organization_id' => $operator->organization_id,
            'role' => UserRole::Passenger,
        ]);

        $this->actingAs($passenger, 'sanctum')
            ->getJson('/api/operator/gcash')
            ->assertForbidden();
    }

    public function test_operator_cannot_view_another_operators_qr(): void
    {
        Storage::fake('public');
        $owner = $this->linkedOperator();
        $other = $this->linkedOperator();
        $profile = $owner->operatorProfiles()->firstOrFail();
        $path = UploadedFile::fake()->image('qr.png')->store('operator-gcash/'.$profile->id, 'public');
        $account = OperatorGcashAccount::query()->create([
            'operator_id' => $profile->id,
            'account_name' => 'Owner GCash',
            'mobile_number' => '09171111111',
            'qr_path' => $path,
            'status' => OperatorGcashStatus::PendingApproval,
            'submitted_at' => now(),
        ]);

        $this->actingAs($other, 'sanctum')
            ->get('/api/operator/gcash/qr?account='.$account->id)
            ->assertNotFound();
    }

    public function test_passenger_sees_barangay_down_payment_and_approved_operator_balance_gcash(): void
    {
        Storage::fake('public');
        [$operator, $booking, $passenger] = $this->linkedRental();
        $profile = $operator->operatorProfiles()->firstOrFail();
        $approvedPath = UploadedFile::fake()->image('live.png')->store('operator-gcash/'.$profile->id, 'public');
        $pendingPath = UploadedFile::fake()->image('next.png')->store('operator-gcash/'.$profile->id, 'public');

        OperatorGcashAccount::query()->create([
            'operator_id' => $profile->id,
            'account_name' => 'Live Operator GCash',
            'mobile_number' => '09171230000',
            'qr_path' => $approvedPath,
            'status' => OperatorGcashStatus::Approved,
            'submitted_at' => now()->subDay(),
            'reviewed_at' => now()->subDay(),
        ]);
        OperatorGcashAccount::query()->create([
            'operator_id' => $profile->id,
            'account_name' => 'Pending Operator GCash',
            'mobile_number' => '09171239999',
            'qr_path' => $pendingPath,
            'status' => OperatorGcashStatus::PendingApproval,
            'submitted_at' => now(),
        ]);

        $this->actingAs($operator, 'sanctum')->postJson("/api/bookings/{$booking->id}/approve")->assertOk();

        $this->actingAs($passenger, 'sanctum')
            ->getJson("/api/bookings/{$booking->id}")
            ->assertOk()
            ->assertJsonPath('data.gcash_account_name', 'Barangay Official GCash')
            ->assertJsonPath('data.balance_gcash_account_name', 'Live Operator GCash')
            ->assertJsonPath('data.balance_gcash_mobile_number', '09171230000')
            ->assertJsonPath('data.status', 'awaiting_payment');

        $qrUrl = $this->actingAs($passenger, 'sanctum')
            ->getJson("/api/bookings/{$booking->id}")
            ->json('data.balance_gcash_qr_url');

        $this->assertIsString($qrUrl);
        $this->assertStringContainsString('storage/'.$approvedPath, $qrUrl);
        $this->assertStringNotContainsString($pendingPath, $qrUrl);
    }

    public function test_passenger_does_not_see_pending_operator_gcash(): void
    {
        Storage::fake('public');
        [$operator, $booking, $passenger] = $this->linkedRental();
        $profile = $operator->operatorProfiles()->firstOrFail();
        $pendingPath = UploadedFile::fake()->image('pending.png')->store('operator-gcash/'.$profile->id, 'public');

        OperatorGcashAccount::query()->create([
            'operator_id' => $profile->id,
            'account_name' => 'Pending Operator GCash',
            'mobile_number' => '09171239999',
            'qr_path' => $pendingPath,
            'status' => OperatorGcashStatus::PendingApproval,
            'submitted_at' => now(),
        ]);

        $this->actingAs($operator, 'sanctum')->postJson("/api/bookings/{$booking->id}/approve")->assertOk();

        $payload = $this->actingAs($passenger, 'sanctum')
            ->getJson("/api/bookings/{$booking->id}")
            ->assertOk()
            ->json('data');

        $this->assertNotSame('Pending Operator GCash', $payload['gcash_account_name']);
        $this->assertStringNotContainsString($pendingPath, (string) $payload['gcash_qr_url']);
    }

    public function test_admin_can_approve_pending_operator_gcash(): void
    {
        Storage::fake('public');
        $operator = $this->linkedOperator();
        $admin = User::query()
            ->where('organization_id', $operator->organization_id)
            ->where('role', UserRole::Administrator)
            ->firstOrFail();
        $profile = $operator->operatorProfiles()->firstOrFail();
        $path = UploadedFile::fake()->image('pending.png')->store('operator-gcash/'.$profile->id, 'public');
        $account = OperatorGcashAccount::query()->create([
            'operator_id' => $profile->id,
            'organization_id' => $operator->organization_id,
            'account_name' => 'Juan Dela Cruz',
            'mobile_number' => '09171234567',
            'qr_path' => $path,
            'status' => OperatorGcashStatus::PendingApproval,
            'submitted_at' => now(),
        ]);

        $this->actingAs($admin)
            ->from(route('settings.payments.edit'))
            ->post(route('settings.payments.operator-gcash.approve', $account))
            ->assertRedirect(route('operator-payouts.index', ['view' => 'accounts']));

        $this->assertSame(OperatorGcashStatus::Approved, $account->fresh()->status);
        $this->assertNotNull($account->fresh()->reviewed_at);
    }

    public function test_admin_reject_keeps_previous_approved_account(): void
    {
        Storage::fake('public');
        $operator = $this->linkedOperator();
        $admin = User::query()
            ->where('organization_id', $operator->organization_id)
            ->where('role', UserRole::Administrator)
            ->firstOrFail();
        $profile = $operator->operatorProfiles()->firstOrFail();
        $approvedPath = UploadedFile::fake()->image('live.png')->store('operator-gcash/'.$profile->id, 'public');
        $pendingPath = UploadedFile::fake()->image('next.png')->store('operator-gcash/'.$profile->id, 'public');

        $approved = OperatorGcashAccount::query()->create([
            'operator_id' => $profile->id,
            'organization_id' => $operator->organization_id,
            'account_name' => 'Live Operator GCash',
            'mobile_number' => '09170000000',
            'qr_path' => $approvedPath,
            'status' => OperatorGcashStatus::Approved,
            'submitted_at' => now()->subDay(),
            'reviewed_at' => now()->subDay(),
        ]);
        $pending = OperatorGcashAccount::query()->create([
            'operator_id' => $profile->id,
            'organization_id' => $operator->organization_id,
            'account_name' => 'Updated Name',
            'mobile_number' => '09179999999',
            'qr_path' => $pendingPath,
            'status' => OperatorGcashStatus::PendingApproval,
            'submitted_at' => now(),
        ]);

        $this->actingAs($admin)
            ->post(route('settings.payments.operator-gcash.reject', $pending), ['rejection_reason' => 'QR code is unreadable.'])
            ->assertRedirect(route('operator-payouts.index', ['view' => 'accounts']));

        $this->assertSame(OperatorGcashStatus::Rejected, $pending->fresh()->status);
        $this->assertSame(OperatorGcashStatus::Approved, $approved->fresh()->status);
        $this->assertSame('Live Operator GCash', $approved->fresh()->account_name);
    }

    public function test_rejected_pdf_upload_is_rejected(): void
    {
        Storage::fake('public');
        $operator = $this->linkedOperator();

        $this->actingAs($operator, 'sanctum')
            ->post('/api/operator/gcash', [
                'account_name' => 'Juan Dela Cruz',
                'mobile_number' => '09171234567',
                'qr' => UploadedFile::fake()->create('qr.pdf', 120, 'application/pdf'),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('qr');
    }

    private function linkedOperator(): User
    {
        $admin = User::factory()->create(['role' => UserRole::Administrator]);
        $admin->organization?->update([
            'gcash_account_name' => 'Barangay Official GCash',
            'gcash_qr_path' => 'gcash-qr/barangay.png',
        ]);
        $operator = User::factory()->create([
            'organization_id' => $admin->organization_id,
            'role' => UserRole::Operator,
        ]);
        $boat = Boat::factory()->create([
            'organization_id' => $admin->organization_id,
            'status' => BoatStatus::Available,
            'service_type' => 'both',
            'service_availability' => BoatService::values(),
        ]);
        $boat->operator?->update(['user_id' => $operator->id]);

        return $operator->fresh();
    }

    /**
     * @return array{0: User, 1: BoatBooking, 2: User}
     */
    private function linkedRental(): array
    {
        $operator = $this->linkedOperator();
        $passenger = User::factory()->create([
            'organization_id' => $operator->organization_id,
            'role' => UserRole::Passenger,
            'name' => 'Maria Santos',
        ]);
        $boat = $operator->operatorProfiles()->firstOrFail()->boat;

        $booking = BoatBooking::query()->create([
            'organization_id' => $operator->organization_id,
            'boat_id' => $boat?->id,
            'user_id' => $passenger->id,
            'code' => 'BR-2026-00077',
            'customer_name' => 'Maria Santos',
            'package' => 'Sagisi',
            'destination' => 'Sagisi',
            'booking_date' => '2026-10-12',
            'start_time' => '09:00',
            'passenger_count' => 2,
            'status' => BoatBookingStatus::Pending,
            'rental_price' => '4500.00',
            'down_payment_percent' => '20.00',
            'down_payment_amount' => '900.00',
            'remaining_balance' => '3600.00',
            'payment_status' => BookingPaymentStatus::Unpaid,
        ]);

        return [$operator, $booking, $passenger];
    }
}
