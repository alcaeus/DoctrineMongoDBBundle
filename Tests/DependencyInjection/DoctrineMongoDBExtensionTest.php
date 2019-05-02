<?php


namespace Doctrine\Bundle\MongoDBBundle\Tests\DependencyInjection;

use Doctrine\Bundle\MongoDBBundle\DependencyInjection\DoctrineMongoDBExtension;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\DependencyInjection\Reference;
use PHPUnit\Framework\TestCase;

class DoctrineMongoDBExtensionTest extends TestCase
{
    public static function buildConfiguration(array $settings = [])
    {
        return [array_merge(
            [
                'connections' => ['default' => []],
                'document_managers' => ['default' => []],
            ],
            $settings
        )];
    }

    public function buildMinimalContainer()
    {
        $container = new ContainerBuilder(new ParameterBag([
            'kernel.root_dir'        => __DIR__,
            'kernel.project_dir'     => __DIR__,
            'kernel.name'            => 'kernel',
            'kernel.environment'     => 'test',
            'kernel.debug'           => 'true',
            'kernel.bundles'         => [],
            'kernel.container_class' => Container::class,
        ]));
        return $container;
    }

    public function testBackwardCompatibilityAliases()
    {
        $loader = new DoctrineMongoDBExtension();
        $loader->load(self::buildConfiguration(), $container = $this->buildMinimalContainer());

        $this->assertEquals('doctrine_mongodb.odm.document_manager', (string) $container->getAlias('doctrine.odm.mongodb.document_manager'));
    }

    /**
     * @dataProvider parameterProvider
     */
    public function testParameterOverride($option, $parameter, $value)
    {
        $container = $this->buildMinimalContainer();
        $container->setParameter('kernel.debug', false);
        $container->setParameter('kernel.bundles', []);
        $loader = new DoctrineMongoDBExtension();
        $loader->load(self::buildConfiguration([$option => $value]), $container);

        $this->assertEquals($value, $container->getParameter('doctrine_mongodb.odm.'.$parameter));
    }

    /**
     * @param array|string $cacheConfig
     *
     * @dataProvider cacheConfigurationProvider
     */
    public function testCacheConfiguration($expectedAliasName, $expectedAliasTarget, $cacheName, $cacheConfig)
    {
        $loader = new DoctrineMongoDBExtension();
        $loader->load(self::buildConfiguration(['document_managers' => ['default' => []]]), $container = $this->buildMinimalContainer());

        $this->assertTrue($container->hasAlias($expectedAliasName));
        $alias = $container->getAlias($expectedAliasName);
        $this->assertEquals($expectedAliasTarget, (string) $alias);
    }

    public static function cacheConfigurationProvider()
    {
        return [
            'metadata_cache_provider' => [
                'expectedAliasName' => 'doctrine.orm.default_metadata_cache',
                'expectedAliasTarget' => 'doctrine_cache.providers.metadata_cache',
                'cacheName' => 'metadata_cache_driver',
                'cacheConfig' => ['cache_provider' => 'metadata_cache'],
            ],
            'query_cache_provider' => [
                'expectedAliasName' => 'doctrine.orm.default_query_cache',
                'expectedAliasTarget' => 'doctrine_cache.providers.query_cache',
                'cacheName' => 'query_cache_driver',
                'cacheConfig' => ['cache_provider' => 'query_cache'],
            ],
            'result_cache_provider' => [
                'expectedAliasName' => 'doctrine.orm.default_result_cache',
                'expectedAliasTarget' => 'doctrine_cache.providers.result_cache',
                'cacheName' => 'result_cache_driver',
                'cacheConfig' => ['cache_provider' => 'result_cache'],
            ],

            'metadata_cache_service' => [
                'expectedAliasName' => 'doctrine.orm.default_metadata_cache',
                'expectedAliasTarget' => 'service_target_metadata',
                'cacheName' => 'metadata_cache_driver',
                'cacheConfig' => ['type' => 'service', 'id' => 'service_target_metadata'],
            ],
            'query_cache_service' => [
                'expectedAliasName' => 'doctrine.orm.default_query_cache',
                'expectedAliasTarget' => 'service_target_query',
                'cacheName' => 'query_cache_driver',
                'cacheConfig' => ['type' => 'service', 'id' => 'service_target_query'],
            ],
            'result_cache_service' => [
                'expectedAliasName' => 'doctrine.orm.default_result_cache',
                'expectedAliasTarget' => 'service_target_result',
                'cacheName' => 'result_cache_driver',
                'cacheConfig' => ['type' => 'service', 'id' => 'service_target_result'],
            ],

            'metadata_cache_array' => [
                'expectedAliasName' => 'doctrine.orm.default_metadata_cache',
                'expectedAliasTarget' => 'doctrine_cache.providers.doctrine.orm.default_metadata_cache',
                'cacheName' => 'metadata_cache_driver',
                'cacheConfig' => 'array',
            ],
            'query_cache_array' => [
                'expectedAliasName' => 'doctrine.orm.default_query_cache',
                'expectedAliasTarget' => 'doctrine_cache.providers.doctrine.orm.default_query_cache',
                'cacheName' => 'query_cache_driver',
                'cacheConfig' => 'array',
            ],
            'result_cache_array' => [
                'expectedAliasName' => 'doctrine.orm.default_result_cache',
                'expectedAliasTarget' => 'doctrine_cache.providers.doctrine.orm.default_result_cache',
                'cacheName' => 'result_cache_driver',
                'cacheConfig' => 'array',
            ],
        ];
    }

