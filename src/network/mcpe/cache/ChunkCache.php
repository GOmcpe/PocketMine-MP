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

namespace pocketmine\network\mcpe\cache;

use pocketmine\math\Vector3;
use pocketmine\network\mcpe\ChunkRequestTask;
use pocketmine\network\mcpe\compression\CompressBatchPromise;
use pocketmine\network\mcpe\compression\Compressor;
use pocketmine\network\mcpe\convert\RuntimeBlockMapping;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\world\ChunkListener;
use pocketmine\world\ChunkListenerNoOpTrait;
use pocketmine\world\format\Chunk;
use pocketmine\world\World;
use function count;
use function spl_object_id;
use function strlen;

/**
 * This class is used by the current MCPE protocol system to store cached chunk packets for fast resending.
 */
class ChunkCache implements ChunkListener{
	/** @var self[][] */
	private static $instances = [];

	/**
	 * Fetches the ChunkCache instance for the given world. This lazily creates cache systems as needed.
	 *
	 * @return ChunkCache
	 */
	public static function getInstance(World $world, Compressor $compressor) : self{
		$worldId = spl_object_id($world);
		$compressorId = spl_object_id($compressor);
		if(!isset(self::$instances[$worldId])){
			self::$instances[$worldId] = [];
			$world->addOnUnloadCallback(static function() use ($worldId) : void{
				foreach(self::$instances[$worldId] as $cache){
					$cache->caches = [];
				}
				unset(self::$instances[$worldId]);
				\GlobalLogger::get()->debug("Destroyed chunk packet caches for world#$worldId");
			});
		}
		if(!isset(self::$instances[$worldId][$compressorId])){
			\GlobalLogger::get()->debug("Created new chunk packet cache (world#$worldId, compressor#$compressorId)");
			self::$instances[$worldId][$compressorId] = new self($world, $compressor);
		}
		return self::$instances[$worldId][$compressorId];
	}

	public static function pruneCaches() : void{
		foreach(self::$instances as $compressorMap){
			foreach($compressorMap as $chunkCache){
				foreach($chunkCache->caches as $chunkHash => $caches){
					foreach($caches as $mappingProtocol => $promise){
						if($promise->hasResult()){
							//Do not clear promises that are not yet fulfilled; they will have requesters waiting on them
							unset($chunkCache->caches[$chunkHash][$mappingProtocol]);
						}
					}
				}
			}
		}
	}

	/** @var World */
	private $world;
	/** @var Compressor */
	private $compressor;

	/** @var CompressBatchPromise[][] */
	private $caches = [];

	/** @var int */
	private $hits = 0;
	/** @var int */
	private $misses = 0;

	private function __construct(World $world, Compressor $compressor){
		$this->world = $world;
		$this->compressor = $compressor;
	}

	/**
	 * Requests asynchronous preparation of the chunk at the given coordinates.
	 *
	 * @return CompressBatchPromise a promise of resolution which will contain a compressed chunk packet.
	 */
	public function request(int $chunkX, int $chunkZ, int $protocolId = ProtocolInfo::CURRENT_PROTOCOL) : CompressBatchPromise{
		$this->world->registerChunkListener($this, $chunkX, $chunkZ);
		$chunk = $this->world->getChunk($chunkX, $chunkZ);
		if($chunk === null){
			throw new \InvalidArgumentException("Cannot request an unloaded chunk");
		}
		$chunkHash = World::chunkHash($chunkX, $chunkZ);

		$mappingProtocol = RuntimeBlockMapping::getMappingProtocol($protocolId);

		if(isset($this->caches[$chunkHash][$mappingProtocol])){
			++$this->hits;
			return $this->caches[$chunkHash][$mappingProtocol];
		}

		++$this->misses;

		$this->world->timings->syncChunkSendPrepare->startTiming();
		try{
			$this->caches[$chunkHash][$mappingProtocol] = new CompressBatchPromise();

			$this->world->getServer()->getAsyncPool()->submitTask(
				new ChunkRequestTask(
					$chunkX,
					$chunkZ,
					$chunk,
					$mappingProtocol,
					$this->caches[$chunkHash][$mappingProtocol],
					$this->compressor,
					function() use ($chunkX, $chunkZ, $mappingProtocol) : void{
						$this->world->getLogger()->error("Failed preparing chunk $chunkX $chunkZ, retrying");

						$this->restartPendingRequest($chunkX, $chunkZ, $mappingProtocol);
					}
				)
			);

			return $this->caches[$chunkHash][$mappingProtocol];
		}finally{
			$this->world->timings->syncChunkSendPrepare->stopTiming();
		}
	}

