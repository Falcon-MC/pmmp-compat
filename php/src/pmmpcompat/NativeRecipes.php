<?php

declare(strict_types=1);

namespace pmmpcompat;

use pocketmine\crafting\ExactRecipeIngredient;
use pocketmine\crafting\ShapedRecipe;
use pocketmine\crafting\ShapelessRecipe;

final class NativeRecipes{
    /** @var array<string, true> */
    private static array $published = [];

    public static function register(ShapedRecipe|ShapelessRecipe $recipe) : void{
        $results = $recipe->getResults();
        if(count($results) !== 1 || $results[0]->hasNamedTag() || $results[0]->getDamage() !== 0){
            return;
        }
        $shaped = $recipe instanceof ShapedRecipe;
        $width = $shaped ? $recipe->getWidth() : 0;
        $height = $shaped ? $recipe->getHeight() : 0;
        $inputs = $shaped ? array_merge(...$recipe->getIngredientMap()) : $recipe->getIngredientList();
        $identifiers = [];
        foreach($inputs as $ingredient){
            if($ingredient === null){
                $identifiers[] = null;
                continue;
            }
            if(!$ingredient instanceof ExactRecipeIngredient){
                return;
            }
            $item = $ingredient->getItem();
            if($item->getDamage() !== 0 || $item->hasNamedTag()){
                return;
            }
            $identifiers[] = $item->getFalconIdentifier();
        }
        $result = $results[0]->getFalconIdentifier();
        $count = $results[0]->getCount();
        $id = "pmmpcompat:" . hash("sha256", serialize([$width, $height, $identifiers, $result, $count]));
        if(!isset(self::$published[$id]) && falcon_register_recipe($id, $width, $height, $identifiers, $result, $count)){
            self::$published[$id] = true;
        }
    }
}
