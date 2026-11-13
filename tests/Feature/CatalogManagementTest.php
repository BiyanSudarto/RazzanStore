<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Game;
use App\Models\GameField;
use App\Models\Product;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogManagementTest extends TestCase
{
    use RefreshDatabase;

    private function admin(string $roleCode): Admin
    {
        $role = Role::firstOrCreate(
            ['code' => $roleCode],
            ['name' => $roleCode === 'super_admin' ? 'Super Admin' : 'Admin'],
        );

        return Admin::create([
            'role_id' => $role->id,
            'name' => 'Test '.$roleCode,
            'email' => $roleCode.'-'.uniqid().'@razzanstore.test',
            'password' => 'StrongTestPassword123!',
            'is_active' => true,
        ]);
    }

    public function test_admin_can_create_game_and_dynamic_player_fields(): void
    {
        $this->actingAs($this->admin('admin'), 'admin');

        $this->postJson('/admin/api/games', [
            'slug' => 'mobile-legends',
            'name' => 'Mobile Legends',
            'is_active' => true,
        ])->assertCreated()
            ->assertJsonPath('data.slug', 'mobile-legends');

        $game = Game::where('slug', 'mobile-legends')->firstOrFail();

        $this->postJson('/admin/api/games/'.$game->id.'/fields', [
            'field_key' => 'player_id',
            'label' => 'Player ID',
            'input_type' => 'text',
            'is_required' => true,
            'validation_rules' => [
                'min_length' => 5,
                'max_length' => 20,
            ],
        ])->assertCreated()
            ->assertJsonPath('data.field_key', 'player_id');

        $this->assertDatabaseHas('game_fields', [
            'game_id' => $game->id,
            'field_key' => 'player_id',
        ]);
    }

    public function test_select_field_requires_options(): void
    {
        $this->actingAs($this->admin('admin'), 'admin');
        $game = Game::create(['slug' => 'free-fire', 'name' => 'Free Fire']);

        $this->postJson('/admin/api/games/'.$game->id.'/fields', [
            'field_key' => 'server',
            'label' => 'Server',
            'input_type' => 'select',
            'is_required' => true,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('options');
    }

    public function test_admin_cannot_create_or_change_product_prices(): void
    {
        $this->actingAs($this->admin('admin'), 'admin');
        $game = Game::create(['slug' => 'pubg-mobile', 'name' => 'PUBG Mobile']);

        $this->postJson('/admin/api/products', [
            'game_id' => $game->id,
            'sku' => 'PUBGM-60',
            'name' => '60 UC',
            'base_cost' => 10000,
            'selling_price' => 12000,
        ])->assertForbidden();

        $product = Product::create([
            'game_id' => $game->id,
            'sku' => 'PUBGM-60',
            'name' => '60 UC',
            'base_cost' => 10000,
            'selling_price' => 12000,
        ]);

        $this->patchJson('/admin/api/products/'.$product->id.'/price', [
            'base_cost' => 11000,
            'selling_price' => 13000,
        ])->assertForbidden();

        $this->assertDatabaseCount('price_histories', 0);
    }

    public function test_super_admin_price_change_is_transactionally_audited(): void
    {
        $this->actingAs($this->admin('super_admin'), 'admin');
        $game = Game::create(['slug' => 'genshin-impact', 'name' => 'Genshin Impact']);
        $product = Product::create([
            'game_id' => $game->id,
            'sku' => 'GEN-60',
            'name' => '60 Genesis Crystals',
            'base_cost' => 10000,
            'selling_price' => 12000,
        ]);

        $this->patchJson('/admin/api/products/'.$product->id.'/price', [
            'base_cost' => 10500,
            'selling_price' => 13000,
            'reason' => 'Penyesuaian harga vendor',
        ])->assertOk()
            ->assertJsonPath('data.base_cost', 10500)
            ->assertJsonPath('data.selling_price', 13000);

        $this->assertDatabaseHas('price_histories', [
            'product_id' => $product->id,
            'old_base_cost' => 10000,
            'new_base_cost' => 10500,
            'old_selling_price' => 12000,
            'new_selling_price' => 13000,
        ]);

        $this->assertDatabaseHas('admin_audit_logs', [
            'action' => 'product.price_changed',
            'target_id' => $product->id,
        ]);
    }

    public function test_public_catalog_exposes_only_active_products_and_not_cost(): void
    {
        $game = Game::create([
            'slug' => 'valorant',
            'name' => 'Valorant',
            'is_active' => true,
        ]);

        GameField::create([
            'game_id' => $game->id,
            'field_key' => 'riot_id',
            'label' => 'Riot ID',
            'input_type' => 'text',
            'is_required' => true,
        ]);

        Product::create([
            'game_id' => $game->id,
            'sku' => 'VAL-125',
            'name' => '125 Points',
            'base_cost' => 10000,
            'selling_price' => 12000,
            'is_active' => true,
        ]);

        Product::create([
            'game_id' => $game->id,
            'sku' => 'VAL-HIDDEN',
            'name' => 'Inactive Product',
            'base_cost' => 10000,
            'selling_price' => 12000,
            'is_active' => false,
        ]);

        $this->getJson('/api/v1/catalog/games/valorant')
            ->assertOk()
            ->assertJsonPath('data.products.0.sku', 'VAL-125')
            ->assertJsonMissingPath('data.products.0.base_cost')
            ->assertJsonMissing(['sku' => 'VAL-HIDDEN']);
    }
}
