<?php

declare(strict_types=1);

namespace Doctrine\Bundle\MongoDBBundle\Command;

use Composer\InstalledVersions;
use Doctrine\Bundle\MongoDBBundle\DataCollector\ConnectionDiagnostic;
use ReflectionExtension;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\Service\ServiceProviderInterface;

use function array_diff;
use function array_keys;
use function exec;
use function explode;
use function extension_loaded;
use function file_exists;
use function getenv;
use function implode;
use function ob_end_clean;
use function ob_get_contents;
use function ob_start;
use function phpversion;
use function preg_match;
use function preg_quote;
use function sprintf;
use function trim;

/** @internal */
#[AsCommand(
    name: 'doctrine:mongodb:connection:diagnostic',
    description: 'Diagnose MongoDB configuration and server capabilities for each connection.',
)]
final class ConnectionDiagnosticCommand extends Command
{
    /** @param ServiceProviderInterface<ConnectionDiagnostic> $diagnostics */
    public function __construct(private readonly ServiceProviderInterface $diagnostics)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('connection', 'c', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'The name of the connection to diagnose. If not specified, all connections will be diagnosed.', [], $this->getConnectionNames(...));
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('MongoDB Encryption Diagnostics');

        if (! $this->diagnostics) {
            $io->warning('No MongoDB connections found. Please ensure you have configured your connections correctly.');

            return Command::SUCCESS;
        }

        /** @var string[] $connectionNames */
        $connectionNames = $input->getOption('connection');
        if ($connectionNames) {
            if (array_diff($connectionNames, $this->getConnectionNames())) {
                $io->error('One or more specified connections do not exist. Available connections: ' . implode(', ', $this->getConnectionNames()));

                return Command::INVALID;
            }
        } else {
            $connectionNames = $this->getConnectionNames();
        }

        $configOk = true;

        $configOk = $configOk && $this->printAndCheckExtensionInfo($io);
        $this->printMongocryptdInfo($io);

        foreach ($connectionNames as $name) {
            $diagnostic = $this->diagnostics->get($name);
            $io->section(sprintf('Connection: %s', $name));

            $configOk = $configOk && $this->printAndCheckServerInfo($io, $diagnostic);
            $this->printAutoEncryptionConfiguration($io, $diagnostic);
        }

        if ($configOk) {
            $io->success('System looks ok for encryption support.');
        } else {
            $io->warning('Not all requirements for encryption support are met. Please check the diagnostics above.');
        }

