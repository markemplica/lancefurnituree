<?php

namespace Tests\Feature;

use App\Models\FurnitureProduct;
use App\Models\User;
use App\Services\ChatPreferenceFilter;
use App\Services\RecommendationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PlannerCatalogFiltersTest extends TestCase
{
    use RefreshDatabase;

    private function product(string $category, array $attributes = []): FurnitureProduct
    {
        return FurnitureProduct::create(array_replace([
            'name' => $category.' sample', 'slug' => Str::slug($category).'-'.Str::random(8),
            'category' => $category, 'description' => 'Planner filter fixture',
            'wood_type' => 'Gmelina', 'style' => 'Modern', 'varnish_finish' => 'Natural',
            'price' => 5000, 'status' => 'active',
        ], $attributes));
    }

    public function test_planner_choices_match_catalog_choices_including_empty_categories_and_added_woods(): void
    {
        $this->product('Plain Door');
        $this->product('Special Door', ['wood_type' => 'Mangga', 'style' => 'Contemporary']);
        $this->product('Console', ['style' => 'Practical']);
        $this->product('Sala Set', ['status' => 'inactive', 'style' => 'Hidden style', 'wood_type' => 'Hidden wood', 'varnish_finish' => 'Hidden finish']);
        $this->actingAs(User::factory()->create());
        $options = $this->get(route('catalog.index'))->assertOk()->viewData('options');
        $planner = $this->get(route('preferences.edit'))->assertOk();
        $document = new \DOMDocument;
        @$document->loadHTML($planner->getContent());
        $xpath = new \DOMXPath($document);
        foreach (['categories' => ['furnitureTypes', 'furniture_type'], 'styles' => ['styles', 'style'],
            'woods' => ['woodTypes', 'wood_type'], 'finishes' => ['varnishFinishes', 'varnish_finish']] as $key => [$variable, $field]) {
            $this->assertSame($options[$key]->all(), $planner->viewData($variable)->all());
            $values = [];
            foreach ($xpath->query('//select[@name="'.$field.'"]/option') as $option) {
                $value = $option->hasAttribute('value') ? $option->getAttribute('value') : $option->textContent;
                if ($value !== '') $values[] = $value;
            }
            $this->assertSame($options[$key]->all(), $values);
        }
        $this->assertContains('Tanguile', $options['woods']);
        $this->assertContains('Narra', $options['woods']);
        $this->assertNotContains('Mangga', $options['woods']);
        $this->assertNotContains('Contemporary', $options['styles']);
        $this->assertNotContains('Practical', $options['styles']);
    }

    public function test_each_catalog_category_saves_and_recommends_only_that_exact_category(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $products = collect(config('furnicraft.catalog_categories'))->mapWithKeys(fn ($category) => [$category => $this->product($category)]);
        $this->product('Plain Door', ['status' => 'inactive']);
        foreach ($products as $category => $product) {
            $this->postJson(route('preferences.update'), ['furniture_type' => $category, 'budget_range' => '1000-10000',
                'wood_type' => 'Gmelina', 'style' => 'Modern', 'varnish_finish' => 'Natural'])
                ->assertOk()->assertJsonPath('preference.furniture_type', $category);
            $preference = $user->preference()->firstOrFail();
            $recommendation = app(RecommendationService::class)->generate($user, $preference, null, $category);
            $this->assertSame([$product->id], $recommendation->items()->pluck('furniture_product_id')->all());
            $this->get(route('preferences.edit'))->assertOk()->assertSee('value="'.$category.'" selected', false);
        }
        $this->postJson(route('preferences.update'), ['furniture_type' => 'Nonexistent category', 'budget_range' => '1000-10000'])
            ->assertUnprocessable()->assertJsonValidationErrors('furniture_type');
    }

    public function test_catalog_only_woods_save_and_strict_filters_do_not_return_other_products(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $this->product('Plain Door');
        foreach (['Tanguile', 'Narra'] as $wood) {
            $this->postJson(route('preferences.update'), ['furniture_type' => 'Plain Door', 'budget_range' => '1000-10000', 'wood_type' => $wood])
                ->assertOk()->assertJsonPath('preference.wood_type', $wood);
            $recommendation = app(RecommendationService::class)->generate($user, $user->preference()->firstOrFail(), null, 'Plain Door');
            $this->assertCount(0, $recommendation->items);
        }
        $this->postJson(route('preferences.update'), ['furniture_type' => 'Plain Door', 'budget_range' => '1000-10000',
            'wood_type' => 'Unknown wood', 'style' => 'Unknown style', 'varnish_finish' => 'Unknown finish'])
            ->assertUnprocessable()->assertJsonValidationErrors(['wood_type', 'style', 'varnish_finish']);
    }

    public function test_older_saved_selections_remain_visible_and_can_be_kept_or_replaced(): void
    {
        $user = User::factory()->create();
        $plain = $this->product('Plain Door', ['wood_type' => 'Mangga', 'style' => 'Practical']);
        $special = $this->product('Special Door', ['wood_type' => 'Mangga', 'style' => 'Practical']);
        $user->preference()->create(['furniture_type' => 'Door', 'budget_min' => 1000, 'budget_max' => 10000,
            'wood_type' => 'Mangga', 'style' => 'Practical', 'varnish_finish' => 'Retired finish']);
        $this->actingAs($user)->get(route('preferences.edit'))->assertOk()
            ->assertSee('value="Door" selected>Door (saved selection)', false)
            ->assertSee('value="Mangga" selected>Mangga (saved selection)', false)
            ->assertSee('value="Practical" selected>Practical (saved selection)', false)
            ->assertSee('value="Retired finish" selected>Retired finish (saved selection)', false);
        $this->postJson(route('preferences.update'), ['furniture_type' => 'Door', 'budget_range' => '1000-10000',
            'wood_type' => 'Mangga', 'style' => 'Practical', 'varnish_finish' => 'Retired finish'])->assertOk();
        $this->assertSame([$plain->id, $special->id], FurnitureProduct::active()->ofRecommendationType('Door')->orderBy('id')->pluck('id')->all());
        $this->postJson(route('preferences.update'), ['furniture_type' => 'Plain Door', 'budget_range' => '1000-10000',
            'wood_type' => null, 'style' => null, 'varnish_finish' => null])->assertOk();
        $this->get(route('preferences.edit'))->assertOk()->assertDontSee('(saved selection)');
    }

    public function test_chat_does_not_broaden_specific_door_category_to_all_doors(): void
    {
        $this->product('Plain Door');
        $user = User::factory()->create();
        $preference = $user->preference()->create(['furniture_type' => 'Door', 'budget_min' => 1000, 'budget_max' => 10000]);
        $parsed = app(ChatPreferenceFilter::class)->parse('show me Plain Door', $preference);
        $this->assertSame('Plain Door', $parsed['updates']['furniture_type']);
    }
}
