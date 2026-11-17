<?php

namespace Database\Seeders\Staging;

use App\Models\Recipe;
use App\Models\User;
use App\Recipes\Images\RecipeImageStore;
use App\Recipes\RecipeService;
use App\Recipes\RecipeStateService;
use App\Recipes\RecipeVisibility;
use App\Recipes\Shopping\RecipeShoppingBridge;
use App\Shopping\ListStatus;
use Illuminate\Support\Facades\DB;

/**
 * Synthetic recipe book (Slovak, family dishes), created through the production services so the seed exercises the real
 * rules: RecipeService for the recipes, RecipeImageStore for the generated photos, RecipeStateService for ticks, done
 * steps, favourites, a note and a personal serving count, RecipeShoppingBridge for one "Add to shopping list" run into an
 * open demo list. Photos are flat coloured shapes drawn in memory with GD: no text, no faces, no network. The import
 * cannot be seeded (no AI runs from the seed), so one recipe only carries created_via "url" with a fictional host and no
 * recipe_imports row exists. The purge in StagingSeeder removes everything again before the next seed.
 */
final class RecipesSeeder
{
    /** Labels equal recipes.category_starter.* in Slovak, so the category chips group with the starter set. */
    private const CAT = [
        'breakfast' => 'Raňajky', 'lunch' => 'Obed', 'dinner' => 'Večera', 'soup' => 'Polievka', 'salad' => 'Šalát',
        'dessert' => 'Dezert', 'baking' => 'Pečenie', 'snack' => 'Desiata', 'drink' => 'Nápoj', 'vegetarian' => 'Vegetariánske',
    ];

    /** @var array<string, int> */
    private array $units = [];

