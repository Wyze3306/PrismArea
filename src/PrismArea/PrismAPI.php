<?php

namespace PrismArea;

use pocketmine\item\Item;
use pocketmine\resourcepacks\ResourcePack as ResourcePackPM;
use pocketmine\resourcepacks\ResourcePackManager;
use pocketmine\Server;
use ReflectionClass;
use ReflectionException;
use Symfony\Component\Filesystem\Path;

class PrismAPI
{

    const ItemLockMode_NONE = 0;
    const ItemLockMode_FULL = 1;
    const ItemLockMode_FULL_INVENTORY = 2;

    /**
     * Locks an item with the specified lock mode.
     * This prevents the item from being moved/dropped in the inventory.
     *
     * @param Item $item The item to lock.
     * @param int $mode The lock mode (ItemLockMode_NONE, ItemLockMode_FULL, or ItemLockMode_FULL_INVENTORY).
     * @return Item The locked item.
     * @see https://github.com/PrismStudioMC/PrismAPI/blob/main/src/PrismAPI/item/ItemFactory.php
     */
    public static function LOCK(Item $item, int $mode = self::ItemLockMode_FULL): Item
    {
        $item->getNamedTag()->setByte("minecraft:item_lock", $mode);
        return $item;
    }

    /**
     * Loads a resource pack from the specified path.
     *
     * @param string $path The path to the resource pack.
     * @return ResourcePackPM|null
     */
    public static function load(string $path): ?ResourcePackPM
    {
        $server = Server::getInstance();
        $logger = $server->getLogger();
        $manager = $server->getResourcePackManager();

        $path = Path::canonicalize($path);
        if (!is_file($path) && !is_dir($path)) {
            $logger->error("Resource pack path not found: {$path}");
            return null;
        }

        try {
            $reflectionClass = new ReflectionClass(ResourcePackManager::class);
        } catch (ReflectionException $e) {
            Server::getInstance()->getLogger()->error("Failed to reflect ResourcePackManager: " . $e->getMessage());
            return null;
        }

        try {
            /** @var ResourcePackPM $pack */
            $pack = $reflectionClass->getMethod("loadPackFromPath")->invoke($manager, $path);
        } catch (ReflectionException $e) {
            Server::getInstance()->getLogger()->error("Failed to load resource pack: " . $e->getMessage());
            return null;
        }

        $manager->setResourceStack(array_merge($manager->getResourceStack(), [$pack]));
        Server::getInstance()->getLogger()->info("Resource pack loaded from: " . $path);

        return $pack;
    }
}