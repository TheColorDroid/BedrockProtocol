<?php

/*
 *
 *  ____            _        _   __  __ _                  __  __ ____
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author PocketMine Team
 * @link http://www.pocketmine.net/
 *
 *
*/

declare(strict_types=1);

namespace pocketmine\network\mcpe\protocol;

#include <rules/DataPacket.h>

use pocketmine\network\mcpe\protocol\serializer\PacketSerializer;
use pocketmine\network\mcpe\protocol\types\recipe\FurnaceRecipe;
use pocketmine\network\mcpe\protocol\types\recipe\MaterialReducerRecipe;
use pocketmine\network\mcpe\protocol\types\recipe\MaterialReducerRecipeOutput;
use pocketmine\network\mcpe\protocol\types\recipe\MultiRecipe;
use pocketmine\network\mcpe\protocol\types\recipe\PotionContainerChangeRecipe;
use pocketmine\network\mcpe\protocol\types\recipe\PotionTypeRecipe;
use pocketmine\network\mcpe\protocol\types\recipe\RecipeWithTypeId;
use pocketmine\network\mcpe\protocol\types\recipe\ShapedRecipe;
use pocketmine\network\mcpe\protocol\types\recipe\ShapelessRecipe;
use function count;

class CraftingDataPacket extends DataPacket implements ClientboundPacket{
	public const NETWORK_ID = ProtocolInfo::CRAFTING_DATA_PACKET;

	public const ENTRY_SHAPELESS = 0;
	public const ENTRY_SHAPED = 1;
	public const ENTRY_FURNACE = 2;
	public const ENTRY_FURNACE_DATA = 3;
	public const ENTRY_MULTI = 4;
	public const ENTRY_SHULKER_BOX = 5;
	public const ENTRY_SHAPELESS_CHEMISTRY = 6;
	public const ENTRY_SHAPED_CHEMISTRY = 7;

	/** @var RecipeWithTypeId[] */
	public array $recipesWithTypeIds = [];
	/** @var PotionTypeRecipe[] */
	public array $potionTypeRecipes = [];
	/** @var PotionContainerChangeRecipe[] */
	public array $potionContainerRecipes = [];
	/** @var MaterialReducerRecipe[] */
	public array $materialReducerRecipes = [];
	public bool $cleanRecipes = false;

	/**
	 * @generate-create-func
	 * @param RecipeWithTypeId[]            $recipesWithTypeIds
	 * @param PotionTypeRecipe[]            $potionTypeRecipes
	 * @param PotionContainerChangeRecipe[] $potionContainerRecipes
	 * @param MaterialReducerRecipe[]       $materialReducerRecipes
	 */
	public static function create(array $recipesWithTypeIds, array $potionTypeRecipes, array $potionContainerRecipes, array $materialReducerRecipes, bool $cleanRecipes) : self{
		$result = new self;
		$result->recipesWithTypeIds = $recipesWithTypeIds;
		$result->potionTypeRecipes = $potionTypeRecipes;
		$result->potionContainerRecipes = $potionContainerRecipes;
		$result->materialReducerRecipes = $materialReducerRecipes;
		$result->cleanRecipes = $cleanRecipes;
		return $result;
	}

