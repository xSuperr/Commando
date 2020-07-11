<?php

/***
 *    ___                                          _
 *   / __\___  _ __ ___  _ __ ___   __ _ _ __   __| | ___
 *  / /  / _ \| '_ ` _ \| '_ ` _ \ / _` | '_ \ / _` |/ _ \
 * / /__| (_) | | | | | | | | | | | (_| | | | | (_| | (_) |
 * \____/\___/|_| |_| |_|_| |_| |_|\__,_|_| |_|\__,_|\___/
 *
 * Commando - A Command Framework virion for PocketMine-MP
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 *
 * Written by @CortexPE <https://CortexPE.xyz>
 *
 */
declare(strict_types=1);

namespace CortexPE\Commando;


use CortexPE\Commando\args\BaseArgument;
use CortexPE\Commando\constraint\BaseConstraint;
use CortexPE\Commando\traits\ArgumentableTrait;
use CortexPE\Commando\traits\IArgumentable;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\command\PluginIdentifiableCommand;
use pocketmine\plugin\Plugin;
use pocketmine\utils\TextFormat;
use function array_map;
use function array_shift;
use function array_slice;
use function array_unique;
use function array_unshift;
use function count;
use function explode;
use function floor;
use function implode;
use function is_bool;
use function is_float;
use function is_int;
use function str_repeat;
use function strlen;

abstract class BaseCommand extends Command implements IArgumentable, IRunnable, PluginIdentifiableCommand {
	use ArgumentableTrait;

	public const ERR_INVALID_ARG_VALUE = 0x01;
	public const ERR_TOO_MANY_ARGUMENTS = 0x02;
	public const ERR_INSUFFICIENT_ARGUMENTS = 0x03;
	public const ERR_NO_ARGUMENTS = 0x04;
	public const ERR_INVALID_ARGUMENTS = 0x05;

	/** @var CommandSender */
	protected $currentSender;

	/** @var BaseSubCommand[] */
	private $subCommands = [];

	/** @var BaseConstraint[] */
	private $constraints = [];

	/** @var Plugin */
	protected $plugin;

	public function __construct(
		Plugin $plugin,
		string $name,
		string $description = "",
		array $aliases = []
	) {
		$this->plugin = $plugin;
		parent::__construct($name, $description, null, $aliases);

		$this->prepare();

		$this->usageMessage = $this->generateUsageMessage();
	}

	public function getPlugin(): Plugin {
		return $this->plugin;
	}

	final public function execute(CommandSender $sender, string $usedAlias, array $args) {
		$this->currentSender = $sender;
		if(!$this->testPermission($sender)) {
			return;
		}
		/** @var BaseCommand|BaseSubCommand $cmd */
		$cmd = $this;
		$passArgs = [];
		if(count($args) > 0) {
			if(isset($this->subCommands[($label = $args[0])])) {
				array_shift($args);
				$this->subCommands[$label]->execute($sender, $label, $args);
				return;
			}

			$passArgs = $this->attemptArgumentParsing($cmd, $args);
		} elseif($this->hasRequiredArguments()){
			$this->sendError(self::ERR_INSUFFICIENT_ARGUMENTS, [], 0);
			return;
		}
		if($passArgs !== null) {
			foreach ($cmd->getConstraints() as $constraint){
				if(!$constraint->test($sender, $usedAlias, $passArgs)){
					$constraint->onFailure($sender, $usedAlias, $passArgs);
					return;
				}
			}
			$cmd->onRun($sender, $usedAlias, $passArgs);
		}
	}

	/**
	 * @param ArgumentableTrait $ctx
	 * @param array             $args
	 *
	 * @return array|null
	 */
	private function attemptArgumentParsing($ctx, array $args): ?array {
		$dat = $ctx->parseArguments($args, $this->currentSender);
		if(!empty(($errors = $dat["errors"]))) {
			foreach($errors as $error) {
				$this->sendError($error["code"], ...$error["data"]);
			}

			return null;
		}

		return $dat["arguments"];
	}

	abstract public function onRun(CommandSender $sender, string $aliasUsed, array $args): void;

	protected function sendUsage(): void {
		$this->currentSender->sendMessage(TextFormat::RED . "Usage: " . $this->getUsage());
	}

