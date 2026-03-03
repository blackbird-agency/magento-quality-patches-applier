<?php

namespace Blackbird\MagentoQualityPatchesApplier;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use Composer\Util\ProcessExecutor;
use Exception;
use RuntimeException;

class Patches implements PluginInterface, EventSubscriberInterface
{

    /**
     * @var Composer $composer
     */
    protected $composer;
    /**
     * @var IOInterface $io
     */
    protected $io;
    /**
     * @var EventDispatcher $eventDispatcher
     */
    protected $eventDispatcher;
    /**
     * @var ProcessExecutor $executor
     */
    protected $executor;

    /**
     * @param Composer $composer
     * @param IOInterface $io
     * @return void
     */
    public function activate(Composer $composer, IOInterface $io)
    {
        $this->composer = $composer;
        $this->io = $io;
        $this->eventDispatcher = $composer->getEventDispatcher();
        $this->executor = new ProcessExecutor($this->io);
    }

    /**
     * Returns an array of event names this subscriber wants to listen to.
     */
    public static function getSubscribedEvents()
    {
        return array(
            ScriptEvents::PRE_INSTALL_CMD => array('revertPatches', 10),
            ScriptEvents::PRE_UPDATE_CMD => array('revertPatches', 10),
            ScriptEvents::POST_INSTALL_CMD => array('applyPatches'),
            ScriptEvents::POST_UPDATE_CMD => array('applyPatches')
        );
    }

    /**
     * @param Event $event
     * @return void
     * @throws Exception
     */
    public function applyPatches(Event $event)
    {
        if (!$this->isEnabled()) {
            return;
        }

        $extra = $this->composer->getPackage()->getExtra();
        $exitOnFailure = getenv('COMPOSER_EXIT_ON_MAGENTO_PATCH_FAILURE') || !empty($extra['composer-exit-on-magento-patch-failure']);

        try {
            if (!isset($extra['magento-patches']["apply"]) || !is_array($extra['magento-patches']) || empty($extra['magento-patches']["apply"])) {
                $this->io->write("<comment>No magento patches to apply, please add patch to extra.magento-patches.apply.</comment>");
                return;
            }

            $patchesToApply = $extra['magento-patches']["apply"];
            if (!is_array($patchesToApply)) {
                $patchesToApply = [$patchesToApply];
            }
            if (array_intersect(["all", "*", "ALL"], $patchesToApply)) {
                $patchesToApply = [];
                $data = $this->getStatusJson();
                foreach ($data as $patch) {
                    if ($patch["Status"] === "Not applied") {
                        $patchesToApply[] = $patch["Id"];
                    }
                }
            }

            if (!empty($extra['magento-patches']["ignore"])) {
                $patchesToApply = array_diff($patchesToApply, $extra['magento-patches']["ignore"]);
            }

            $patchesToApply = array_unique($patchesToApply);

            $this->io->write(sprintf("<comment>Applying the %d magento quality patches : %s</comment>", count($patchesToApply), implode(" ", $patchesToApply)));
            $patchesArgs = [];
            foreach ($patchesToApply as $patch) {
                $patchesArgs[] = escapeshellarg($patch);
            }

            $patchesArg = implode(" ", $patchesArgs);
            $command = sprintf("%s apply %s", escapeshellarg($this->getMagentoPatchesCliPath()), $patchesArg);

            if ($this->io->isVerbose()) {
                $this->io->write(sprintf("<info>%s</info>", $command));
                $resultCode = $this->executor->executeTty($command);
            } else {
                $resultCode = $this->executor->execute($command, $output);
                if ($resultCode !== 0) {
                    $this->io->write(sprintf("<error>%s</error>", $output));
                }
            }

            if ($resultCode !== 0) {
                throw new RuntimeException(sprintf("Error applying patches : %s please check errors from output above.", $patchesArgs));
            }
        } catch (Exception $e) {
            if ($exitOnFailure) {
                throw $e;
            } else {
                $this->io->write(sprintf("<error>%s</error>", $e->getMessage()));
            }
        }
    }

    /**
     * @param Event $event
     * @return void
     * @throws RuntimeException
     */
    public function revertPatches(Event $event)
    {
        if (!$this->isEnabled()) {
            return;
        }

        try {
            $data = $this->getStatusJson();
            $patchesToRemove = [];
            foreach ($data as $patch) {
                if ($patch["Status"] === "Applied") {
                    $patchesToRemove[] = $patch["Id"];
                }
            }
            if (!empty($patchesToRemove)) {
                $this->io->write(sprintf("<comment>Reverting the %d magento quality patches already applied</comment>", count($patchesToRemove)));
                $resultCode = $this->executor->execute(sprintf("%s revert --all", escapeshellarg($this->getMagentoPatchesCliPath())), $output);
                $message = $output;
                if(is_array($output)){
                    $message = implode("\n", $output ?? []);
                }
                if(!empty($message)){
                    $message = "Command output : " . $message;
                }
                if ($resultCode !== 0) {
                    throw new RuntimeException("error reverting patches" . $message);
                }
            }
        } catch (\Exception $e) {
            $this->io->write(sprintf("<warning>Warning : %s</warning>", $e->getMessage()));
        }
    }

    /**
     * Retrieves the patches status as a JSON-decoded array.
     *
     * @return array<array{Id: string, Title: string, Category: string, Origin:string, Status: string, Details: string}>
     * @throws \JsonException|RuntimeException If the command execution fails or returns a non-zero result code.
     */
    private function getStatusJson(): array
    {
        $resultCode = $this->executor->execute(sprintf("%s status -f json", escapeshellarg($this->getMagentoPatchesCliPath())), $output);

        if ($resultCode !== 0) {
            $message = $output;
            if(is_array($output)){
                $message = implode("\n", $output ?? []);
            }
            if(!empty($message)){
                $message = "Command output : " . $message;
            }
            throw new RuntimeException("Unable to retrieve installed magento patches" . $message);
        }
        return \json_decode($output, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @return bool
     */
    protected function isEnabled(): bool
    {
        $extra = $this->composer->getPackage()->getExtra();

        return !empty($extra['magento-patches']);
    }

    /**
     * @return string
     */
    protected function getMagentoPatchesCliPath(): string
    {
        $binDir = $this->composer->getConfig()->get('bin-dir');
        if (!file_exists($binDir . '/magento-patches')) {
            throw new \LogicException('magento-patches binary not found');
        }
        return $binDir . '/magento-patches';
    }

    /**
     * {@inheritDoc}
     */
    public function deactivate(Composer $composer, IOInterface $io)
    {
    }

    /**
     * {@inheritDoc}
     */
    public function uninstall(Composer $composer, IOInterface $io)
    {
    }

}
