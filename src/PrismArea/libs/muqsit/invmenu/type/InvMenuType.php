<?php

declare(strict_types=1);

namespace PrismArea\libs\muqsit\invmenu\type;

use PrismArea\libs\muqsit\invmenu\InvMenu;
use PrismArea\libs\muqsit\invmenu\type\graphic\InvMenuGraphic;
use pocketmine\inventory\Inventory;
use pocketmine\player\Player;

interface InvMenuType{

	public function createGraphic(InvMenu $menu, Player $player) : ?InvMenuGraphic;

	public function createInventory() : Inventory;
}