    /** @return array{recipes: int, photos: int, shopping_added: int} */
    public function seed(StagingContext $ctx): array
    {
        $parent1 = $ctx->user('parent1');
        $parent2 = $ctx->user('parent2');
        $child1 = $ctx->user('child1');
        $grandparent = $ctx->user('grandparent');

        $this->units = DB::table('shopping_units')->whereNotNull('key')->pluck('id', 'key')->all();
        $recipes = app(RecipeService::class);
        $images = app(RecipeImageStore::class);
        $state = app(RecipeStateService::class);

        $make = function (User $author, array $data, int $photos = 0) use ($recipes, $images): Recipe {
            $recipe = $recipes->create($author, $data);
            for ($p = 0; $p < $photos; $p++) {
                $images->add($recipe, $this->photo($recipe->title, $p));
            }

            return $recipe;
        };
        $shared = RecipeVisibility::Shared;
        $private = RecipeVisibility::Private;

        $pancakes = $make($parent1, [
            'title' => 'Palacinky s džemom', 'description' => 'Tenké palacinky na nedeľné raňajky.', 'servings' => 4,
            'prep_minutes' => 10, 'cook_minutes' => 20, 'visibility' => $shared,
            'categories' => [self::CAT['breakfast'], self::CAT['dessert']],
            'ingredients' => [
                $this->ing('Múka', '250', 'g'), $this->ing('Mlieko', '500', 'ml'), $this->ing('Vajcia', '2', 'pcs'),
                $this->ing('Maslo', '30', 'g', 'na vymastenie'), $this->ing('Cukor', '2', 'tbsp'), $this->ing('Soľ', null, null, 'štipka'),
                $this->ing('Džem', '200', 'g'),
            ],
            'steps' => $this->steps([
                'Vo veľkej miske vyšľaháme vajcia s mliekom.', 'Pridáme múku, cukor a soľ a premiešame na hladké cesto.',
                'Cesto necháme 15 minút odpočívať.', 'Na rozohriatej panvici s trochou masla upečieme tenké palacinky.',
                'Palacinky potrieme džemom a zrolujeme.',
            ]),
        ], 1);

        $potatoSoup = $make($grandparent, [
            'title' => 'Zemiaková polievka', 'description' => 'Hustá polievka podľa babičky.', 'servings' => 6,
            'prep_minutes' => 15, 'cook_minutes' => 35, 'visibility' => $shared,
            'categories' => [self::CAT['soup'], self::CAT['lunch']],
            'ingredients' => [
                $this->ing('Zemiaky', '1', 'kg'), $this->ing('Mrkva', '2', 'pcs'), $this->ing('Cibuľa', '1', 'pcs'),
                $this->ing('Cesnak', '3', 'pcs', 'strúčiky'), $this->ing('Kôpor', '1', 'bunch'),
            ],
            'steps' => $this->steps([
                'Zemiaky, mrkvu a cibuľu nakrájame na kocky.', 'Zeleninu podusíme a zalejeme vodou.',
                'Varíme do mäkka, pridáme cesnak a kôpor.',
            ]),
        ], 1);

        $make($parent2, [
            'title' => 'Cestoviny s paradajkovou omáčkou', 'servings' => 4, 'prep_minutes' => 10, 'cook_minutes' => 25, 'visibility' => $shared,
            'categories' => [self::CAT['lunch'], self::CAT['vegetarian']],
            'ingredients' => [
                $this->ing('Cestoviny', '400', 'g'), $this->ing('Paradajkový pretlak', '1', 'pack'), $this->ing('Cibuľa', '1', 'pcs'),
                $this->ing('Olivový olej', '2', 'tbsp'), $this->ing('Bazalka', '1', 'bunch'),
            ],
            'steps' => $this->steps(['Uvaríme cestoviny podľa návodu.', 'Na oleji speníme cibuľu a pridáme pretlak.', 'Omáčku podusíme a zmiešame s cestovinami.']),
        ], 1);

        $make($child1, [
            'title' => 'Šalát z čerstvej zeleniny', 'servings' => 2, 'prep_minutes' => 10, 'visibility' => $private,
            'categories' => [self::CAT['salad'], self::CAT['vegetarian']],
            'ingredients' => [$this->ing('Paradajky', '3', 'pcs'), $this->ing('Uhorka', '1', 'pcs'), $this->ing('Olivový olej', '1', 'tbsp'), $this->ing('Soľ', null, null)],
            'steps' => $this->steps(['Zeleninu umyjeme a nakrájame.', 'Pokvapkáme olejom a posolíme.']),
        ], 1);

        $applePie = $make($grandparent, [
            'title' => 'Jablkový koláč', 'description' => 'Šťavnatý koláč s škoricou.', 'servings' => 8,
            'prep_minutes' => 30, 'cook_minutes' => 45, 'visibility' => $shared,
            'categories' => [self::CAT['baking'], self::CAT['dessert']],
            'ingredients' => [
                $this->ing('Jablká', '1.5', 'kg'), $this->ing('Múka', '300', 'g'), $this->ing('Maslo', '150', 'g'),
                $this->ing('Cukor', '120', 'g'), $this->ing('Vajcia', '2', 'pcs'), $this->ing('Škorica', '1', 'tsp'),
            ],
            'steps' => $this->steps([
                'Z múky, masla, cukru a vajec vypracujeme cesto.', 'Jablká postrúhame a premiešame so škoricou.',
                'Cesto s jablkami dáme do formy.', 'Pečieme 45 minút na 180 stupňov.',
            ]),
        ], 10);

        $make($parent1, [
            'title' => 'Domáca limonáda', 'servings' => 6, 'prep_minutes' => 10, 'visibility' => $private,
            'categories' => [self::CAT['drink']],
            'ingredients' => [$this->ing('Citróny', '4', 'pcs'), $this->ing('Cukor', '100', 'g'), $this->ing('Voda', '2', 'l'), $this->ing('Mäta', '1', 'bunch')],
            'steps' => $this->steps(['Citróny vyžmýkame a šťavu zmiešame s cukrom.', 'Zalejeme vodou a pridáme mätu.']),
        ], 1);

        $make($parent2, [
            'title' => 'Ovsená kaša s ovocím', 'servings' => null, 'prep_minutes' => 5, 'cook_minutes' => 10, 'visibility' => $private,
            'categories' => [self::CAT['breakfast']],
            'ingredients' => [$this->ing('Ovsené vločky', '80', 'g'), $this->ing('Mlieko', '250', 'ml'), $this->ing('Banány', '1', 'pcs'), $this->ing('Med', '1', 'tsp')],
            'steps' => $this->steps(['Vločky uvaríme v mlieku do zhustnutia.', 'Podávame s ovocím a medom.']),
        ]);

        $make($parent1, [
            'title' => 'Vyprážané kuracie rezne', 'description' => 'Množstvá, ktoré sa zle prepočítavajú.', 'servings' => 4,
            'prep_minutes' => 20, 'cook_minutes' => 20, 'visibility' => $shared,
            'categories' => [self::CAT['lunch'], self::CAT['dinner']],
            'ingredients' => [
                $this->ing('Kuracie prsia', '600', 'g'), $this->ing('Soľ', null, null, 'podľa chuti'), $this->ing('Vajce', '1', 'pcs'),
                $this->ing('Cesnak', '3', 'pcs', 'strúčiky'), $this->ing('Rasca', '0.5', 'tsp'), $this->ing('Strúhanka', '150', 'g'),
                $this->ing('Olej', '200', 'ml', 'na vyprážanie'),
            ],
            'steps' => $this->steps([
                'Prsia nakrájame na rezne a naklepeme.', 'Rezne osolíme a potrieme cesnakom s rascou.',
                'Obalíme v múke, vajci a strúhanke.', 'Vyprážame dozlatista z oboch strán.',
            ]),
        ], 1);

        $make($child1, [
            'title' => 'Chlebíčky na desiatu', 'servings' => 4, 'prep_minutes' => 10, 'visibility' => $shared,
            'categories' => [self::CAT['snack']],
            'ingredients' => [$this->ing('Chlieb', '8', 'pcs', 'krajce'), $this->ing('Maslo', '40', 'g'), $this->ing('Syr', '150', 'g'), $this->ing('Uhorka', '1', 'pcs')],
            'steps' => $this->steps(['Chlieb natrieme maslom.', 'Pridáme syr a uhorku.']),
        ], 1);

        $make($parent2, [
            'title' => 'Zemlovka', 'description' => 'Zapekaná sladká zemiaková zemlovka.', 'servings' => 6,
            'prep_minutes' => 25, 'cook_minutes' => 50, 'visibility' => $shared, 'created_via' => 'url',
            'source_url' => 'https://recepty.example/zemlovka',
            'categories' => [self::CAT['dessert'], self::CAT['baking']],
            'ingredients' => [$this->ing('Zemiaky', '800', 'g'), $this->ing('Jablká', '3', 'pcs'), $this->ing('Cukor', '3', 'tbsp'), $this->ing('Maslo', '50', 'g')],
            'steps' => $this->steps(['Uvarené zemiaky nakrájame na plátky.', 'Striedame vrstvy zemiakov a jabĺk.', 'Zapekáme do zlatista.']),
        ], 1);

        $make($grandparent, [
            'title' => 'Lečo s vajíčkom', 'servings' => 4, 'prep_minutes' => 15, 'cook_minutes' => 30, 'visibility' => $private,
            'categories' => [self::CAT['dinner'], self::CAT['vegetarian']],
            'ingredients' => [$this->ing('Paprika', '4', 'pcs'), $this->ing('Paradajky', '500', 'g'), $this->ing('Cibuľa', '2', 'pcs'), $this->ing('Vajcia', '4', 'pcs')],
            'steps' => $this->steps(['Zeleninu nakrájame a podusíme.', 'Do leča rozšľaháme vajcia a krátko prevaríme.']),
        ], 1);

        $make($grandparent, [
            'title' => 'Babičkina nedeľná slepačia polievka s domácimi rezancami, zeleninou a čerstvou petržlenovou vňaťou',
            'servings' => 6, 'prep_minutes' => 30, 'cook_minutes' => 120, 'visibility' => $shared,
            'categories' => [self::CAT['soup'], self::CAT['lunch']],
            'ingredients' => [$this->ing('Slepačie mäso', '1', 'kg'), $this->ing('Mrkva', '3', 'pcs'), $this->ing('Petržlen', '1', 'bunch'), $this->ing('Rezance', '200', 'g')],
            'steps' => $this->steps(['Mäso varíme dve hodiny v osolenej vode.', 'Pridáme zeleninu a rezance a dovaríme.']),
        ], 1);

        // Two members keep progress on the same shared recipe; a third has only a personal serving count.
        $pancakeIngredients = DB::table('recipe_ingredients')->where('recipe_id', $pancakes->id)->orderBy('position')->pluck('id')->all();
        $pancakeSteps = DB::table('recipe_steps')->where('recipe_id', $pancakes->id)->orderBy('position')->pluck('id')->all();
        foreach (array_slice($pancakeIngredients, 0, 4) as $id) {
            $state->tick($parent1, $pancakes->id, (int) $id, true);
        }
        foreach (array_slice($pancakeIngredients, 0, 2) as $id) {
            $state->tick($child1, $pancakes->id, (int) $id, true);
        }
        foreach (array_slice($pancakeSteps, 0, 2) as $id) {
            $state->stepDone($parent1, $pancakes->id, (int) $id, true);
        }
        $state->stepDone($child1, $pancakes->id, (int) $pancakeSteps[0], true);
        $state->servings($child1, $pancakes->id, 2);
        $state->favourite($parent1, $pancakes->id, true);
        $state->favourite($child1, $pancakes->id, true);
        $state->favourite($parent1, $potatoSoup->id, true);
        $state->favourite($grandparent, $applePie->id, true);
        $state->note($parent1, $pancakes->id, 'Deti ich majú najradšej s marhuľovým džemom, cesto stačí urobiť deň vopred.');
        $state->tick($grandparent, $potatoSoup->id, (int) DB::table('recipe_ingredients')->where('recipe_id', $potatoSoup->id)->orderBy('position')->value('id'), true);

        $added = $this->shoppingRun($parent1, $pancakes);

        return [
            'recipes' => Recipe::query()->count(),
            'photos' => DB::table('recipe_photos')->count(),
            'shopping_added' => $added,
        ];
    }

