<?php

declare(strict_types=1);

namespace Vistynq\Support;

use Vistynq\Config\Config;
use Vistynq\Http\Router;
use Vistynq\Repositories\AuthTokenRepository;
use Vistynq\Repositories\AuthTokenRepositoryInterface;
use Vistynq\Repositories\CanvasRepository;
use Vistynq\Repositories\CanvasRepositoryInterface;
use Vistynq\Repositories\DeletedNoteRepository;
use Vistynq\Repositories\DeletedNoteRepositoryInterface;
use Vistynq\Repositories\DirectLinkRepository;
use Vistynq\Repositories\DirectLinkRepositoryInterface;
use Vistynq\Repositories\IndirectLinkRepository;
use Vistynq\Repositories\IndirectLinkRepositoryInterface;
use Vistynq\Repositories\NoteRepository;
use Vistynq\Repositories\NoteRepositoryInterface;
use Vistynq\Repositories\RefreshTokenRepository;
use Vistynq\Repositories\RefreshTokenRepositoryInterface;
use Vistynq\Repositories\TagRepository;
use Vistynq\Repositories\TagRepositoryInterface;
use Vistynq\Repositories\UserRepository;
use Vistynq\Repositories\UserRepositoryInterface;

/**
 * Единая точка регистрации зависимостей (roadmap R17): Kernel::boot() и
 * интеграционные тесты подключают одни и те же биндинги, чтобы тестовая
 * обвязка не расходилась с продакшен-сборкой.
 */
final class ContainerFactory
{
    private function __construct()
    {
    }

    public static function configure(Container $container, Config $config): void
    {
        $container->singleton(Config::class, static fn (): Config => $config);
        $container->singleton(Database::class, static fn (Container $c): Database => new Database($c->get(Config::class)));

        // Интерфейсы репозиториев (R17): сервисы и контроллеры зависят от
        // контрактов, unit-тесты подставляют фейки (R15).
        $container->bind(UserRepositoryInterface::class, UserRepository::class);
        $container->bind(CanvasRepositoryInterface::class, CanvasRepository::class);
        $container->bind(NoteRepositoryInterface::class, NoteRepository::class);
        $container->bind(TagRepositoryInterface::class, TagRepository::class);
        $container->bind(AuthTokenRepositoryInterface::class, AuthTokenRepository::class);
        $container->bind(RefreshTokenRepositoryInterface::class, RefreshTokenRepository::class);
        $container->bind(DeletedNoteRepositoryInterface::class, DeletedNoteRepository::class);
        $container->bind(DirectLinkRepositoryInterface::class, DirectLinkRepository::class);
        $container->bind(IndirectLinkRepositoryInterface::class, IndirectLinkRepository::class);

        $container->singleton(Router::class, static function (): Router {
            $router = new Router();
            require __DIR__ . '/../routes.php';

            return $router;
        });
    }
}
