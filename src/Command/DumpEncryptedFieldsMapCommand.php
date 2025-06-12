<?php

declare(strict_types=1);

namespace Doctrine\Bundle\MongoDBBundle\Command;

use Doctrine\ODM\MongoDB\DocumentManager;
use Doctrine\ODM\MongoDB\Mapping\ClassMetadata;
use Doctrine\ODM\MongoDB\Utility\EncryptedFieldsMapGenerator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Yaml\Dumper;
use Symfony\Contracts\Service\ServiceProviderInterface;

use function array_combine;
use function array_keys;
use function array_map;
use function array_values;
use function sprintf;
use function var_export;

/** @internal */
#[AsCommand(
    name: 'doctrine:mongodb:dump-encrypted-fields-map',
    description: 'Dumps the encrypted fields map for all documents in the configured connections.',
)]
final class DumpEncryptedFieldsMapCommand extends Command
{
    /** @param ServiceProviderInterface<DocumentManager> $documentManagers */
    public function __construct(private readonly ServiceProviderInterface $documentManagers) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('format', 'f', InputOption::VALUE_REQUIRED, 'The output format for the encrypted fields map (yaml, php)', 'yaml');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $format = $input->getOption('format');

        $dumper = new Dumper();

        foreach ($this->documentManagers as $name => $documentManager) {
            assert($documentManager instanceof DocumentManager);
            $generator = new EncryptedFieldsMapGenerator($documentManager->getMetadataFactory());
            $encryptedFieldsMap = $generator->getEncryptedFieldsMap();

            if (empty($encryptedFieldsMap)) {
                continue;
            }

            $encryptedFieldsMap = array_combine(
                // Convert class names in keys to their full namespaces
                array_map(
                    fn (string $fqcn): string => $this->getDocumentNamespace(
                        $documentManager->getClassMetadata($fqcn),
                        $documentManager->getConfiguration()->getDefaultDB(),
                    ),
                    array_keys($encryptedFieldsMap),
                ),
                array_values($encryptedFieldsMap),
            );

            $io->section(sprintf('Dumping encrypted fields map for document manager "%s"', $name));
            switch ($format) {
                case 'yaml':
                    $outputContent = $dumper->dump($encryptedFieldsMap, 3);
                    break;
                case 'php':
                    $outputContent = var_export($encryptedFieldsMap, true);
                    break;
                default:
                    $io->error(sprintf('Unknown format "%s"', $format));

                    return Command::FAILURE;
            }

            $io->block($outputContent);
        }

        return Command::SUCCESS;
    }

    private function getDocumentNamespace(ClassMetadata $metadata, string $defaultDb): string
    {
        $db = $metadata->getDatabase();
        $db = $db ?: $defaultDb;
        $db = $db ?: 'doctrine';

        return $db . '.' . $metadata->getCollection();
    }
}
