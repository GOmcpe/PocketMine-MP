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

namespace pocketmine\world\format\io\region;

use pocketmine\data\bedrock\BiomeIds;
use pocketmine\nbt\BigEndianNbtSerializer;
use pocketmine\nbt\NbtDataException;
use pocketmine\nbt\tag\ByteArrayTag;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\IntArrayTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\world\format\BiomeArray;
use pocketmine\world\format\Chunk;
use pocketmine\world\format\io\ChunkData;
use pocketmine\world\format\io\ChunkUtils;
use pocketmine\world\format\io\exception\CorruptedChunkException;
use pocketmine\world\format\SubChunk;
use function zlib_decode;

/**
 * Trait containing I/O methods for handling legacy Anvil-style chunks.
 *
 * Motivation: In the future PMAnvil will become a legacy read-only format, but Anvil will continue to exist for the sake
 * of handling worlds in the PC 1.13 format. Thus, we don't want PMAnvil getting accidentally influenced by changes
 * happening to the underlying Anvil, because it only uses the legacy part.
 *
 * @internal
 */
trait LegacyAnvilChunkTrait{
	/**
	 * @throws CorruptedChunkException
	 */
	protected function deserializeChunk(string $data) : ChunkData{
		$decompressed = @zlib_decode($data);
		if($decompressed === false){
			throw new CorruptedChunkException("Failed to decompress chunk NBT");
		}
		$nbt = new BigEndianNbtSerializer();
		try{
			$chunk = $nbt->read($decompressed)->mustGetCompoundTag();
		}catch(NbtDataException $e){
			throw new CorruptedChunkException($e->getMessage(), 0, $e);
		}
		$chunk = $chunk->getTag("Level");
		if(!($chunk instanceof CompoundTag)){
			throw new CorruptedChunkException("'Level' key is missing from chunk NBT");
		}

		$subChunks = [];
		$subChunksTag = $chunk->getListTag("Sections") ?? [];
		foreach($subChunksTag as $subChunk){
			if($subChunk instanceof CompoundTag){
				$subChunks[$subChunk->getByte("Y")] = $this->deserializeSubChunk($subChunk);
			}
		}

		$makeBiomeArray = function(string $biomeIds) : BiomeArray{
			try{
				return new BiomeArray($biomeIds);
			}catch(\InvalidArgumentException $e){
				throw new CorruptedChunkException($e->getMessage(), 0, $e);
			}
		};
		$biomeArray = null;
		if(($biomeColorsTag = $chunk->getTag("BiomeColors")) instanceof IntArrayTag){
			$biomeArray = $makeBiomeArray(ChunkUtils::convertBiomeColors($biomeColorsTag->getValue())); //Convert back to original format
		}elseif(($biomesTag = $chunk->getTag("Biomes")) instanceof ByteArrayTag){
			$biomeArray = $makeBiomeArray($biomesTag->getValue());
		}else{
			$biomeArray = BiomeArray::fill(BiomeIds::OCEAN);
		}

		return new ChunkData(
			new Chunk(
				$subChunks,
				$biomeArray,
				$chunk->getByte("TerrainPopulated", 0) !== 0
			),
			($entitiesTag = $chunk->getTag("Entities")) instanceof ListTag ? self::getCompoundList("Entities", $entitiesTag) : [],
			($tilesTag = $chunk->getTag("TileEntities")) instanceof ListTag ? self::getCompoundList("TileEntities", $tilesTag) : [],
		);
	}

	abstract protected function deserializeSubChunk(CompoundTag $subChunk) : SubChunk;

}
