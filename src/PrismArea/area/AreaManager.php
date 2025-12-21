<?php

namespace PrismArea\area;

use pocketmine\permission\DefaultPermissions;
use pocketmine\permission\Permission;
use pocketmine\permission\PermissionManager;
use pocketmine\utils\Config;
use pocketmine\utils\SingletonTrait;
use pocketmine\world\Position;
use PrismArea\Loader;
use PrismArea\timings\TimingsManager;
use PrismArea\types\AreaFlag;
use PrismArea\types\AreaSubFlag;

class AreaManager
{
    use SingletonTrait;

    /** @var array<string, Area> */
    private array $areas = [];
    private array $indexedAreas = [];

    private ?Config $config = null;

    public function __construct()
    {
        self::setInstance($this);
        $manager = PermissionManager::getInstance();

        DefaultPermissions::registerPermission(new Permission("prism.flag.*", "Allows access to all area flags"));
        DefaultPermissions::registerPermission(new Permission("prism.subflag.*", "Allows access to all area sub-flags"));

        foreach (AreaFlag::cases() as $k => $name) {
            $perm = "prism.flag." . strtolower($name->name);
            DefaultPermissions::registerPermission(
                new Permission($perm, "Allows access to the area flag: {$name->name}"),
                [$manager->getPermission("prism.flag.*")],
            );
            Loader::getInstance()->getLogger()->debug("Registered permission for flag: {$perm}");
        }

        foreach (AreaSubFlag::cases() as $k => $name) {
            $perm = "prism.subflag." . strtolower($name->name);
            DefaultPermissions::registerPermission(
                new Permission($perm, "Allows access to the area sub-flag: {$name->name}"),
                [$manager->getPermission("prism.subflag.*")],
            );
            Loader::getInstance()->getLogger()->debug("Registered permission for subflag: {$perm}");
        }
    }

    /**
     * @param string $path
     * @return void
     */
    public function load(string $path): void
    {
        $this->config = new Config($path, Config::JSON, []);
        $data = $this->config->getAll();

        foreach ($data as $k => $values) {
            $area = Area::parse($values);
            $this->register($area);
        }

        Loader::getInstance()->getLogger()->notice("Loaded " . count($this->areas) . " areas from {$path}");
    }

    /**
     * Closes the area manager and saves the areas to the data folder.
     *
     * @return void
     */
    public function close(): void
    {
        if ($this->config === null) {
            return;
        }

        $data = [];
        foreach ($this->areas as $area) {
            $data[strtolower($area->getName())] = $area->jsonSerialize();
        }

        $this->config->setAll($data);
        $this->config->save();
    }

    /**
     * Registers an area.
     *
     * @param Area $area
     * @return void
     */
    public function register(Area $area): void
    {
        $this->areas[strtolower($area->getName())] = $area;
        foreach ($this->getCoveredChunks($area) as $k => $chunkHash) {
            $this->indexedAreas[$chunkHash][] = $area;
            $this->sortChunkList($chunkHash);
        }
    }

    /**
     * Deletes an area.
     *
     * @param Area $area
     * @throws \RuntimeException if the area does not exist.
     */
    public function delete(Area $area): void
    {
        $k = strtolower($area->getName());
        if (!isset($this->areas[$k])) {
            throw new \RuntimeException("Area '{$area->getName()}' not found.");
        }

        // Remove the area from the areas array
        foreach ($this->getCoveredChunks($area) as $k => $chunkHash) {
            if (!isset($this->indexedAreas[$chunkHash])) {
                continue;
            }

            foreach ($this->indexedAreas[$chunkHash] as $i => $a) {
                if ($a === $area || strtolower($a->getName()) === $k) {
                    unset($this->indexedAreas[$chunkHash][$i]);
                }
            }

            if (empty($this->indexedAreas[$chunkHash])) {
                unset($this->indexedAreas[$chunkHash]);
            } else {
                // Re-index and keep sorted
                $this->indexedAreas[$chunkHash] = array_values($this->indexedAreas[$chunkHash]);
                $this->sortChunkList($chunkHash);
            }
        }


        unset($this->areas[$k]);
    }

    /**
     * @return array
     */
    public function getAreas(): array
    {
        return $this->areas;
    }

    /**
     * Checks if an area exists by its name.
     *
     * @param string $name
     * @return bool
     */
    public function existArea(string $name): bool
    {
        return isset($this->areas[strtolower($name)]);
    }

