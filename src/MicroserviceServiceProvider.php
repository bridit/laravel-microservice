<?php

declare(strict_types=1);

namespace Bridit\Microservices;

use Aws\Laravel\AwsFacade as Aws;
use Aws\Laravel\AwsServiceProvider;
use Bridit\Sns\SnsBroadcastServiceProvider;
use Exception;
use Illuminate\Foundation\Application as LaravelApplication;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;
use Laravel\Lumen\Application as LumenApplication;
use function storage_path;

class MicroserviceServiceProvider extends ServiceProvider
{

  /**
   * @throws Exception
   */
  public function boot(): void
  {
    if ($this->app['config']->get('app.env') === 'local') {
      return;
    }

    $this->ensureCoreDirectoriesExists();

    if (class_exists('Laravel\Passport\Passport')) {
      $this->ensurePassportKeysExists();
    }
  }

  /**
   * @inheritDoc
   */
  public function register()
  {
    $this->app->register(AwsServiceProvider::class);
    $this->app->register(SnsBroadcastServiceProvider::class);

    $this->healthCheckRouteRegister();

    Config::set('logging.channels.batch', Config::get('logging.channels.stack'));
    Config::set('logging.default', 'batch');
  }

  private function healthCheckRouteRegister(): void
  {
    if ($this->app instanceof LaravelApplication) {
      $this->app['router']
        ->middleware('web')
        ->get('health-check', 'Bridit\Microservices\Http\Controllers\HealthCheckController@check');
    } elseif ($this->app instanceof LumenApplication) {
      $this->app['router']->group([], function ($router): void {
        $router->get('health-check', 'Bridit\Microservices\Http\Controllers\HealthCheckController@check');
      });
    }
  }

  private function ensureCoreDirectoriesExists(): void
  {
    $this->createDirectoryIfNotExists(storage_path('app/public'));
    $this->createDirectoryIfNotExists(storage_path('framework/cache'));
    $this->createDirectoryIfNotExists(storage_path('framework/sessions'));
    $this->createDirectoryIfNotExists(storage_path('framework/testing'));
    $this->createDirectoryIfNotExists(storage_path('framework/views'));
    $this->createDirectoryIfNotExists(storage_path('logs'));
  }

  private function createDirectoryIfNotExists(string $path): void
  {
    if (! is_dir($path)) {
      mkdir($path, 0755, true);
    }
  }

  private function ensurePassportKeysExists(): void
  {
    $privateKeyPath = storage_path('oauth-private.key');
    $publicKeyPath = storage_path('oauth-public.key');

    if (is_readable($privateKeyPath) && is_readable($publicKeyPath)) {
      return;
    }

    $keys = $this->getPassportKeys();
    $privateKey = data_get($keys, 'private.Parameter.Value');
    $publicKey = data_get($keys, 'public.Parameter.Value');

    if (blank($privateKey) || blank($publicKey)) {
      throw new Exception('Passport keys not set on AWS SSM.');
    }

    $this->savePassportKeys($privateKey, $publicKey);
  }

  /**
   * @return array
   */
  private function getPassportKeys(): array
  {
    $ssm = Aws::createClient('ssm');
    $env = $this->app['config']->get('app.env');
    $oauthKeyName = $this->app['config']->get('app.oauth_key_name', $this->app['config']->get('app.name'));
    $parameterPrefix = strtolower($oauthKeyName.'-'.$env);

    return [
      'private' => $ssm->getParameter(['Name' => "$oauthKeyName-$env-private", 'WithDecryption' => true]),
      'public' => $ssm->getParameter(['Name' => "$oauthKeyName-$env-public", 'WithDecryption' => true]),
    ];
  }

  /**
   * @param string $privateKey
   * @param string $publicKey
   */
  private function savePassportKeys(string $privateKey, string $publicKey): void
  {
    $privateKeyPath = storage_path('oauth-private.key');
    $publicKeyPath = storage_path('oauth-public.key');

    file_put_contents($privateKeyPath, $privateKey);
    file_put_contents($publicKeyPath, $publicKey);
    chmod($privateKeyPath, 0660);
    chmod($publicKeyPath, 0660);
  }
}
