<?php

namespace Blackbird\MagentoQualityPatchesApplier;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\Capable;
use Composer\Plugin\Capability\CommandProvider as CommandProviderCapability;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use Composer\Util\ProcessExecutor;
use Exception;
use RuntimeException;

class Patches implements PluginInterface, EventSubscriberInterface, Capable
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
     * {@inheritDoc}
     */
    public function getCapabilities()
    {
        return [
            CommandProviderCapability::class => CommandProvider::class,
        ];
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
        $exitOnFailureEnv = getenv('COMPOSER_EXIT_ON_MAGENTO_PATCH_FAILURE');
        if ($exitOnFailureEnv !== false && $exitOnFailureEnv !== '') {
            $exitOnFailure = filter_var($exitOnFailureEnv, FILTER_VALIDATE_BOOLEAN);
        } else {
            $exitOnFailure = ($extra['composer-exit-on-magento-patch-failure'] ?? null) !== false;
        }

        $magentoPatches = is_array($extra['magento-patches'] ?? null) ? $extra['magento-patches'] : [];

        // Each step gets its own try/catch and is called in sequence (never nested in a way where a
        // "return" in one would skip the other), so hotfixes always run after the configured/required
        // patches - matching Magento Cloud's own order - and neither step's failure can hide the other's.
        $this->applyConfiguredPatches($magentoPatches, $event, $exitOnFailure);
        $this->applyHotfixesStep($magentoPatches, $exitOnFailure);
        $this->reportPatchStatusWarnings();
    }

    /**
     * Applies the patches listed in extra.magento-patches.apply, plus any auto-installed required patches.
     *
     * @param array $magentoPatches
     * @param Event $event
     * @param bool $exitOnFailure
     * @return void
     * @throws Exception
     */
    private function applyConfiguredPatches(array $magentoPatches, Event $event, bool $exitOnFailure)
    {
        try {
            if (empty($magentoPatches)) {
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
     * @param array $magentoPatches
     * @param bool $exitOnFailure
     * @return void
     * @throws Exception
     */
    private function applyHotfixesStep(array $magentoPatches, bool $exitOnFailure)
    {
        try {
            $this->maybeApplyHotfixes($magentoPatches);
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

        $extra = $this->composer->getPackage()->getExtra();
        $magentoPatches = is_array($extra['magento-patches'] ?? null) ? $extra['magento-patches'] : [];

        // Revert m2-hotfixes first, as its own independent step, so their presence can never conflict
        // with reverting or reapplying the official quality/cloud patches below.
        try {
            $this->maybeRevertHotfixes($magentoPatches);
        } catch (Exception $e) {
            $this->io->write(sprintf("<warning>Warning : %s</warning>", $e->getMessage()));
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
     * Returns the path to the patch-status binary (Adobe's Monthly Security Release Versioning Tool),
     * if present, or null otherwise. Unlike the other CLI paths, this one is entirely optional.
     *
     * @return string|null
     */
    protected function getPatchStatusCliPath(): ?string
    {
        $binDir = $this->composer->getConfig()->get('bin-dir');
        $path = $binDir . '/patch-status';
        return file_exists($path) ? $path : null;
    }

    /**
     * If vendor/bin/patch-status is available, runs it and prints a warning listing any missing patches
     * or unprotected CVEs it reports. Purely informational: never throws and does not honor
     * composer-exit-on-magento-patch-failure, since a security-status check should never abort the run.
     *
     * @return void
     */
    private function reportPatchStatusWarnings()
    {
        $cliPath = $this->getPatchStatusCliPath();
        if ($cliPath === null) {
            return;
        }

        try {
            // Invoked via the PHP binary directly rather than relying on the file's executable bit and
            // shebang, since patch-status is not always installed with the executable permission set.
            $command = sprintf(
                "%s %s --format=json --root=%s",
                escapeshellarg(PHP_BINARY),
                escapeshellarg($cliPath),
                escapeshellarg($this->getProjectRootPath())
            );
            $resultCode = $this->executor->execute($command, $output);
            if ($resultCode !== 0) {
                $this->io->write("<comment>Unable to determine patch/CVE status via patch-status, skipping.</comment>");
                return;
            }

            $data = json_decode($output, true);
            if (!is_array($data)) {
                return;
            }

            $missingPatches = $data['missing_patches'] ?? [];

            $vulnerableCves = [];
            foreach ($data['vulnerability_status'] ?? [] as $cve => $info) {
                $status = $info['status'] ?? null;
                if ($status !== 'PROTECTED' && $status !== 'NOT_APPLICABLE') {
                    $vulnerableCves[] = sprintf('%s (%s)', $cve, $status ?? 'unknown');
                }
            }

            if (empty($missingPatches) && empty($vulnerableCves)) {
                $this->io->write("<info>patch-status reports no missing patch and no unprotected CVE.</info>");
                return;
            }

            if (!empty($missingPatches)) {
                $this->io->write(sprintf(
                    "<warning>patch-status reports %d missing patch(es) : %s</warning>",
                    count($missingPatches),
                    implode(" ", $missingPatches)
                ));
            }

            if (!empty($vulnerableCves)) {
                $this->io->write(sprintf(
                    "<warning>patch-status reports %d unprotected CVE(s) : %s</warning>",
                    count($vulnerableCves),
                    implode(", ", $vulnerableCves)
                ));
            }
        } catch (Exception $e) {
            $this->io->write(sprintf("<comment>Unable to determine patch/CVE status via patch-status : %s</comment>", $e->getMessage()));
        }
    }

    /**
     * Applies the custom patches found in the m2-hotfixes directory, unless disabled or unless a cloud
     * environment is detected (Magento Cloud already applies them itself during deployment in that case).
     *
     * @param array $magentoPatches
     * @return void
     * @throws RuntimeException
     */
    private function maybeApplyHotfixes(array $magentoPatches)
    {
        $setting = array_key_exists("auto-install-hotfixes", $magentoPatches) ? $magentoPatches["auto-install-hotfixes"] : null;

        if ($setting === false) {
            $this->io->write("<comment>m2-hotfixes auto-apply is disabled (auto-install-hotfixes: false), skipping.</comment>");
            return;
        }

        if ($setting !== true && $this->isCloudEnvironmentDetected()) {
            $this->io->write("<comment>Cloud environment detected, skipping m2-hotfixes auto-apply (Magento Cloud already applies them during deployment).</comment>");
            return;
        }

        $this->applyHotfixes();
    }

    /**
     * Reverts any currently-applied m2-hotfixes patches, under the same conditions maybeApplyHotfixes
     * uses to decide whether to apply them, so a plugin-disabled or cloud-managed setup is left alone.
     *
     * @param array $magentoPatches
     * @return void
     * @throws RuntimeException
     */
    private function maybeRevertHotfixes(array $magentoPatches)
    {
        $setting = array_key_exists("auto-install-hotfixes", $magentoPatches) ? $magentoPatches["auto-install-hotfixes"] : null;

        if ($setting === false) {
            return;
        }

        if ($setting !== true && $this->isCloudEnvironmentDetected()) {
            return;
        }

        $this->revertHotfixes();
    }

    /**
     * Detects whether the current process is running on a Magento Cloud (Platform.sh based) environment.
     *
     * @return bool
     */
    private function isCloudEnvironmentDetected(): bool
    {
        $cloudEnvVars = [
            "MAGENTO_CLOUD_MODE",
            "MAGENTO_CLOUD_APPLICATION_NAME",
            "MAGENTO_CLOUD_PROJECT",
            "MAGENTO_CLOUD_ENVIRONMENT",
            "MAGENTO_CLOUD_RELATIONSHIPS",
            "PLATFORM_APPLICATION_NAME",
            "PLATFORM_PROJECT",
            "PLATFORM_ENVIRONMENT",
        ];

        foreach ($cloudEnvVars as $envVar) {
            $value = getenv($envVar);
            if ($value !== false && $value !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * Applies the *.patch files found in the project's m2-hotfixes directory, in alphabetical order by
     * filename - mirroring how Magento Cloud applies custom patches during deployment - using the
     * system "patch" tool directly, without depending on magento/magento-cloud-patches internals.
     *
     * @return void
     * @throws RuntimeException
     */
    private function applyHotfixes()
    {
        $hotfixesDir = $this->getProjectRootPath() . '/m2-hotfixes';
        $patchFiles = glob($hotfixesDir . '/*.patch') ?: [];
        sort($patchFiles);

        if (empty($patchFiles)) {
            $this->io->write("<comment>No custom patches found in the m2-hotfixes directory.</comment>");
            return;
        }

        $this->io->write("<comment>Applying custom patches from the m2-hotfixes directory:</comment>");
        foreach ($patchFiles as $patchFile) {
            $this->applyHotfixPatch($patchFile);
        }
    }

    /**
     * Applies a single custom patch file, skipping it if it is already applied.
     *
     * @param string $patchFile
     * @return void
     * @throws RuntimeException
     */
    private function applyHotfixPatch(string $patchFile)
    {
        $name = basename($patchFile);
        $projectRoot = $this->getProjectRootPath();
        $baseCommand = sprintf(
            "patch -p1 -f -d %s -i %s",
            escapeshellarg($projectRoot),
            escapeshellarg($patchFile)
        );

        $resultCode = $this->executor->execute($baseCommand . " --dry-run", $output);
        if ($resultCode === 0) {
            $resultCode = $this->executor->execute($baseCommand, $output);
            if ($resultCode !== 0) {
                throw new RuntimeException(sprintf("Error applying custom patch %s : %s", $name, $output));
            }
            $this->io->write(sprintf("<info>%s applied</info>", $name));
            return;
        }

        $reverseResultCode = $this->executor->execute($baseCommand . " --dry-run --reverse", $reverseOutput);
        if ($reverseResultCode === 0) {
            $this->io->write(sprintf("<comment>%s already applied, skipping</comment>", $name));
            return;
        }

        throw new RuntimeException(sprintf("Error applying custom patch %s : %s", $name, $output));
    }

    /**
     * Reverts the *.patch files found in the project's m2-hotfixes directory that are currently applied,
     * in reverse alphabetical order (the opposite of the order they were applied in).
     *
     * @return void
     * @throws RuntimeException
     */
    private function revertHotfixes()
    {
        $hotfixesDir = $this->getProjectRootPath() . '/m2-hotfixes';
        $patchFiles = glob($hotfixesDir . '/*.patch') ?: [];
        rsort($patchFiles);

        $reverted = [];
        foreach ($patchFiles as $patchFile) {
            if ($this->revertHotfixPatch($patchFile)) {
                $reverted[] = basename($patchFile);
            }
        }

        if (!empty($reverted)) {
            $this->io->write(sprintf(
                "<comment>Reverted %d custom m2-hotfixes patch(es) : %s</comment>",
                count($reverted),
                implode(" ", $reverted)
            ));
        }
    }

    /**
     * Reverts a single custom patch file if it is currently applied.
     *
     * @param string $patchFile
     * @return bool true if the patch was applied and has been reverted, false if it wasn't applied.
     * @throws RuntimeException
     */
    private function revertHotfixPatch(string $patchFile): bool
    {
        $name = basename($patchFile);
        $projectRoot = $this->getProjectRootPath();
        $baseCommand = sprintf(
            "patch -p1 -f -d %s -i %s",
            escapeshellarg($projectRoot),
            escapeshellarg($patchFile)
        );

        // Only revert if it is currently applied (the reverse dry-run succeeds); otherwise there is
        // nothing to do for this patch.
        $reverseCheckCode = $this->executor->execute($baseCommand . " --dry-run --reverse", $output);
        if ($reverseCheckCode !== 0) {
            return false;
        }

        $resultCode = $this->executor->execute($baseCommand . " --reverse", $output);
        if ($resultCode !== 0) {
            throw new RuntimeException(sprintf("Error reverting custom patch %s : %s", $name, $output));
        }

        return true;
    }

    /**
     * @return string
     */
    private function getProjectRootPath(): string
    {
        return dirname($this->composer->getConfig()->get('vendor-dir'));
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
