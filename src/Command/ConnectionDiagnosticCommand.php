<?php

declare(strict_types=1);

namespace Doctrine\Bundle\MongoDBBundle\Command;

use Doctrine\Bundle\MongoDBBundle\DataCollector\ConnectionDiagnostic;
use Doctrine\Bundle\MongoDBBundle\DataCollector\EncryptionDiagnostic;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\Service\ServiceProviderInterface;

use function array_diff;
use function array_keys;
use function implode;
use function sprintf;

/** @internal */
#[AsCommand(
    name: 'doctrine:mongodb:connection:diagnostic',
    description: 'Diagnose MongoDB configuration and server capabilities for each connection.',
)]
final class ConnectionDiagnosticCommand extends Command
{
    // TODO: Inject as service?
    private readonly EncryptionDiagnostic $encryptionDiagnostic;

    /** @param ServiceProviderInterface<ConnectionDiagnostic> $diagnostics */
    public function __construct(private readonly ServiceProviderInterface $diagnostics)
    {
        parent::__construct();

        $this->encryptionDiagnostic = new EncryptionDiagnostic();
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

        $configOk = $this->printAndCheckExtensionInfo($io);
        $this->printMongocryptdInfo($io);

        foreach ($connectionNames as $name) {
            $diagnostic = $this->diagnostics->get($name);
            $configOk   = $configOk && $this->printAndCheckConnectionDiagnostic($name, $diagnostic, $io);
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

    private function printAndCheckConnectionDiagnostic(string $name, ConnectionDiagnostic $diagnostic, SymfonyStyle $io): bool
    {
        $io->section(sprintf('Connection: %s', $name));

        $configOk = $this->printAndCheckServerInfo($io, $diagnostic);
        $this->printAutoEncryptionConfiguration($io, $diagnostic);

        return $configOk;
    }

    private function printAndCheckExtensionInfo(SymfonyStyle $io): bool
    {
        $io->text('<info>PHP Environment</info>');
        $phpInfo = $this->encryptionDiagnostic->getPhpExtensionInfo();
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
        $mongocryptdInfo = $this->encryptionDiagnostic->getMongocryptdInfo();

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
}