    /** One Add-to-shopping run: the preview rows (catalog matches, suggested units) go into the open weekly demo list. */
    private function shoppingRun(User $actor, Recipe $recipe): int
    {
        $bridge = app(RecipeShoppingBridge::class);
        $listId = (int) DB::table('shopping_lists')->where('name', 'Týždenný nákup')->where('status', ListStatus::Open->value)->value('id');
        $rows = [];
        foreach ($bridge->preview($actor, $recipe->id)['rows'] as $row) {
            $rows[] = [
                'catalog_item_id' => $row['match']['catalogItemId'],
                'name' => $row['name'],
                'quantity' => $row['quantity'] ?? '1',
                'unit_id' => $row['unitId'] ?? $row['suggestedUnitId'],
            ];
        }

        return $bridge->commit($actor, $recipe->id, 'existing', $listId, null, null, $rows)['count'];
    }

    /** @return array{name: string, quantity: ?string, unit_id: ?int, note: ?string} */
    private function ing(string $name, ?string $quantity, ?string $unit, ?string $note = null): array
    {
        return ['name' => $name, 'quantity' => $quantity, 'unit_id' => $unit === null ? null : $this->units[$unit], 'note' => $note];
    }

    /**
     * @param  list<string>  $bodies
     * @return list<array{body: string}>
     */
    private function steps(array $bodies): array
    {
        return array_map(fn (string $body) => ['body' => $body], $bodies);
    }

    /** Flat coloured shapes on a plain background, derived from the title and the photo number. No text, no faces. */
    private function photo(string $title, int $n): string
    {
        $h = crc32($title.'#'.$n);
        $img = imagecreatetruecolor(800, 600);
        $c = fn (int $shift) => imagecolorallocate($img, 80 + (($h >> $shift) & 0x7F), 80 + (($h >> ($shift + 7)) & 0x7F), 80 + (($h >> ($shift + 3)) & 0x7F));
        imagefilledrectangle($img, 0, 0, 800, 600, imagecolorallocate($img, 244, 240, 230));
        imagefilledellipse($img, 400, 330, 420, 420, $c(0));
        imagefilledellipse($img, 400, 330, 260, 260, imagecolorallocate($img, 250, 246, 238));
        if ($n % 2 === 0) {
            imagefilledrectangle($img, 120, 80, 280, 200, $c(5));
        } else {
            imagefilledpolygon($img, [620, 80, 700, 220, 540, 220], $c(9));
        }
        ob_start();
        imagejpeg($img, null, 82);
        $bytes = (string) ob_get_clean();
        imagedestroy($img);

        return $bytes;
    }
}
