<?php

declare(strict_types=1);

namespace League\Flysystem\AkamaiNetStorage;

use GuzzleHttp\HandlerStack;
use Psr\Http\Client\ClientInterface;
use Akamai\Open\EdgeGrid\Client as EdgeGridClient;
use League\Flysystem\AkamaiNetStorage\Handler\Authentication as HandlerAuthentication;

class AkamaiNetStorageClientFactory
{
    /**
     * @var ClientInterface $client
     */
    private $client;

    /**
     *
     * @param array $config
     */
    public function __construct(array $config)
    {
        $config = self::parseConfig($config);

        $signer = new Authentication();
        $signer->setKey($config['signer']['key'], $config['signer']['name']);

        $handlerAuthentication = new HandlerAuthentication();
        $handlerAuthentication->setSigner($signer);

        $handlerStack = HandlerStack::create();
        $handlerStack->push($handlerAuthentication, 'netstorage-handler');

        $config['edgegrid']['handler'] = $handlerStack;

        $this->client = new EdgeGridClient($config['edgegrid']);
    }

    /**
     *
     * @param array $config
     * @return array
     */
    private static function parseConfig(array $config): array
    {
        $signer = $config['signer'] ?? [] + [
            'key' => '',
            'name' => '',
        ];

        if (!isset($signer['key']) || trim($signer['key']) === '') {
            throw new \Exception('The signer key is not set.');
        }

        if (!isset($signer['name']) || trim($signer['name']) === '') {
            throw new \Exception('The signer name is not set.');
        }

        $edgegrid = $config['edgegrid'] ?? [] + [
            'base_uri' => '',
            'timeout'  => EdgeGridClient::DEFAULT_REQUEST_TIMEOUT,
            'debug'    => false,
        ];

        if (!isset($edgegrid['base_uri']) || trim($edgegrid['base_uri']) === '') {
            throw new \Exception('The edgegrid base_uri is not set.');
        }

        return ['signer' => $signer, 'edgegrid' => $edgegrid];
    }

    /**
     *
     * @return ClientInterface
     */
    public function getClient(): ClientInterface
    {
        return $this->client;
    }
}
