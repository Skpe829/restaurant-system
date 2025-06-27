<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Recipe extends Model
{
    use HasUuids;

    protected $table = 'restaurant-recipes-dev';

    protected $fillable = [
        'name',
        'description',
        'ingredients',
        'preparation_time',
        'is_active'
    ];

    protected $casts = [
        'ingredients' => 'array',
        'preparation_time' => 'integer',
        'is_active' => 'boolean'
    ];

    // 6 recetas predefinidas
    public static function getAvailableRecipes(): array
    {
        return [
            [
                'id' => '1',
                'name' => 'Margherita Pizza',
                'description' => 'Classic Italian pizza with tomato, cheese and onion',
                'ingredients' => [
                    'tomato' => 3,
                    'cheese' => 3,
                    'onion' => 2,
                    'flour' => 4,
                    'olive_oil' => 1
                ],
                'preparation_time' => 25,
                'is_active' => true
            ],
            [
                'id' => '2',
                'name' => 'Caesar Salad',
                'description' => 'Fresh romaine lettuce with cheese and onion',
                'ingredients' => [
                    'lettuce' => 4,
                    'cheese' => 2,
                    'onion' => 1,
                    'lemon' => 1,
                    'croutons' => 2
                ],
                'preparation_time' => 15,
                'is_active' => true
            ],
            [
                'id' => '3',
                'name' => 'Grilled Chicken',
                'description' => 'Juicy grilled chicken with lemon and onion',
                'ingredients' => [
                    'chicken' => 5,
                    'lemon' => 2,
                    'onion' => 2,
                    'potato' => 3,
                    'olive_oil' => 2
                ],
                'preparation_time' => 35,
                'is_active' => true
            ],
            [
                'id' => '4',
                'name' => 'Classic Burger',
                'description' => 'Delicious burger with meat, cheese and fresh vegetables',
                'ingredients' => [
                    'meat' => 4,
                    'cheese' => 2,
                    'lettuce' => 2,
                    'tomato' => 2,
                    'onion' => 1
                ],
                'preparation_time' => 20,
                'is_active' => true
            ],
            [
                'id' => '5',
                'name' => 'Meat and Rice Bowl',
                'description' => 'Hearty rice bowl with seasoned meat and cheese',
                'ingredients' => [
                    'rice' => 4,
                    'meat' => 3,
                    'cheese' => 2,
                    'onion' => 2,
                    'tomato' => 2
                ],
                'preparation_time' => 18,
                'is_active' => true
            ],
            [
                'id' => '6',
                'name' => 'Chicken Rice Bowl',
                'description' => 'Fresh chicken rice bowl with lemon and vegetables',
                'ingredients' => [
                    'chicken' => 4,
                    'rice' => 3,
                    'lemon' => 2,
                    'lettuce' => 2,
                    'cheese' => 1
                ],
                'preparation_time' => 22,
                'is_active' => true
            ]
        ];
    }

    public static function getRandomRecipe(): array
    {
        $recipes = self::getAvailableRecipes();
        $randomIndex = array_rand($recipes);
        return $recipes[$randomIndex];
    }

    public static function selectMultipleRandomRecipes(int $quantity): array
    {
        $selectedRecipes = [];

        for ($i = 0; $i < $quantity; $i++) {
            $selectedRecipes[] = self::getRandomRecipe();
        }

        return $selectedRecipes;
    }
}