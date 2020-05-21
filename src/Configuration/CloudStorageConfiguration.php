<?php

declare(strict_types=1);

/*
 * This file is part of Ymir WordPress plugin.
 *
 * (c) Carl Alexander <support@ymirapp.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ymir\Plugin\Configuration;

use Ymir\Plugin\CloudProvider\Aws\S3Client;
use Ymir\Plugin\CloudStorage\CloudStorageStreamWrapper;
use Ymir\Plugin\DependencyInjection\Container;
use Ymir\Plugin\DependencyInjection\ContainerConfigurationInterface;

/**
 * Configures the dependency injection container with the cloud storage parameters and services.
 */
class CloudStorageConfiguration implements ContainerConfigurationInterface
{
    /**
     * {@inheritdoc}
     */
    public function modify(Container $container)
    {
        $container['cloud_storage_client'] = $container->service(function (Container $container) {
            return new S3Client($container['http_transport'], $container['cloud_provider_store'], $container['cloud_provider_key'], $container['cloud_provider_secret']);
        });
        $container['cloud_storage_protocol'] = CloudStorageStreamWrapper::PROTOCOL.'://';
    }
}
