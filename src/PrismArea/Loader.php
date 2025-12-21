<?php

namespace PrismArea;

use pocketmine\plugin\PluginBase;
use pocketmine\utils\SingletonTrait;
use PrismArea\area\AreaManager;
use PrismArea\command\AreaCommand;
use PrismArea\lang\LangManager;
use PrismArea\libs\muqsit\invmenu\InvMenuHandler;
use PrismArea\listener\AbilitiesListener;
use PrismArea\listener\BlockListener;
use PrismArea\listener\PlayerListener;
use PrismArea\listener\WorldListener;
use PrismArea\timings\TimingsManager;

class Loader extends PluginBase
{
    use SingletonTrait;

    protected function onLoad(): void
    {
        self::setInstance($this);
        $this->saveDefaultConfig();

        @mkdir($this->getDataFolder() . "/lang", 0777, true);
        $this->saveResource("lang/en_US.ini");
        $this->saveResource("pack.zip");
    }

    /**
     * Initializes the plugin and loads area data.
     *
     * This method is called when the plugin is enabled.
     * It loads area data from the specified JSON file.
     */
    public function onEnable(): void
    {
        $config = $this->getConfig();

        // Check if InvMenu plugin is installed
        if (!class_exists(InvMenuHandler::class)) {
            $this->getLogger()->error("InvMenu plugin not found. Please install it to use the area menu features.");
            $this->getServer()->getPluginManager()->disablePlugin($this);
            return;
        }

        // Check if InvMenuHandler is already registered
        if (!InvMenuHandler::isRegistered()) {
            InvMenuHandler::register($this);
        }

        $timingsManager = TimingsManager::getInstance();
        $timingsManager->load($this);

        $areaManager = AreaManager::getInstance();
        $areaManager->load($this->getDataFolder() . "/areas.json");

        $langManager = LangManager::getInstance();
        $langManager->load($this->getDataFolder() . "/lang");

        $this->getServer()->getPluginManager()->registerEvents(new PlayerListener($this, $areaManager), $this);
        $this->getServer()->getPluginManager()->registerEvents(new WorldListener($this, $areaManager), $this);
        $this->getServer()->getPluginManager()->registerEvents(new BlockListener($this, $areaManager), $this);

        if ($config->getNested("abilities.enabled", true)) {
            // Register the AbilitiesListener if the config option is enabled
            new AbilitiesListener($this, (int)$config->getNested("abilities.tick", 1));
        }

        $this->getServer()->getCommandMap()->register("area", new AreaCommand());
        PrismAPI::load($this->getDataFolder() . "/pack.zip"); // Load the resource pack
    }

    /**
     * Cleans up resources when the plugin is disabled.
     *
     * This method is called when the plugin is disabled.
     * It closes the area manager to release any resources.
     */
    public function onDisable(): void
    {
        AreaManager::getInstance()->close();
    }
}