	protected function decodePayload(PacketSerializer $in) : void{
		$recipeCount = $in->getUnsignedVarInt();
		for($i = 0; $i < $recipeCount; ++$i){
			$recipeType = $in->getVarInt();

			switch($recipeType){
				case self::ENTRY_SHAPELESS:
				case self::ENTRY_SHULKER_BOX:
				case self::ENTRY_SHAPELESS_CHEMISTRY:
					$this->recipesWithTypeIds[] = ShapelessRecipe::decode($recipeType, $in);
					break;
				case self::ENTRY_SHAPED:
				case self::ENTRY_SHAPED_CHEMISTRY:
					$this->recipesWithTypeIds[] = ShapedRecipe::decode($recipeType, $in);
					break;
				case self::ENTRY_FURNACE:
				case self::ENTRY_FURNACE_DATA:
					$this->recipesWithTypeIds[] = FurnaceRecipe::decode($recipeType, $in);
					break;
				case self::ENTRY_MULTI:
					$this->recipesWithTypeIds[] = MultiRecipe::decode($recipeType, $in);
					break;
				default:
					throw new PacketDecodeException("Unhandled recipe type $recipeType!"); //do not continue attempting to decode
			}
		}
		for($i = 0, $count = $in->getUnsignedVarInt(); $i < $count; ++$i){
			$inputId = $in->getVarInt();
			$inputMeta = $in->getVarInt();
			$ingredientId = $in->getVarInt();
			$ingredientMeta = $in->getVarInt();
			$outputId = $in->getVarInt();
			$outputMeta = $in->getVarInt();
			$this->potionTypeRecipes[] = new PotionTypeRecipe($inputId, $inputMeta, $ingredientId, $ingredientMeta, $outputId, $outputMeta);
		}
		for($i = 0, $count = $in->getUnsignedVarInt(); $i < $count; ++$i){
			$input = $in->getVarInt();
			$ingredient = $in->getVarInt();
			$output = $in->getVarInt();
			$this->potionContainerRecipes[] = new PotionContainerChangeRecipe($input, $ingredient, $output);
		}
		for($i = 0, $count = $in->getUnsignedVarInt(); $i < $count; ++$i){
			$inputIdAndData = $in->getVarInt();
			[$inputId, $inputMeta] = [$inputIdAndData >> 16, $inputIdAndData & 0x7fff];
			$outputs = [];
			for($j = 0, $outputCount = $in->getUnsignedVarInt(); $j < $outputCount; ++$j){
				$outputItemId = $in->getVarInt();
				$outputItemCount = $in->getVarInt();
				$outputs[] = new MaterialReducerRecipeOutput($outputItemId, $outputItemCount);
			}
			$this->materialReducerRecipes[] = new MaterialReducerRecipe($inputId, $inputMeta, $outputs);
		}
		$this->cleanRecipes = $in->getBool();
	}

	protected function encodePayload(PacketSerializer $out) : void{
		$out->putUnsignedVarInt(count($this->recipesWithTypeIds));
		foreach($this->recipesWithTypeIds as $d){
			$out->putVarInt($d->getTypeId());
			$d->encode($out);
		}
		$out->putUnsignedVarInt(count($this->potionTypeRecipes));
		foreach($this->potionTypeRecipes as $recipe){
			$out->putVarInt($recipe->getInputItemId());
			$out->putVarInt($recipe->getInputItemMeta());
			$out->putVarInt($recipe->getIngredientItemId());
			$out->putVarInt($recipe->getIngredientItemMeta());
			$out->putVarInt($recipe->getOutputItemId());
			$out->putVarInt($recipe->getOutputItemMeta());
		}
		$out->putUnsignedVarInt(count($this->potionContainerRecipes));
		foreach($this->potionContainerRecipes as $recipe){
			$out->putVarInt($recipe->getInputItemId());
			$out->putVarInt($recipe->getIngredientItemId());
			$out->putVarInt($recipe->getOutputItemId());
		}
		$out->putUnsignedVarInt(count($this->materialReducerRecipes));
		foreach($this->materialReducerRecipes as $recipe){
			$out->putVarInt(($recipe->getInputItemId() << 16) | $recipe->getInputItemMeta());
			$out->putUnsignedVarInt(count($recipe->getOutputs()));
			foreach($recipe->getOutputs() as $output){
				$out->putVarInt($output->getItemId());
				$out->putVarInt($output->getCount());
			}
		}
		$out->putBool($this->cleanRecipes);
	}

	public function handle(PacketHandlerInterface $handler) : bool{
		return $handler->handleCraftingData($this);
	}
}
