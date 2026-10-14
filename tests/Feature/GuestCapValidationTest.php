<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\GlobalRate;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class GuestCapValidationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $user;

    private RoomType $roomTypeStandard; // max_guests = 2

    private RoomType $roomTypeSingle;   // max_guests = 1

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->user = User::factory()->create(['role' => 'user']);

        $this->roomTypeStandard = RoomType::create([
            'id' => Str::uuid(),
            'name_en' => 'Standard Room',
            'name_th' => 'ห้องสแตนดาร์ด',
            'max_guests' => 2,
            'extra_bed_enabled' => false,
        ]);

        GlobalRate::create([
            'rate_type' => 'daily',
            'room_type_id' => $this->roomTypeStandard->id,
            'name_en' => 'Standard Daily',
            'amount' => 1000,
        ]);

        for ($i = 1; $i <= 5; $i++) {
            Room::create([
                'id' => Str::uuid(),
                'room_type_id' => $this->roomTypeStandard->id,
                'room_number' => 'STD-10'.$i,
                'status' => 'available',
            ]);
        }

        $this->roomTypeSingle = RoomType::create([
            'id' => Str::uuid(),
            'name_en' => 'Single Room',
            'name_th' => 'ห้องเดี่ยว',
            'max_guests' => 1,
            'extra_bed_enabled' => false,
        ]);

        GlobalRate::create([
            'rate_type' => 'daily',
            'room_type_id' => $this->roomTypeSingle->id,
            'name_en' => 'Single Daily',
            'amount' => 800,
        ]);

        for ($i = 1; $i <= 5; $i++) {
            Room::create([
                'id' => Str::uuid(),
                'room_type_id' => $this->roomTypeSingle->id,
                'room_number' => 'SGL-20'.$i,
                'status' => 'available',
            ]);
        }
    }

    /**
     * Helper สำหรับทำ booking payload พื้นฐาน
     */
    private function baseBookingPayload(array $rooms): array
    {
        return [
            'source' => 'online',
            'booking_rooms' => $rooms,
        ];
    }

    // ============================================================
    // ชั้น 1 (REQ-007): Validation ตอนรับ guests array
    // ============================================================

    public function test_create_booking_exceeding_max_guests_returns_422(): void
    {
        $payload = $this->baseBookingPayload([
            [
                'room_type_id' => $this->roomTypeStandard->id,
                'check_in' => now()->addDays(2)->toDateString(),
                'check_out' => now()->addDays(4)->toDateString(),
                'guests' => [
                    ['name' => 'Guest 1'],
                    ['name' => 'Guest 2'],
                    ['name' => 'Guest 3'], // 3 คน เกิน max_guests = 2
                ],
            ],
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/bookings', $payload);

        $response->assertStatus(422)
            ->assertJson([
                'status' => 'error',
                'message' => 'ผู้เข้าพักเกินจำนวนสูงสุด 2 คนของห้องประเภทนี้ค่ะ 🥺',
            ]);
    }

    public function test_create_booking_within_max_guests_returns_201(): void
    {
        $payload = $this->baseBookingPayload([
            [
                'room_type_id' => $this->roomTypeStandard->id,
                'check_in' => now()->addDays(2)->toDateString(),
                'check_out' => now()->addDays(4)->toDateString(),
                'guests' => [
                    ['name' => 'Guest 1'],
                    ['name' => 'Guest 2'], // 2 คน เท่ากับ max_guests พอดี
                ],
            ],
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/bookings', $payload);

        $response->assertStatus(201)
            ->assertJson(['status' => 'success']);
    }

    public function test_create_booking_without_guests_succeeds_for_draft_flow(): void
    {
        $payload = $this->baseBookingPayload([
            [
                'room_type_id' => $this->roomTypeStandard->id,
                'check_in' => now()->addDays(2)->toDateString(),
                'check_out' => now()->addDays(4)->toDateString(),
                // ไม่ส่ง guests เลย (draft real-time flow)
            ],
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/bookings', $payload);

        $response->assertStatus(201)
            ->assertJson(['status' => 'success']);
    }

    public function test_add_rooms_exceeding_max_guests_returns_422(): void
    {
        // สร้าง draft booking เปล่าๆ ก่อน
        $booking = Booking::create([
            'id' => Str::uuid(),
            'user_id' => $this->user->id,
            'source' => 'online',
            'status' => 'draft',
            'total_amount' => 0,
            'payment_deadline' => now()->addHours(24),
        ]);

        $payload = [
            'booking_rooms' => [
                [
                    'room_type_id' => $this->roomTypeSingle->id,
                    'check_in' => now()->addDays(2)->toDateString(),
                    'check_out' => now()->addDays(3)->toDateString(),
                    'guests' => [
                        ['name' => 'Guest 1'],
                        ['name' => 'Guest 2'], // 2 คน เกิน Single (max = 1)
                    ],
                ],
            ],
        ];

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->id}/rooms", $payload);

        $response->assertStatus(422)
            ->assertJson([
                'status' => 'error',
                'message' => 'ผู้เข้าพักเกินจำนวนสูงสุด 1 คนของห้องประเภทนี้ค่ะ 🥺',
            ]);
    }

    public function test_add_rooms_within_max_guests_returns_200(): void
    {
        $booking = Booking::create([
            'id' => Str::uuid(),
            'user_id' => $this->user->id,
            'source' => 'online',
            'status' => 'draft',
            'total_amount' => 0,
            'payment_deadline' => now()->addHours(24),
        ]);

        $payload = [
            'booking_rooms' => [
                [
                    'room_type_id' => $this->roomTypeSingle->id,
                    'check_in' => now()->addDays(2)->toDateString(),
                    'check_out' => now()->addDays(3)->toDateString(),
                    'guests' => [
                        ['name' => 'Guest 1'], // 1 คน พอดี Single
                    ],
                ],
            ],
        ];

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->id}/rooms", $payload);

        $response->assertStatus(200)
            ->assertJson(['status' => 'success']);
    }

    public function test_update_single_room_exceeding_max_guests_returns_422(): void
    {
        $created = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/bookings', $this->baseBookingPayload([
                [
                    'room_type_id' => $this->roomTypeStandard->id,
                    'check_in' => now()->addDays(2)->toDateString(),
                    'check_out' => now()->addDays(4)->toDateString(),
                    'guests' => [['name' => 'Guest 1']],
                ],
            ]));
        $bookingId = $created->json('booking_id');
        $booking = Booking::findOrFail($bookingId);
        $roomId = $booking->bookingRooms->first()->id;

        $response = $this->actingAs($this->user, 'sanctum')
            ->putJson("/api/v1/bookings/{$bookingId}/rooms/{$roomId}", [
                'guests' => [
                    ['name' => 'Guest 1'],
                    ['name' => 'Guest 2'],
                    ['name' => 'Guest 3'], // 3 คน เกิน Standard (max = 2)
                ],
            ]);

        $response->assertStatus(422)
            ->assertJson([
                'status' => 'error',
                'message' => 'ผู้เข้าพักเกินจำนวนสูงสุด 2 คนของห้องประเภทนี้ค่ะ 🥺',
            ]);
    }

    public function test_update_single_room_changing_room_type_below_guest_count_returns_422(): void
    {
        // ห้องเดิม Standard มี 2 guests
        $created = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/bookings', $this->baseBookingPayload([
                [
                    'room_type_id' => $this->roomTypeStandard->id,
                    'check_in' => now()->addDays(2)->toDateString(),
                    'check_out' => now()->addDays(4)->toDateString(),
                    'guests' => [
                        ['name' => 'Guest 1'],
                        ['name' => 'Guest 2'],
                    ],
                ],
            ]));
        $bookingId = $created->json('booking_id');
        $booking = Booking::findOrFail($bookingId);
        $roomId = $booking->bookingRooms->first()->id;

        // เปลี่ยนประเภทห้องเป็น Single (max_guests = 1) โดยไม่ได้แก้ guests -> effectiveGuests ยังเป็น 2 คน
        $response = $this->actingAs($this->user, 'sanctum')
            ->putJson("/api/v1/bookings/{$bookingId}/rooms/{$roomId}", [
                'room_type_id' => $this->roomTypeSingle->id,
            ]);

        $response->assertStatus(422)
            ->assertJson([
                'status' => 'error',
                'message' => 'ผู้เข้าพักเกินจำนวนสูงสุด 1 คนของห้องประเภทนี้ค่ะ 🥺',
            ]);
    }

    public function test_update_single_room_within_max_guests_returns_200(): void
    {
        $created = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/bookings', $this->baseBookingPayload([
                [
                    'room_type_id' => $this->roomTypeStandard->id,
                    'check_in' => now()->addDays(2)->toDateString(),
                    'check_out' => now()->addDays(4)->toDateString(),
                ],
            ]));
        $bookingId = $created->json('booking_id');
        $booking = Booking::findOrFail($bookingId);
        $roomId = $booking->bookingRooms->first()->id;

        $response = $this->actingAs($this->user, 'sanctum')
            ->putJson("/api/v1/bookings/{$bookingId}/rooms/{$roomId}", [
                'guests' => [
                    ['name' => 'Guest A'],
                    ['name' => 'Guest B'],
                ],
            ]);

        $response->assertStatus(200)
            ->assertJson(['status' => 'success']);
    }

    public function test_update_rooms_batch_exceeding_max_guests_returns_422(): void
    {
        $created = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/bookings', $this->baseBookingPayload([
                [
                    'room_type_id' => $this->roomTypeStandard->id,
                    'check_in' => now()->addDays(2)->toDateString(),
                    'check_out' => now()->addDays(4)->toDateString(),
                ],
                [
                    'room_type_id' => $this->roomTypeSingle->id,
                    'check_in' => now()->addDays(2)->toDateString(),
                    'check_out' => now()->addDays(4)->toDateString(),
                ],
            ]));
        $bookingId = $created->json('booking_id');
        $booking = Booking::findOrFail($bookingId);
        $rooms = $booking->bookingRooms;

        $response = $this->actingAs($this->user, 'sanctum')
            ->putJson("/api/v1/bookings/{$bookingId}/rooms", [
                'booking_rooms' => [
                    [
                        'booking_room_id' => $rooms[0]->id,
                        'guests' => [['name' => 'G1']],
                    ],
                    [
                        'booking_room_id' => $rooms[1]->id,
                        'guests' => [['name' => 'G2'], ['name' => 'G3']], // เกิน Single (max = 1)
                    ],
                ],
            ]);

        $response->assertStatus(422)
            ->assertJson([
                'status' => 'error',
                'message' => 'ผู้เข้าพักเกินจำนวนสูงสุด 1 คนของห้องประเภทนี้ค่ะ 🥺',
            ]);
    }

    public function test_update_rooms_batch_within_max_guests_returns_200(): void
    {
        $created = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/bookings', $this->baseBookingPayload([
                [
                    'room_type_id' => $this->roomTypeStandard->id,
                    'check_in' => now()->addDays(2)->toDateString(),
                    'check_out' => now()->addDays(4)->toDateString(),
                ],
                [
                    'room_type_id' => $this->roomTypeSingle->id,
                    'check_in' => now()->addDays(2)->toDateString(),
                    'check_out' => now()->addDays(4)->toDateString(),
                ],
            ]));
        $bookingId = $created->json('booking_id');
        $booking = Booking::findOrFail($bookingId);
        $rooms = $booking->bookingRooms;

        $response = $this->actingAs($this->user, 'sanctum')
            ->putJson("/api/v1/bookings/{$bookingId}/rooms", [
                'booking_rooms' => [
                    [
                        'booking_room_id' => $rooms[0]->id,
                        'guests' => [['name' => 'G1'], ['name' => 'G2']],
                    ],
                    [
                        'booking_room_id' => $rooms[1]->id,
                        'guests' => [['name' => 'G3']],
                    ],
                ],
            ]);

        $response->assertStatus(200)
            ->assertJson(['status' => 'success']);
    }

    // ============================================================
    // ชั้น 2 (REQ-007): Validation ตอน BookingConfirmation confirm
    // ============================================================

    public function test_confirm_booking_with_missing_or_empty_guests_returns_422(): void
    {
        // สร้าง booking ที่ไม่มี guests
        $created = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/bookings', $this->baseBookingPayload([
                [
                    'room_type_id' => $this->roomTypeStandard->id,
                    'check_in' => now()->addDays(2)->toDateString(),
                    'check_out' => now()->addDays(4)->toDateString(),
                ],
            ]));
        $bookingId = $created->json('booking_id');

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/bookings/{$bookingId}/confirm", [
                'slip_image' => UploadedFile::fake()->image('slip.jpg', 800, 600),
                'amount' => 2000,
            ]);

        $response->assertStatus(422)
            ->assertJson([
                'status' => 'error',
                'message' => 'กรุณาระบุข้อมูลผู้เข้าพักอย่างน้อย 1 คนต่อห้องก่อนส่งหลักฐานการชำระเงินค่ะ 🥺',
            ]);
    }

    public function test_confirm_booking_with_all_rooms_having_at_least_one_guest_returns_201(): void
    {
        // สร้าง booking พร้อมระบุ guests >= 1 ทุกห้อง
        $created = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/bookings', $this->baseBookingPayload([
                [
                    'room_type_id' => $this->roomTypeStandard->id,
                    'check_in' => now()->addDays(2)->toDateString(),
                    'check_out' => now()->addDays(4)->toDateString(),
                    'guests' => [['name' => 'Somchai']],
                ],
            ]));
        $bookingId = $created->json('booking_id');

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/bookings/{$bookingId}/confirm", [
                'slip_image' => UploadedFile::fake()->image('slip.jpg', 800, 600),
                'amount' => 2000,
            ]);

        $response->assertStatus(201)
            ->assertJson(['status' => 'success']);
    }

    // ============================================================
    // REQ-019: ชื่อผู้เข้าพักซ้ำกันได้ ไม่ถูก reject
    // ============================================================

    public function test_req_019_duplicate_guest_names_are_allowed(): void
    {
        $payload = $this->baseBookingPayload([
            [
                'room_type_id' => $this->roomTypeStandard->id,
                'check_in' => now()->addDays(2)->toDateString(),
                'check_out' => now()->addDays(4)->toDateString(),
                'guests' => [
                    ['name' => 'Somchai Jaidee'],
                    ['name' => 'Somchai Jaidee'], // ชื่อซ้ำกัน 2 คน (ไม่เกิน max_guests = 2)
                ],
            ],
        ]);

        $created = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/bookings', $payload);

        $created->assertStatus(201)
            ->assertJson(['status' => 'success']);

        $bookingId = $created->json('booking_id');

        // confirm ก็ต้องผ่านด้วย
        $confirmResponse = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/bookings/{$bookingId}/confirm", [
                'slip_image' => UploadedFile::fake()->image('slip.jpg', 800, 600),
                'amount' => 2000,
            ]);

        $confirmResponse->assertStatus(201)
            ->assertJson(['status' => 'success']);
    }
}
