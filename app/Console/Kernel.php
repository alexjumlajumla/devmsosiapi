<?php

namespace App\Console;

use App\Models\Settings;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * The Artisan commands provided by your application.
     *
     * @var array
     */
    protected $commands = [
        Commands\CleanupNotifications::class,
        Commands\RetryFailedNotificationsCommand::class,
        Commands\TestNotificationSystem::class,
        Commands\InspectFcmToken::class,
    ];

	/**
	 * Define the application's command schedule.
	 *
	 * @param Schedule $schedule
	 * @return void
	 */
	protected function schedule(Schedule $schedule): void
	{
//		$schedule->command('sudo chmod -R 777 ./storage/')->hourly();
//		$schedule->command('sudo chmod -R 777 ./bootstrap/cache/')->hourly();
		$schedule->command('email:send:by:time')->hourly();
		$schedule->command('remove:expired:bonus:from:cart')->dailyAt('00:01');
		$schedule->command('remove:expired:closed:dates')->dailyAt('00:01');
		$schedule->command('remove:expired:stories')->dailyAt('00:01');
		$schedule->command('order:auto:repeat')->dailyAt('00:01');
		$schedule->command('expired:subscription:remove')->everyMinute();
//         $schedule->command('truncate:telescope')->daily();
		$schedule->command('update:models:galleries')->hourly()->withoutOverlapping()->runInBackground();
		$schedule->command('firebase:clean-tokens')
			->daily()
			->at('03:00')  // Run at 3 AM
			->withoutOverlapping()
			->appendOutputTo(storage_path('logs/firebase-cleanup.log'));
		$schedule->command('voice:cleanup-recordings')
			->daily()
			->at('02:00')  // Run at 2 AM
			->withoutOverlapping()
			->appendOutputTo(storage_path('logs/voice-cleanup.log'));
		// Clean up old notifications daily at 2 AM
        $schedule->command('notifications:cleanup --days=30')
                 ->dailyAt('02:00')
                 ->onOneServer()
                 ->runInBackground()
                 ->emailOutputOnFailure(env('ADMIN_EMAIL'));
                 
        // Retry failed notifications every 5 minutes
        $schedule->command('notifications:retry-failed')
                 ->everyFiveMinutes()
                 ->onOneServer()
                 ->runInBackground()
                 ->emailOutputOnFailure(env('ADMIN_EMAIL'));
	}

	/**
	 * Register the commands for the application.
	 *
	 * @return void
	 */
	protected function commands(): void
	{
		$this->load(__DIR__.'/Commands');

		require base_path('routes/console.php');
	}
}
