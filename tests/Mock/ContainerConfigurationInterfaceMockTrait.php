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

namespace Ymir\Plugin\Tests\Mock;

use PHPUnit\Framework\MockObject\MockObject;
use Ymir\Plugin\DependencyInjection\ContainerConfigurationInterface;

trait ContainerConfigurationInterfaceMockTrait
{
    /**
     * Get a mock of a ContainerConfigurationInterface object.
     */
    private function getContainerConfigurationInterfaceMock(): MockObject
    {
        return $this->getMockBuilder(ContainerConfigurationInterface::class)
                    ->getMock();
    }
}