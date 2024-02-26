<?php

declare(strict_types=1);

namespace DDD\Symfony\Bundles;

use DDD\Domain\Base\Repo\DB\Doctrine\EntityManagerFactory;
use DDD\Infrastructure\Libs\Config;
use DDD\Infrastructure\Services\AppService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

class AppBundle extends Bundle
{
    protected static ContainerInterface $defaultContainer;

    public function boot()
    {
        $projectDirectory = $this->container->getParameterBag()->get('kernel.project_dir');
        if (!defined('APP_ROOT_DIR')) {
            define('APP_ROOT_DIR', $projectDirectory);
        }
        self::$defaultContainer = $this->container;

        Config::addConfigDirectory(AppService::instance()->getRootDir() . '/config/app');
        parent::boot();
    }

    public static function getContainer(): ContainerInterface
    {
        return self::$defaultContainer;
    }


}