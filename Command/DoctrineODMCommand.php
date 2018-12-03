<?php


namespace Doctrine\Bundle\MongoDBBundle\Command;

use Doctrine\ODM\MongoDB\Tools\DisconnectedClassMetadataFactory;
use Doctrine\ODM\MongoDB\Tools\DocumentGenerator;
use Doctrine\ODM\MongoDB\Tools\Console\Helper\DocumentManagerHelper;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\DependencyInjection\ContainerAwareTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;
use const E_USER_DEPRECATED;
use function sprintf;
use function trigger_error;

/**
 * Base class for Doctrine ODM console commands to extend.
 *
 * @author Justin Hileman <justin@justinhileman.info>
 */
abstract class DoctrineODMCommand extends Command implements ContainerAwareInterface
{
    use ContainerAwareTrait;

    /**
     * @return ContainerInterface
     */
    protected function getContainer()
    {
        @trigger_error(
            sprintf('The %s method is deprecated and should not be used. Please wire your dependencies explicitly.', __METHOD__),
            E_USER_DEPRECATED
        );

        return $this->container;
    }

    public static function setApplicationDocumentManager(Application $application, $dmName)
    {
        $dm = $application->getKernel()->getContainer()->get('doctrine_mongodb')->getManager($dmName);
        $helperSet = $application->getHelperSet();
        $helperSet->set(new DocumentManagerHelper($dm), 'dm');
    }

    protected function getDocumentGenerator()
    {
        $documentGenerator = new DocumentGenerator();
        $documentGenerator->setGenerateAnnotations(false);
        $documentGenerator->setGenerateStubMethods(true);
        $documentGenerator->setRegenerateDocumentIfExists(false);
        $documentGenerator->setUpdateDocumentIfExists(true);
        $documentGenerator->setNumSpaces(4);

        return $documentGenerator;
    }

    protected function getDoctrineDocumentManagers()
    {
        return $this->container->get('doctrine_mongodb')->getManagers();
    }

    protected function getBundleMetadatas(Bundle $bundle)
    {
        $namespace = $bundle->getNamespace();
        $bundleMetadatas = [];
        $documentManagers = $this->getDoctrineDocumentManagers();
        foreach ($documentManagers as $dm) {
            $cmf = new DisconnectedClassMetadataFactory();
            $cmf->setDocumentManager($dm);
            $cmf->setConfiguration($dm->getConfiguration());
            $metadatas = $cmf->getAllMetadata();
            foreach ($metadatas as $metadata) {
                if (strpos($metadata->name, $namespace) === 0) {
                    $bundleMetadatas[$metadata->name] = $metadata;
                }
            }
        }

        return $bundleMetadatas;
    }

    protected function findBundle($bundleName)
    {
        $foundBundle = false;
        foreach ($this->getApplication()->getKernel()->getBundles() as $bundle) {
            /* @var $bundle Bundle */
            if (strtolower($bundleName) == strtolower($bundle->getName())) {
                $foundBundle = $bundle;
                break;
            }
        }

        if (!$foundBundle) {
            throw new \InvalidArgumentException("No bundle " . $bundleName . " was found.");
        }

        return $foundBundle;
    }

    /**
     * Transform classname to a path $foundBundle substract it to get the destination
     *
     * @param Bundle $bundle
     * @return string
     */
    protected function findBasePathForBundle($bundle)
    {
        $path = str_replace('\\', DIRECTORY_SEPARATOR, $bundle->getNamespace());
        $search = str_replace('\\', DIRECTORY_SEPARATOR, $bundle->getPath());
        $destination = str_replace(DIRECTORY_SEPARATOR.$path, '', $search, $c);

        if ($c != 1) {
            throw new \RuntimeException(sprintf('Can\'t find base path for bundle (path: "%s", destination: "%s").', $path, $destination));
        }

        return $destination;
    }
}
