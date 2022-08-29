<?php

namespace IgorGG\FilesystemAkamaiNetstorage;

use League\Flysystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use Illuminate\Filesystem\FilesystemAdapter;
use League\Flysystem\AkamaiNetStorage\AkamaiNetStorageAdapter;
use League\Flysystem\AkamaiNetStorage\AkamaiNetStorageClientFactory;

class AkamaiNetstorageServiceProvider extends ServiceProvider
{

    /**
     *
     * @return void
     */
    public function boot()
    {
        Storage::extend('akamai', function ($app, $config) {
            $adapter = new AkamaiNetStorageAdapter(
                (new AkamaiNetStorageClientFactory($this->paraseConfig($config)))->getClient(),
                $config['cpCode'] ?? '',
                $config['basePath'] ?? '',
                $config['baseUrl'] ?? ''
            );

            return new FilesystemAdapter(
                new Filesystem($adapter, $config),
                $adapter,
                $config
            );
        });
    }

    /**
     *
     * @param array $config
     * @return array
     */
    private function paraseConfig(array $config): array
    {
        return [
            'signer' => [
                'key' => $config['key'] ?? '',
                'name' => $config['keyName'] ?? '',
            ],
            'edgegrid' => [
                'base_uri' => $config['hostname'] ?? '',
                'timeout' => $config['timeout'],
            ],
        ];
    }
}
