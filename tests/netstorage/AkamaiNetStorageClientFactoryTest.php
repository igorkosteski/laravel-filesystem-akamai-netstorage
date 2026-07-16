<?php

namespace League\Flysystem\AkamaiNetStorage\Tests;

use Exception;
use League\Flysystem\AkamaiNetStorage\AkamaiNetStorageClientFactory;

class AkamaiNetStorageClientFactoryTest extends \PHPUnit\Framework\TestCase
{
    public function testValidGetClient()
    {
        $config = [
            'signer' => [
                'key' => 'netstorage-key',
                'name' => 'key-name',
            ],
            'edgegrid' => [
                'base_uri' => 'testing.akamaihd.net.example.org',
                'timeout' => 300,
            ]
        ];

        $client = new AkamaiNetStorageClientFactory($config);

        $this->assertInstanceOf(AkamaiNetStorageClientFactory::class, $client);
    }

    /**
     * @dataProvider invalidConfigProvider
     */
    public function testInvalidGetClient(Exception $excepton, array $config)
    {
        $this->expectExceptionObject($excepton);

        new AkamaiNetStorageClientFactory($config);
    }

    public static function invalidConfigProvider()
    {
        return [
            [
                new Exception('The signer key is not set.'),
                [
                    'edgegrid' => [
                        'base_uri' => 'testing.akamaihd.net.example.org',
                    ],
                ]
            ],
            [
                new Exception('The signer key is not set.'),
                [
                    'signer' => [
                        'name' => 'key-name',
                    ],
                    'edgegrid' => [
                        'base_uri' => 'testing.akamaihd.net.example.org',
                    ],
                ]
            ],
            [
                new Exception('The signer name is not set.'),
                [
                    'signer' => [
                        'key' => 'netstorage-key',
                    ],
                    'edgegrid' => [
                        'base_uri' => 'testing.akamaihd.net.example.org',
                    ],
                ]
            ],
            [
                new Exception('The edgegrid base_uri is not set.'),
                [
                    'signer' => [
                        'key' => 'netstorage-key',
                        'name' => 'key-name',
                    ],
                    'edgegrid' => [],
                ]
            ],
            [
                new Exception('The edgegrid base_uri is not set.'),
                [
                    'signer' => [
                        'key' => 'netstorage-key',
                        'name' => 'key-name',
                    ],
                ]
            ],
        ];
    }
}
