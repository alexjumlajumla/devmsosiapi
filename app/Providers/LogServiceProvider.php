<?php

namespace App\Providers;

use App\Services\LogSanitizer;
use Illuminate\Support\ServiceProvider;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

class LogServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        //
    }

    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot()
    {
        // Sanitize log context before it gets written
        Event::listen(MessageLogged::class, function (MessageLogged $event) {
            if (isset($event->context) && !empty($event->context)) {
                // Sanitize the context array to mask sensitive data
                $event->context = LogSanitizer::sanitize($event->context);
            }
            
            // Also sanitize the message itself if it's a string
            if (is_string($event->message)) {
                $event->message = LogSanitizer::sanitize($event->message);
            }
        });
        
        // Create a custom logging channel that enhances the default one
        $this->app->singleton('log.sanitized', function ($app) {
            return new \Illuminate\Log\Logger(
                tap($app['log']->getLogger(), function ($logger) {
                    $logger->pushProcessor(function ($record) {
                        // Sanitize the message
                        $record['message'] = LogSanitizer::sanitize($record['message']);
                        
                        // Sanitize the context
                        if (isset($record['context']) && !empty($record['context'])) {
                            $record['context'] = LogSanitizer::sanitize($record['context']);
                        }
                        
                        return $record;
                    });
                }),
                $app['log']->getEventDispatcher()
            );
        });
        
        // Provide a wrapper for the Log facade that uses our sanitized version
        $this->app->bind('log.safe', function ($app) {
            return $app['log.sanitized'];
        });
    }
} 