        return Command::SUCCESS;
    }

    /** @return string[] */
    private function getConnectionNames(): array
    {
        return array_keys($this->diagnostics->getProvidedServices());
    }

    /** @return array{extensionLoaded: bool, extensionVersion: ?string, extensionSupportsLibmongocrypt: bool, libraryVersion: ?string} */
    private function getPhpExtensionInfo(): array
    {
        // There will be no "libmongocrypt" entry unless libmongocrypt is not available.
        // When ext-mongodb was compiled with libmongocrypt support, either "libmongocrypt bundled version"
        // or "libmongocrypt library version" will be available instead
        $libmongocryptAvailable = $this->getExtensionInfoRow('libmongocrypt') !== 'disabled';

        return [
            'extensionLoaded' => extension_loaded('mongodb'),
            'extensionVersion' => phpversion('mongodb') ?: null,
            'extensionSupportsLibmongocrypt' => $libmongocryptAvailable,
            'libraryVersion' => InstalledVersions::getPrettyVersion('mongodb/mongodb'),
        ];
    }

    /** @return array{mongocryptdPath: ?string, mongocryptdVersion: ?string} */
    private function getMongocryptdInfo(): array
    {
        $mongocryptdPath = $this->findMongocryptdPath();

        return [
            'mongocryptdPath' => $mongocryptdPath,
            'mongocryptdVersion' => $this->getMongocryptdVersion($mongocryptdPath),
        ];
    }

    public function getMongocryptdVersion(?string $mongocryptdPath): ?string
    {
        if ($mongocryptdPath === null) {
            return null;
        }

        $output = [];
        exec($mongocryptdPath . ' --version', $output);

        if (isset($output[0])) {
            return trim($output[0]);
        }

        return null;
    }

    private function findMongocryptdPath(): ?string
    {
        $paths = explode(':', getenv('PATH') ?: '');

        foreach ($paths as $path) {
            if (file_exists($path . '/mongocryptd')) {
                return $path . '/mongocryptd';
            }
        }

        return null;
    }

    private function printAndCheckExtensionInfo(SymfonyStyle $io): bool
    {
        $io->text('<info>PHP Environment</info>');
        $phpInfo = $this->getPhpExtensionInfo();
        $io->listing([
            'MongoDB extension loaded: ' . ($phpInfo['extensionLoaded'] ? 'Yes' : 'No'),
            'MongoDB extension version: ' . ($phpInfo['extensionVersion'] ?: '[unknown]'),
            'MongoDB extension supports libmongocrypt: ' . ($phpInfo['extensionSupportsLibmongocrypt'] ? 'Yes' : 'No'),
            'MongoDB library version: ' . ($phpInfo['libraryVersion'] ?: '[unknown]'),
        ]);

        $extensionOk = $phpInfo['extensionLoaded'] && $phpInfo['extensionSupportsLibmongocrypt'];

        if (! $extensionOk) {
            $io->warning('At least one extension requirement is not met. Encryption may not work.');
        }

        return $extensionOk;
    }

    private function printMongocryptdInfo(SymfonyStyle $io): void
    {
        $io->text('<info>mongocryptd information</info>');
        $mongocryptdInfo = $this->getMongocryptdInfo();

        if ($mongocryptdInfo['mongocryptdPath'] === null) {
            $io->listing(['mongocryptd: not found']);
        } else {
            $io->listing([
                'mongocryptd path: ' . $mongocryptdInfo['mongocryptdPath'],
                'mongocryptd version: ' . ($mongocryptdInfo['mongocryptdVersion'] ?: '[unknown]'),
            ]);
        }
    }

    private function printAndCheckServerInfo(SymfonyStyle $io, ConnectionDiagnostic $diagnostic): bool
    {
        $io->text('<info>Server Information</info>');
        $serverInfo = $diagnostic->getServerInfo();

        $io->listing([
            'Server Version: ' . ($serverInfo['version'] ?? '[unknown]'),
            'Topology: ' . $serverInfo['topologyName'],
        ]);

        if (! $serverInfo['versionSupported']) {
            $io->warning('This server version does not support encryption.');
        }

        if (! $serverInfo['topologySupported']) {
            $io->warning('This topology does not support encryption.');
        }

        return $serverInfo['versionSupported'] && $serverInfo['topologySupported'];
    }

    private function printAutoEncryptionConfiguration(SymfonyStyle $io, ConnectionDiagnostic $diagnostic): void
    {
        $io->text('<info>Auto Encryption Configuration</info>');
        if (! $diagnostic->usesAutoEncryption()) {
            $io->text('Auto encryption is not enabled for this connection.');

            return;
        }

        $autoEncryptionInfo = $diagnostic->getAutoEncryptionInfo();
        $io->listing([
            'Auto Encryption Enabled: ' . ($autoEncryptionInfo['autoEncryptionEnabled'] ? 'Yes' : 'No'),
            'Key Vault Namespace: ' . $autoEncryptionInfo['keyVaultNamespace'],
            'Key Count: ' . $autoEncryptionInfo['keyCount'],
        ]);
    }

    private function getExtensionInfoRow(string $row): ?string
    {
        $pattern = sprintf('/^%s(.*)$/m', preg_quote($row . ' => '));

        if (preg_match($pattern, $this->getExtensionInfo(), $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    private function getExtensionInfo(): string
    {
        $extension = new ReflectionExtension('mongodb');

        ob_start();
        $extension->info();
        $info = ob_get_contents();
        ob_end_clean();

        return $info;
    }
}