    /**
     * Returns an array of area names.
     *
     * @param string $name
     * @return Area|null
     */
    public function getArea(string $name): ?Area
    {
        return $this->areas[strtolower($name)] ?? null;
    }

    /**
     * Finds an area by position.
     *
     * @param Position $position
     * @return Area|null
     */
    public function find(Position $position): ?Area
    {
        $worldId = $position->getWorld()->getId();
        $chunkX = $position->getFloorX() >> 4;
        $chunkZ = $position->getFloorZ() >> 4;
        $chunkHash = "{$worldId}:{$chunkX}:{$chunkZ}";

        $timings = TimingsManager::getInstance()->getSearchAreas();
        $timings->startTiming(); // Start timing the area search
        try {
            foreach ($this->indexedAreas[$chunkHash] ?? [] as $_ => $area) {
                if ($area->isPositionInside($position)) {
                    return $area;
                }
            }
        } finally {
            $timings->stopTiming(); // Stop timing the area search
        }

        return null;
    }

    /**
     * Moves an area before another area by their names.
     *
     * @param Area $target
     * @param Area $ref
     * @return bool
     */
    public function prioritize(Area $target, Area $ref): bool
    {
        if ($target === $ref) {
            return false;
        }

        $ordered = array_values($this->areas);
        usort($ordered, static function (Area $a, Area $b): int {
            $pa = $a->getPriority();
            $pb = $b->getPriority();
            return $pa === $pb
                ? strcmp(strtolower($a->getName()), strtolower($b->getName()))
                : ($pa <=> $pb); // ASC
        });

        // Retrieve the target area from the ordered list
        /** @var Area[] $ordered */
        $ordered = array_values(array_filter($ordered, static fn (Area $x) => $x !== $target));

        // Find the index of the reference area
        $refIndex = null;
        foreach ($ordered as $i => $a) {
            if ($a === $ref) {
                $refIndex = $i;
                break;
            }
        }
        if ($refIndex === null) {
            return false;
        }

        // Insert the target area before the reference area
        array_splice($ordered, $refIndex, 0, [$target]);

        // Update the priorities of the areas
        $n = count($ordered);
        for ($i = 0; $i < $n; $i++) {
            $new = $n - $i;
            if ($ordered[$i]->getPriority() !== $new) {
                $ordered[$i]->setPriority($new);
            }
        }

        // Update the areas array
        $this->recalculatePriorities(null);
        return true;
    }

    /**
     * Sorts the area list for a specific chunk.
     *
     * @param Area|null $scope
     * @return void
     */
    public function recalculatePriorities(?Area $scope = null): void
    {
        if ($scope === null) {
            foreach (array_keys($this->indexedAreas) as $_ => $chunkHash) {
                $this->sortChunkList($chunkHash);
            }
            return;
        }

        foreach ($this->getCoveredChunks($scope) as $_ => $chunkHash) {
            if (isset($this->indexedAreas[$chunkHash])) {
                $this->sortChunkList($chunkHash);
            }
        }
    }

    /**
     * Sorts the areas in a specific chunk by priority and name.
     * Areas with higher priority come first (DESC order).
     *
     * @param string $chunkHash
     * @return void
     */
    private function sortChunkList(string $chunkHash): void
    {
        if (empty($this->indexedAreas[$chunkHash])) {
            return;
        }

        usort($this->indexedAreas[$chunkHash], static function (Area $a, Area $b): int {
            $pa = $a->getPriority();
            $pb = $b->getPriority();
            return $pa === $pb
                ? strcmp(strtolower($a->getName()), strtolower($b->getName()))
                : ($pb <=> $pa); // DESC: higher priority first
        });
    }

    /**
     * Returns an area by its name.
     *
     * @param Area $region
     * @return array<string>
     */
    private function getCoveredChunks(Area $region): array
    {
        $chunks = [];

        $aabb = $region->getAABB();
        $minX = $aabb->minX >> 4;
        $maxX = $aabb->maxX >> 4;
        $minZ = $aabb->minZ >> 4;
        $maxZ = $aabb->maxZ >> 4;

        $worldId = $region->getWorld()->getId();
        for ($x = $minX; $x <= $maxX; $x++) {
            for ($z = $minZ; $z <= $maxZ; $z++) {
                $chunks[] = "{$worldId}:{$x}:{$z}";
            }
        }

        return $chunks;
    }
}
