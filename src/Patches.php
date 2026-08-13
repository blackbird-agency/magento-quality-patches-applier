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
            $magentoPatches = $extra['magento-patches'];
            if (!is_array($magentoPatches)) {
                $this->io->write("<comment>No magento patches to apply, please add patch to extra.magento-patches.apply.</comment>");
                return;
            }

            $autoInstallRequiredPatches = !empty($magentoPatches["auto-install-required-patches"]);

            $patchesToApply = $magentoPatches["apply"] ?? [];
            if (!is_array($patchesToApply)) {
                $patchesToApply = [$patchesToApply];
            }

            if (empty($patchesToApply) && !$autoInstallRequiredPatches) {
                $this->io->write("<comment>No magento patches to apply, please add patch to extra.magento-patches.apply.</comment>");
                return;
            }

            $useAllMode = (bool) array_intersect(["all", "*", "ALL"], $patchesToApply);
            if ($useAllMode) {
                $patchesToApply = $this->getNotAppliedPatchIds();
            } elseif ($autoInstallRequiredPatches) {
                $patchesToApply = array_merge($patchesToApply, $this->getNotAppliedRequiredPatchIds());
            }

            if (!empty($magentoPatches["ignore"])) {
                $patchesToApply = array_diff($patchesToApply, $magentoPatches["ignore"]);
            }

            $idsToApply = array_values(array_unique($patchesToApply));
            $appliedPatchIds = [];
            $iteration = 0;
            $maxIterations = 20;

            while (!empty($idsToApply)) {
                if (++$iteration > $maxIterations) {
                    $this->io->write(sprintf("<comment>Stopping after %d passes, some newly-unlocked patches may still be pending for the next run.</comment>", $maxIterations));
                    break;
                }

                $this->applyPatchList($idsToApply);
                $appliedPatchIds = array_merge($appliedPatchIds, $idsToApply);

                $idsToApply = [];
                if ($useAllMode) {
                    // Applying a patch can resolve status ambiguity ("N/A") for another patch that was
                    // undetectable in the previous pass, so re-check for newly-unlocked not-applied patches.
                    $idsToApply = $this->getNotAppliedPatchIds();
                } elseif ($autoInstallRequiredPatches) {
                    $idsToApply = $this->getNotAppliedRequiredPatchIds();
                }

                if (!empty($idsToApply)) {
                    $idsToApply = array_diff($idsToApply, $appliedPatchIds);
                    if (!empty($magentoPatches["ignore"])) {
                        $idsToApply = array_diff($idsToApply, $magentoPatches["ignore"]);
                    }
                    $idsToApply = array_values(array_unique($idsToApply));
                }
            }

            if (empty($appliedPatchIds)) {
                $this->io->write("<comment>No patches to apply</comment>");
                return;
            }

            if ($event->isDevMode()) {
                $this->logRequiredPatchesDetails($appliedPatchIds);
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
            $iteration = 0;
            $maxIterations = 20;
            $previousCount = null;

            while (true) {
                $patchesToRemove = $this->getAppliedPatchIds();

                if (empty($patchesToRemove)) {
                    break;
                }

                if ($previousCount !== null && count($patchesToRemove) >= $previousCount) {
                    $this->io->write("<comment>Revert made no further progress, stopping.</comment>");
                    break;
                }

                if (++$iteration > $maxIterations) {
                    $this->io->write(sprintf("<comment>Stopping after %d revert passes, some patches may still be applied.</comment>", $maxIterations));
                    break;
                }

                $previousCount = count($patchesToRemove);

                $this->io->write(sprintf("<comment>Reverting the %d magento patches already applied</comment>", count($patchesToRemove)));
                $resultCode = $this->executor->execute(sprintf("%s revert --all", escapeshellarg($this->getMagentoPatchesCliPath())), $output);
                $message = $output;
                if(is_array($output)){
                    $message = implode("\n", $output ?? []);
                }
                if(!empty($message)){
                    $message = "Command output : " . $message;
                }
                if ($resultCode !== 0) {
                    throw new RuntimeException("error reverting patches " . $message);
                }
            }
        } catch (RuntimeException $e) {
            $this->io->write(sprintf("<comment>Unable to retrieve installed magento patches, continuing...</comment>", $e->getMessage()));
        } catch (\Exception $e) {
            $this->io->write(sprintf("<warning>Warning : %s</warning>", $e->getMessage()));
        }
    }

    /**
     * Runs the magento-patches apply command for the given patch ids.
     *
     * @param array<string> $patchIds
     * @return void
     * @throws RuntimeException
     */
    private function applyPatchList(array $patchIds)
    {
        $patchesArgs = [];
        foreach ($patchIds as $patch) {
            $patchesArgs[] = escapeshellarg($patch);
        }

        $this->io->write(sprintf("<comment>Applying the %d magento patches : %s</comment>", count($patchIds), implode(" ", $patchIds)));

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
            throw new RuntimeException(sprintf("Error applying patches : %s please check errors from output above.", $patchesArg));
        }
    }

    /**
     * Returns the ids of the currently "Not applied" patches that are of type "Required".
     *
     * @return array<string>
     */
    private function getNotAppliedRequiredPatchIds(): array
    {
        $ids = [];
        $data = $this->getStatusJson($this->getEcePatchesCliPath());
        foreach ($data as $patch) {
            if ($patch["Status"] === "Not applied" && $patch["Id"] !== "N/A" && $this->isRequiredPatch($patch)) {
                $ids[] = $patch["Id"];
            }
        }
        return $ids;
    }

    /**
     * Returns the ids of all currently "Not applied" patches.
     *
     * @return array<string>
     */
    private function getNotAppliedPatchIds(): array
    {
        $ids = [];
        $data = $this->getStatusJson();
        foreach ($data as $patch) {
            if ($patch["Status"] === "Not applied" && $patch["Id"] !== "N/A") {
                $ids[] = $patch["Id"];
            }
        }
        return $ids;
    }

    /**
     * Returns the ids of all currently "Applied" patches.
     *
     * @return array<string>
     */
    private function getAppliedPatchIds(): array
    {
        $ids = [];
        $data = $this->getStatusJson();
        foreach ($data as $patch) {
            if ($patch["Status"] === "Applied") {
                $ids[] = $patch["Id"];
            }
        }
        return $ids;
    }

    /**
     * Writes the title and details of the applied patches that are "Required" to the console.
     *
     * @param array<string> $appliedPatchIds
     * @return void
     */
    private function logRequiredPatchesDetails(array $appliedPatchIds)
    {
        try {
            $data = $this->getStatusJson($this->getEcePatchesCliPath());
        } catch (Exception $e) {
            return;
        }

        $patchesById = [];
        foreach ($data as $patch) {
            $patchesById[$patch["Id"]] = $patch;
        }

        // Iterate over $appliedPatchIds, not $data, to preserve the order patches were actually
        // applied in (across the auto-install-required-patches catch-up passes).
        foreach ($appliedPatchIds as $id) {
            if (!isset($patchesById[$id]) || !$this->isRequiredPatch($patchesById[$id])) {
                continue;
            }
            $patch = $patchesById[$id];
            $details = trim(preg_replace('/^Patch type: Required\s*/', '', $patch["Details"]));
            $this->io->write(sprintf("<info>%s - %s</info>\n%s", $patch["Id"], trim($patch["Title"]), $details));
        }
    }

    /**
     * Determines whether a patch, as returned by the status command, is of type "Required".
     *
     * @param array{Id: string, Title: string, Category: string, Origin:string, Status: string, Details: string} $patch
     * @return bool
     */
    private function isRequiredPatch(array $patch): bool
    {
        return isset($patch["Details"]) && strpos($patch["Details"], "Patch type: Required") === 0;
    }

    /**
     * Retrieves the patches status as a JSON-decoded array.
     *
     * @param string|null $cliPath Path to the patches CLI binary to use, defaults to the magento-patches binary.
     * @return array<array{Id: string, Title: string, Category: string, Origin:string, Status: string, Details: string}>
     * @throws \JsonException|RuntimeException If the command execution fails or returns a non-zero result code.
     */
    private function getStatusJson(?string $cliPath = null): array
    {
        $cliPath = $cliPath ?? $this->getMagentoPatchesCliPath();
        $resultCode = $this->executor->execute(sprintf("%s status -f json", escapeshellarg($cliPath)), $output);

        if ($resultCode !== 0) {
            $message = $output;
            if(is_array($output)){
                $message = implode("\n", $output ?? []);
            }
            if(!empty($message)){
                $message = "Command output : " . $message;
            }
            throw new RuntimeException("Unable to retrieve installed magento patches " . $message);
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
     * The ece-patches binary (from magento/magento-cloud-patches) correctly reports the "Required" patch type
     * for MCLOUD patches, unlike magento-patches (from magento/quality-patches) which reports them as "Optional".
     *
     * @return string
     */
    protected function getEcePatchesCliPath(): string
    {
        $binDir = $this->composer->getConfig()->get('bin-dir');
        if (!file_exists($binDir . '/ece-patches')) {
            throw new \LogicException('ece-patches binary not found');
        }
        return $binDir . '/ece-patches';
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
