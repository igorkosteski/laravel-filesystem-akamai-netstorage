<?php

namespace IgorGG\FilesystemAkamaiNetstorage;

use League\Flysystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Contracts\Foundation\Application;
use League\Flysystem\AkamaiNetStorage\AkamaiNetStorageAdapter;
use League\Flysystem\AkamaiNetStorage\AkamaiNetStorageClientFactory;

class AkamaiNetstorageServiceProvider extends ServiceProvider {

    public function boot(): void
    {
        Storage::extend('akamai', function (Application $app, array $config) {
            $adapter = new AkamaiNetStorageAdapter(
                (new AkamaiNetStorageClientFactory([
                    'signer' => [
                        'key' => $config['key'] ?? '',
                        'name' => $config['keyName'] ?? '',
                    ],
                    'edgegrid' => [
                        'base_uri' => $config['hostname'] ?? '',
                        'timeout' => $config['timeout'] ?? 300,
                    ],
                ]))->getClient(),
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
}
