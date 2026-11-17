<?php

namespace App\Recipes;

enum RecipeVisibility: string
{
    case Private = 'private';
    case Shared = 'shared';
}