    private function getContainer($bundles = 'YamlBundle')
    {
        $bundles = (array) $bundles;

        $map = [];
        foreach ($bundles as $bundle) {
            require_once __DIR__.'/Fixtures/Bundles/'.$bundle.'/'.$bundle.'.php';

            $map[$bundle] = 'DoctrineMongoDBBundle\Tests\DependencyInjection\Fixtures\Bundles\\'.$bundle.'\\'.$bundle;
        }

        return new ContainerBuilder(new ParameterBag([
            'kernel.debug'           => false,
            'kernel.bundles'         => $map,
            'kernel.cache_dir'       => sys_get_temp_dir(),
            'kernel.environment'     => 'test',
            'kernel.name'            => 'kernel',
            'kernel.root_dir'        => __DIR__.'/../../',
            'kernel.project_dir'     => __DIR__.'/../../',
            'kernel.container_class' => Container::class,
        ]));
    }

    public function parameterProvider()
    {
        return [
            ['proxy_namespace', 'proxy_namespace', 'foo'],
            ['proxy-namespace', 'proxy_namespace', 'bar'],
        ];
    }

    public function getAutomappingConfigurations()
    {
        return [
            [
                [
                    'dm1' => [
                        'connection' => 'cn1',
                        'mappings' => [
                            'YamlBundle' => null
                        ]
                    ],
                    'dm2' => [
                        'connection' => 'cn2',
                        'mappings' => [
                            'XmlBundle' => null
                        ]
                    ]
                ]
            ],
            [
                [
                    'dm1' => [
                        'connection' => 'cn1',
                        'auto_mapping' => true
                    ],
                    'dm2' => [
                        'connection' => 'cn2',
                        'mappings' => [
                            'XmlBundle' => null
                        ]
                    ]
                ]
            ],
            [
                [
                    'dm1' => [
                        'connection' => 'cn1',
                        'auto_mapping' => true,
                        'mappings' => [
                            'YamlBundle' => null
                        ]
                    ],
                    'dm2' => [
                        'connection' => 'cn2',
                        'mappings' => [
                            'XmlBundle' => null
                        ]
                    ]
                ]
            ]
        ];
    }

    /**
     * @dataProvider getAutomappingConfigurations
     */
    public function testAutomapping(array $documentManagers)
    {
        $container = $this->getContainer([
            'YamlBundle',
            'XmlBundle'
        ]);

        $loader = new DoctrineMongoDBExtension();

        $loader->load(
            [
                [
                    'default_database' => 'test_database',
                    'connections' => [
                        'cn1' => [],
                        'cn2' => []
                    ],
                    'document_managers' => $documentManagers
                ]
            ], $container);

        $configDm1 = $container->getDefinition('doctrine_mongodb.odm.cn1_configuration');
        $configDm2 = $container->getDefinition('doctrine_mongodb.odm.cn2_configuration');

        $this->assertContains(
            [
                'setDocumentNamespaces',
                [
                    [
                        'YamlBundle' => 'DoctrineMongoDBBundle\Tests\DependencyInjection\Fixtures\Bundles\YamlBundle\Document'
                    ]
                ]
            ], $configDm1->getMethodCalls());

        $this->assertContains(
            [
                'setDocumentNamespaces',
                [
                    [
                        'XmlBundle' => 'DoctrineMongoDBBundle\Tests\DependencyInjection\Fixtures\Bundles\XmlBundle\Document'
                    ]
                ]
            ], $configDm2->getMethodCalls());
    }

    public function testFactoriesAreRegistered()
    {
        $container = $this->getContainer();

        $loader = new DoctrineMongoDBExtension();
        $loader->load(
            [
                [
                    'default_database' => 'test_database',
                    'connections' => [
                        'cn1' => [],
                        'cn2' => []
                    ],
                    'document_managers' => [
                        'dm1' => [
                            'connection' => 'cn1',
                            'repository_factory' => 'repository_factory_service',
                            'persistent_collection_factory' => 'persistent_collection_factory_service',
                        ]
                    ]
                ]
            ], $container);

        $configDm1 = $container->getDefinition('doctrine_mongodb.odm.cn1_configuration');
        $this->assertContains(
            [
                'setRepositoryFactory',
                [new Reference('repository_factory_service')]
            ], $configDm1->getMethodCalls());
        $this->assertContains(
            [
                'setPersistentCollectionFactory',
                [new Reference('persistent_collection_factory_service')]
            ], $configDm1->getMethodCalls());
    }

    public function testPublicServicesAndAliases()
    {
        $loader = new DoctrineMongoDBExtension();
        $loader->load(self::buildConfiguration(), $container = $this->buildMinimalContainer());

        $this->assertTrue($container->getDefinition('doctrine_mongodb')->isPublic());
        $this->assertTrue($container->getDefinition('doctrine_mongodb.odm.default_document_manager')->isPublic());
        $this->assertTrue($container->getAlias('doctrine_mongodb.odm.document_manager')->isPublic());
    }

    public function testDocumentManagerWithDifferentConnectionName()
    {
        $config = [
            [
                'document_managers' => [
                    'dm1' => [
                        'connection' => 'conn1',
                    ],
                ],
                'connections' => [
                    'conn1' => [],
                ],
            ],
        ];

        $loader = new DoctrineMongoDBExtension();
        $loader->load($config, $container = $this->buildMinimalContainer());

        $this->assertFalse($container->hasDefinition('doctrine_mongodb.odm.dm1_configuration'));
        $this->assertTrue($container->hasDefinition('doctrine_mongodb.odm.conn1_configuration'));

        $definition = $container->getDefinition('doctrine_mongodb.odm.conn1_connection');
        $this->assertEquals(new Reference('doctrine_mongodb.odm.conn1_configuration'), $definition->getArgument(2));

        $definition = $container->getDefinition('doctrine_mongodb.odm.dm1_document_manager');
        $this->assertEquals(new Reference('doctrine_mongodb.odm.conn1_configuration'), $definition->getArgument(1));
    }
}
