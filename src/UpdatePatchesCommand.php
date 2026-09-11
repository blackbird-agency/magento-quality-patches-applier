<?php

namespace Blackbird\MagentoQualityPatchesApplier;

use Composer\Command\BaseCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

class UpdatePatchesCommand extends BaseCommand
{
    private const PACKAGE_CLOUD = 'magento/magento-cloud-patches';
    private const PACKAGE_QUALITY = 'magento/quality-patches';

    private const TARGET_PACKAGES = [
        'cloud' => [self::PACKAGE_CLOUD],
        'quality' => [self::PACKAGE_QUALITY],
        'all' => [self::PACKAGE_CLOUD, self::PACKAGE_QUALITY],
    ];

    /**
     * {@inheritDoc}
     */
    protected function configure()
    {
        $this
            ->setName('magento-patches:update')
            ->setDescription('Requires the latest available version of magento-cloud-patches and/or quality-patches, pinned to their exact version.')
            ->addArgument(
                'target',
                InputArgument::OPTIONAL,
                'Which patch package(s) to update: "cloud", "quality" or "all"',
                'cloud'
            );
    }

    /**
     * {@inheritDoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $target = $input->getArgument('target');
        if (!isset(self::TARGET_PACKAGES[$target])) {
            $output->writeln(sprintf(
                '<error>Invalid target "%s", expected one of: %s</error>',
                $target,
                implode(', ', array_keys(self::TARGET_PACKAGES))
            ));
            return 1;
        }

        $requirements = [];
        foreach (self::TARGET_PACKAGES[$target] as $package) {
            $latestVersion = $this->getLatestVersion($package, $output);
            if ($latestVersion === null) {
                $output->writeln(sprintf('<error>Unable to determine the latest version of %s, skipping.</error>', $package));
                continue;
            }
            $output->writeln(sprintf('<info>%s latest version: %s</info>', $package, $latestVersion));
            $requirements[] = sprintf('%s:%s', $package, $latestVersion);
        }

        if (empty($requirements)) {
            $output->writeln('<comment>Nothing to update.</comment>');
            return 1;
        }

        return $this->requirePackages($requirements, $output);
    }

    /**
     * @param string $packageName
     * @param OutputInterface $output
     * @return string|null
     */
    private function getLatestVersion(string $packageName, OutputInterface $output): ?string
    {
        $bufferedOutput = new BufferedOutput();
        $bufferedOutput->setVerbosity($output->getVerbosity());

        // Composer commands write through the Application's IO, which is only bound to the given
        // OutputInterface when invoked via Application::doRun() - calling Command::run() directly
        // would silently write to whatever output was bound during the outer command's own doRun().
        // doRun() (unlike run()) does not catch exceptions itself, so a "package not found" failure
        // must be caught here or it would bubble up and abort the whole outer composer process.
        try {
            $resultCode = $this->getApplication()->doRun(new ArrayInput([
                'command' => 'show',
                'package' => $packageName,
                '--latest' => true,
                '--format' => 'json',
            ]), $bufferedOutput);
        } catch (Throwable $e) {
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));
            return null;
        }

        $raw = $bufferedOutput->fetch();

        if ($resultCode !== 0) {
            if (trim($raw) !== '') {
                $output->writeln($raw);
            }
            return null;
        }

        // Composer may print unrelated warnings (e.g. about a script name colliding with a command)
        // into the same output before the actual JSON payload, so only decode the JSON object itself.
        $jsonStart = strpos($raw, '{');
        $jsonEnd = strrpos($raw, '}');
        $json = ($jsonStart !== false && $jsonEnd !== false && $jsonEnd > $jsonStart)
            ? substr($raw, $jsonStart, $jsonEnd - $jsonStart + 1)
            : $raw;

        $data = json_decode($json, true);
        if (!isset($data['latest'])) {
            $output->writeln(sprintf(
                '<comment>"composer show %s --latest" did not report a "latest" version (raw output below, re-run with -vvv for more details).</comment>',
                $packageName
            ));
            if (trim($raw) !== '') {
                $output->writeln($raw);
            }
            return null;
        }

        return $data['latest'];
    }

    /**
     * @param array<string> $requirements
     * @param OutputInterface $output
     * @return int
     */
    private function requirePackages(array $requirements, OutputInterface $output): int
    {
        try {
            return $this->getApplication()->doRun(new ArrayInput([
                'command' => 'require',
                'packages' => $requirements,
                '--fixed' => true,
                '--no-interaction' => true,
            ]), $output);
        } catch (Throwable $e) {
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));
            return 1;
        }
    }
}