	private function destroy(int $chunkX, int $chunkZ, int $mappingProtocol = null) : bool{
		$chunkHash = World::chunkHash($chunkX, $chunkZ);

		if($mappingProtocol === null){
			$existing = false;

			if(isset($this->caches[$chunkHash])){
				$existing = count($this->caches[$chunkHash]) > 0;
				unset($this->caches[$chunkHash]);
			}

			return $existing;
		}

		$existing = $this->caches[$chunkHash][$mappingProtocol] ?? null;
		unset($this->caches[$chunkHash][$mappingProtocol]);

		return $existing !== null;
	}

	/**
	 * Restarts an async request for an unresolved chunk.
	 *
	 * @throws \InvalidArgumentException
	 */
	private function restartPendingRequest(int $chunkX, int $chunkZ, int $mappingProtocol) : void{
		$chunkHash = World::chunkHash($chunkX, $chunkZ);

		$existing = $this->caches[$chunkHash][$mappingProtocol] ?? null;
		if($existing === null or $existing->hasResult()){
			throw new \InvalidArgumentException("Restart can only be applied to unresolved promises");
		}
		$existing->cancel();
		unset($this->caches[$chunkHash][$mappingProtocol]);

		$this->request($chunkX, $chunkZ, $mappingProtocol)->onResolve(...$existing->getResolveCallbacks());
	}

	/**
	 * @throws \InvalidArgumentException
	 */
	private function destroyOrRestart(int $chunkX, int $chunkZ) : void{
		$chunkHash = World::chunkHash($chunkX, $chunkZ);

		if(isset($this->caches[$chunkHash])){
			foreach($this->caches[$chunkHash] as $mappingProtocol => $cache){
				if(!$cache->hasResult()){
					//some requesters are waiting for this chunk, so their request needs to be fulfilled
					$this->restartPendingRequest($chunkX, $chunkZ, $mappingProtocol);
				}else{
					//dump the cache, it'll be regenerated the next time it's requested
					$this->destroy($chunkX, $chunkZ, $mappingProtocol);
				}
			}
		}
	}

	use ChunkListenerNoOpTrait {
		//force overriding of these
		onChunkChanged as private;
		onBlockChanged as private;
		onChunkUnloaded as private;
	}

	/**
	 * @see ChunkListener::onChunkChanged()
	 */
	public function onChunkChanged(int $chunkX, int $chunkZ, Chunk $chunk) : void{
		$this->destroyOrRestart($chunkX, $chunkZ);
	}

	/**
	 * @see ChunkListener::onBlockChanged()
	 */
	public function onBlockChanged(Vector3 $block) : void{
		//FIXME: requesters will still receive this chunk after it's been dropped, but we can't mark this for a simple
		//sync here because it can spam the worker pool
		$this->destroy($block->getFloorX() >> Chunk::COORD_BIT_SIZE, $block->getFloorZ() >> Chunk::COORD_BIT_SIZE);
	}

	/**
	 * @see ChunkListener::onChunkUnloaded()
	 */
	public function onChunkUnloaded(int $chunkX, int $chunkZ, Chunk $chunk) : void{
		$this->destroy($chunkX, $chunkZ);
		$this->world->unregisterChunkListener($this, $chunkX, $chunkZ);
	}

	/**
	 * Returns the number of bytes occupied by the cache data in this cache. This does not include the size of any
	 * promises referenced by the cache.
	 */
	public function calculateCacheSize() : int{
		$result = 0;
		foreach($this->caches as $caches){
			foreach($caches as $cache){
				if($cache->hasResult()){
					$result += strlen($cache->getResult());
				}
			}
		}
		return $result;
	}

	/**
	 * Returns the percentage of requests to the cache which resulted in a cache hit.
	 */
	public function getHitPercentage() : float{
		$total = $this->hits + $this->misses;
		return $total > 0 ? $this->hits / $total : 0.0;
	}
}
