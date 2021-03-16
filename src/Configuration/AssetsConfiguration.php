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

use Ymir\Plugin\DependencyInjection\Container;
use Ymir\Plugin\DependencyInjection\ContainerConfigurationInterface;

/**
 * Configures the dependency injection container with WordPress attachment services.
 */
class AssetsConfiguration implements ContainerConfigurationInterface
{
    /**
     * {@inheritdoc}
     */
    public function modify(Container $container)
    {
        $container['assets_url'] = $container->service(function () {
            return getenv('YMIR_CUSTOM_ASSETS_URL') ?: (getenv('YMIR_ASSETS_URL') ?: (defined('YMIR_ASSETS_URL') ? YMIR_ASSETS_URL : ''));
        });
    }
}
