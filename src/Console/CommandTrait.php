<?php
namespace Exceedone\Exment\Console;

use Exceedone\Exment\Middleware;

trait CommandTrait
{
    protected function initExmentCommand()
    {
        Middleware\Morph::defineMorphMap();
        Middleware\Initialize::initializeConfig(false);
    }

    /**
     * Publish static files
     *
     * @return void
     */
    public function publishStaticFiles()
    {
        $this->call('vendor:publish', ['--provider' => \Exceedone\Exment\ExmentServiceProvider::class, '--tag' => 'public', '--force' => true]);
        $this->call('vendor:publish', ['--provider' => \Exceedone\Exment\ExmentServiceProvider::class, '--tag' => 'lang', '--force' => true]);
        $this->call('vendor:publish', ['--provider' => \Exceedone\Exment\ExmentServiceProvider::class, '--tag' => 'views_vendor', '--force' => true]);
    }
}
