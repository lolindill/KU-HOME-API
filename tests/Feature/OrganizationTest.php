<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 🏛️ Organization CRUD — wayfinder/organization-bookings ticket 01/05
 *
 * contract (ticket 01): erp = string unique nullable (logical FK ภายนอก) ·
 * CRUD เต็มใต้ role:admin · ไม่มี DELETE · เลิกใช้ = is_active + toggle
 */
class OrganizationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    public function test_admin_can_create_organization_with_erp(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/organizations', [
            'erp' => 'KU-ORG-001',
            'name' => 'คณะวิศวกรรมศาสตร์',
        ]);

        $response->assertStatus(201)
            ->assertJson(['status' => 'success']);

        $org = Organization::where('erp', 'KU-ORG-001')->firstOrFail();
        $this->assertSame('คณะวิศวกรรมศาสตร์', $org->name);
        // PgBoolean — default true
        $this->assertTrue($org->is_active);
    }

    public function test_admin_can_create_organization_without_erp(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/organizations', [
            'name' => 'องค์กรยังไม่มีรหัส ERP',
        ]);

        $response->assertStatus(201);

        $org = Organization::where('name', 'องค์กรยังไม่มีรหัส ERP')->firstOrFail();
        $this->assertNull($org->erp);
        $this->assertTrue($org->is_active);
    }

    public function test_duplicate_erp_is_rejected(): void
    {
        Organization::create(['erp' => 'KU-DUP', 'name' => 'องค์กรแรก']);

        $response = $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/organizations', [
            'erp' => 'KU-DUP',
            'name' => 'องค์กรที่สอง',
        ]);

        $response->assertStatus(422);
        $this->assertSame(1, Organization::where('erp', 'KU-DUP')->count());
    }

    public function test_non_admin_cannot_access_organization_endpoints(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $org = Organization::create(['erp' => 'KU-403', 'name' => 'องค์กรหวงห้าม']);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/organizations')
            ->assertStatus(403);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/organizations', ['name' => 'โจร'])
            ->assertStatus(403);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/organizations/{$org->id}")
            ->assertStatus(403);

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/organizations/{$org->id}", ['name' => 'โจรแก้'])
            ->assertStatus(403);

        $this->actingAs($user, 'sanctum')
            ->patchJson("/api/v1/organizations/{$org->id}/toggle")
            ->assertStatus(403);
    }

    public function test_admin_can_search_organizations_by_name_and_erp(): void
    {
        Organization::create(['erp' => 'KU-ENG', 'name' => 'คณะวิศวกรรมศาสตร์']);
        Organization::create(['erp' => 'KU-AGRI', 'name' => 'คณะเกษตร']);
        Organization::create(['erp' => null, 'name' => 'หอพักนิสิต']);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/organizations?search=วิศว');
        $response->assertStatus(200);
        $this->assertCount(1, $response->json('organizations'));
        $this->assertSame('KU-ENG', $response->json('organizations.0.erp'));

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/organizations?search=KU-AGRI');
        $response->assertStatus(200);
        $this->assertCount(1, $response->json('organizations'));
        $this->assertSame('คณะเกษตร', $response->json('organizations.0.name'));
    }

    public function test_admin_can_filter_organizations_by_is_active(): void
    {
        Organization::create(['name' => 'องค์กรเปิด']);
        Organization::create(['name' => 'องค์กรปิด', 'is_active' => false]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/organizations?is_active=false');
        $response->assertStatus(200);
        $this->assertCount(1, $response->json('organizations'));
        $this->assertSame('องค์กรปิด', $response->json('organizations.0.name'));
    }

    public function test_admin_can_show_organization_by_uuid(): void
    {
        $org = Organization::create(['erp' => 'KU-SHOW', 'name' => 'องค์กรโชว์']);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/v1/organizations/{$org->id}");
        $response->assertStatus(200);
        $this->assertSame('KU-SHOW', $response->json('organization.erp'));

        // ไม่พบ → 404
        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/organizations/'.fake()->uuid())
            ->assertStatus(404);
    }

    public function test_admin_can_update_organization(): void
    {
        $org = Organization::create(['erp' => 'KU-OLD', 'name' => 'ชื่อเดิม']);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/v1/organizations/{$org->id}", [
                'name' => 'ชื่อใหม่',
                'erp' => 'KU-NEW',
            ]);
        $response->assertStatus(200);

        $org->refresh();
        $this->assertSame('ชื่อใหม่', $org->name);
        $this->assertSame('KU-NEW', $org->erp);
    }

    public function test_update_erp_conflicts_with_other_organization(): void
    {
        Organization::create(['erp' => 'KU-A', 'name' => 'องค์กร A']);
        $org = Organization::create(['erp' => 'KU-B', 'name' => 'องค์กร B']);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/v1/organizations/{$org->id}", ['erp' => 'KU-A']);
        $response->assertStatus(422);
    }

    public function test_admin_can_toggle_organization_active(): void
    {
        $org = Organization::create(['name' => 'องค์กรจะปิด', 'is_active' => true]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/v1/organizations/{$org->id}/toggle");
        $response->assertStatus(200);
        $this->assertFalse($response->json('organization.is_active'));
        $this->assertFalse($org->refresh()->is_active);

        // toggle กลับ = เปิดใหม่
        $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/v1/organizations/{$org->id}/toggle")
            ->assertStatus(200);
        $this->assertTrue($org->refresh()->is_active);
    }

    public function test_organizations_have_no_delete_endpoint(): void
    {
        $org = Organization::create(['name' => 'ห้ามลบ']);

        // ไม่มี DELETE route ตาม precedent discounts — 405 (method not allowed)
        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/v1/organizations/{$org->id}")
            ->assertStatus(405);

        $this->assertDatabaseHas('organizations', ['id' => $org->id]);
    }
}