    public function sendError(int $errorCode, array $rawArgs, int $argIndex): void
    {
        $correct = implode(" ", array_slice($rawArgs, 0, $argIndex));
        $correct = $correct !== "" ? $correct . " " : $correct;
        $correctLength = 1 + strlen($this->getName()) + strlen($correct);

        $sender = $this->currentSender;
        switch ($errorCode) {
            case self::ERR_INVALID_ARG_VALUE:
                $incorrect = $rawArgs[$argIndex];
                $rawArgs[$argIndex] = TextFormat::YELLOW . $incorrect . TextFormat::RED;

                $expectedTypes = array_map(static function(BaseArgument $argument) : string{
                    return $argument->getTypeName();
                }, $this->argumentList[$argIndex]);
                if(count($expectedTypes) === 1){
                    $expectedTypes[0] = ($expectedTypes[0] === "int" ? "an " : "a ") . $expectedTypes[0];
                }

                if(is_bool($incorrect)){
                    $givenType = "a bool";
                }elseif(is_float($incorrect)){
                    $givenType = "a float";
                }elseif(is_int($incorrect)){
                    $givenType = "an int";
                }else{
                    $givenType = "a string";
                }

                $sender->sendMessage(TextFormat::RED . "/" . $this->getName() . " " . implode(" ", $rawArgs));
                $sender->sendMessage(TextFormat::YELLOW . str_repeat(" ", (int)($correctLength + floor(strlen($incorrect) / 2))) . "^");
                $sender->sendMessage(TextFormat::RED . "Command expected argument " . ($argIndex + 1) . " to be " . (count($expectedTypes) === 1 ? $expectedTypes[0] : "one of " . implode(", ", $expectedTypes)) . " but " . $givenType . " was given.");
                break;
            case self::ERR_TOO_MANY_ARGUMENTS:
            case self::ERR_NO_ARGUMENTS:
                $incorrect = array_slice($rawArgs, $argIndex);
                $incorrectLength = strlen(implode(" ", $incorrect));

                $sender->sendMessage(TextFormat::RED . "/" . $this->getName() . " " . $correct . TextFormat::YELLOW . implode(" ", $incorrect));
                $sender->sendMessage(TextFormat::YELLOW . str_repeat(" ", (int)($correctLength + floor($incorrectLength / 2))) . "^");
                $sender->sendMessage(TextFormat::RED . "Command expected " . count($this->argumentList) . " arguments, " . count($rawArgs) . " given.");
                break;
            case self::ERR_INSUFFICIENT_ARGUMENTS:
                $incorrect = implode(" ", array_slice(explode(" ", $this->getUsage()), $argIndex + 1));

                $sender->sendMessage(TextFormat::RED . "/" . $this->getName() . " " . $correct . TextFormat::YELLOW . $incorrect);
                $sender->sendMessage(TextFormat::YELLOW . str_repeat(" ", (int)($correctLength + floor(strlen($incorrect) / 2))) . "^");
                $sender->sendMessage(TextFormat::RED . "Command expected " . count($this->argumentList) . " arguments, " . count($rawArgs) . " given.");
                break;
        }
    }

	public function registerSubCommand(BaseSubCommand $subCommand): void {
		$keys = $subCommand->getAliases();
		array_unshift($keys, $subCommand->getName());
		$keys = array_unique($keys);
		foreach($keys as $key) {
			if(!isset($this->subCommands[$key])) {
				$subCommand->setParent($this);
				$this->subCommands[$key] = $subCommand;
			} else {
				throw new \InvalidArgumentException("SubCommand with same name / alias for '{$key}' already exists");
			}
		}
	}

	/**
	 * @return BaseSubCommand[]
	 */
	public function getSubCommands(): array {
		return $this->subCommands;
	}

	public function addConstraint(BaseConstraint $constraint) : void {
		$this->constraints[] = $constraint;
	}

	/**
	 * @return BaseConstraint[]
	 */
	public function getConstraints(): array {
		return $this->constraints;
	}

	public function getUsageMessage(): string {
		return $this->getUsage();
	}

	public function setCurrentSender(CommandSender $sender): void{
		$this->currentSender = $sender;
	}
}
